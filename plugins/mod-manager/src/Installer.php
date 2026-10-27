<?php

namespace Marschux\ModManager;

use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Marschux\ModManager\Models\ModInstall;
use Marschux\ModManager\Models\ModProfile;
use Marschux\ModManager\Sources\Thunderstore;
use RuntimeException;

/**
 * Puts mods on a server and takes them off again, the way the server's mod profile says.
 *
 * Every Thunderstore package is unpacked whole into a folder of its own below the mods folder,
 * like r2modman does it - BepInEx loads plugins from any depth there.
 */
class Installer
{
    /** what the config editor opens: text, never a binary a mod keeps in the config folder */
    private const CONFIG_TYPES = ['cfg', 'json', 'yml', 'yaml', 'txt', 'ini', 'xml', 'toml'];

    /** folders of a package that go next to the mods folder instead of into it */
    private const SORTED = ['patchers', 'monomod'];

    private ServerFiles $files;

    private Thunderstore $source;

    public function __construct(private Server $server, private ModProfile $profile)
    {
        $this->files = new ServerFiles($server);
        $this->source = new Thunderstore((string) $profile->setting('community'), $this->hidden());
    }

    /**
     * What installing $id means: its dependencies that are not there yet, then $id itself.
     * Packages the profile hides (the loader) are left out.
     *
     * @return array<int, array<string, mixed>> from Thunderstore::latest(), plus "dependency"
     */
    public function plan(string $id, ?string $version = null): array
    {
        $installed = $this->installs()->pluck('package')->all();
        $plan = [];

        $collect = function (string $package, bool $root) use (&$collect, &$plan, $installed, $version) {
            if (array_key_exists($package, $plan) || in_array($package, $this->hidden(), true) || (!$root && in_array($package, $installed, true))) {
                return;
            }

            // taken before the dependencies, so a dependency cycle stops here
            $plan[$package] = null;
            // a version picked by hand only for the package itself, dependencies come newest
            $latest = $this->source->release($package, $root ? $version : null);

            foreach ($latest['requires'] as $required) {
                $collect($required, false);
            }

            // dependencies first, so nothing is installed before what it needs
            unset($plan[$package]);
            $plan[$package] = $latest + ['dependency' => !$root, 'pinned' => $root && $version !== null];
        };

        $collect($id, true);

        return array_values($plan);
    }

    /** @param  array<int, array<string, mixed>>  $plan  from plan() */
    public function install(array $plan): void
    {
        foreach ($plan as $package) {
            $this->installPackage($package);
        }
    }

    /**
     * Dependencies nothing needs any more once $install is gone - also the ones only those
     * needed.
     *
     * @return Collection<int, ModInstall>
     */
    public function orphans(ModInstall $install): Collection
    {
        $remaining = $this->installs()->reject(fn (ModInstall $other) => $other->id === $install->id);
        $orphans = new Collection();

        do {
            $required = $remaining->pluck('requires')->flatten()->unique()->all();
            $found = $remaining->filter(fn (ModInstall $other) => $other->dependency && !in_array($other->package, $required, true));

            $orphans = $orphans->merge($found);
            $remaining = $remaining->diff($found);
        } while ($found->isNotEmpty());

        return $orphans->values();
    }

    /** @return string[] the names removed */
    public function uninstall(ModInstall $install, bool $withOrphans): array
    {
        $removing = $withOrphans ? $this->orphans($install)->prepend($install) : new Collection([$install]);

        $this->files->delete($removing->pluck('files')->flatten()->all());
        ModInstall::query()->whereIn('id', $removing->pluck('id'))->delete();

        return $removing->pluck('name')->all();
    }

    /**
     * The mod loader: its pack folder's content goes to the server root, then this server's
     * start command gets the profile's prefix. Configs the loader already has are kept. The
     * newest version without $version.
     */
    public function installLoader(?string $version = null): ModInstall
    {
        $package = $this->source->release((string) $this->profile->setting('loader_package'), $version);
        $scratch = '.mod-manager/loader';

        $this->files->delete($this->files->exists($scratch) ? [$scratch] : []);

        try {
            $this->files->pull($package['download'], $scratch, 'loader.zip');
            $this->files->decompress($scratch, 'loader.zip');

            // a loader pack is one folder (e.g. BepInExPack_Valheim) next to manifest and readme
            $folder = collect($this->files->list($scratch))->filter()->keys()->first()
                ?? throw new RuntimeException(trans('mod-manager::mods.messages.loader_unknown'));

            $configDir = trim((string) $this->profile->setting('config_dir'), '/');
            $moves = [];
            $replace = [];
            $listings = [];

            foreach ($this->files->walk("$scratch/$folder") as $path) {
                $directory = dirname($path) === '.' ? '' : dirname($path);
                $listings[$directory] ??= $this->files->list($directory);

                if (isset($listings[$directory][basename($path)])) {
                    if ($configDir !== '' && str_starts_with($path, "$configDir/")) {
                        continue;
                    }

                    $replace[] = $path;
                }

                $moves[] = ['from' => "$scratch/$folder/$path", 'to' => $path];
            }

            // Wings refuses to move onto an existing file, so an update removes the old one first
            $this->files->delete($replace);
            $this->files->move($moves);

            // what only the version before had - left in place it could break an older version
            $previous = $this->installs()->firstWhere('package', $package['id'])?->files ?? [];
            $this->files->delete(array_values(array_filter(
                array_diff($previous, array_column($moves, 'to')),
                fn (string $path) => $configDir === '' || !str_starts_with($path, "$configDir/"),
            )));
        } finally {
            $this->files->delete([$scratch]);
        }

        $this->applyStartPrefix();

        return ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => $package['id']],
            ['name' => (string) $this->profile->setting('loader_name', $package['name']), 'version' => $package['version'], 'icon' => $package['icon'], 'dependency' => false, 'requires' => [], 'files' => array_column($moves, 'to')],
        );
    }

    /**
     * Takes the loader off again: its files and the start command prefix. Mods and configs stay
     * where they are - they only do nothing until the loader is back.
     */
    public function uninstallLoader(): void
    {
        $loader = $this->installs()->firstWhere('package', (string) $this->profile->setting('loader_package'));

        if ($loader) {
            $this->files->delete(self::loaderPaths($loader->files, $this->keptDirs()));
            $loader->delete();
        }

        $prefix = trim((string) $this->profile->setting('start_prefix'));

        if ($prefix !== '') {
            $this->server->update(['startup' => trim(str_replace($prefix, '', (string) $this->server->startup))]);
        }
    }

    /**
     * What to delete for the loader's files: whole folders where nothing else lives
     * (BepInEx/core, doorstop_libs), single files elsewhere. Folders that hold - or lead to - a
     * kept folder (mods, configs) are never deleted whole, files inside a kept folder not at all.
     *
     * @param  string[]  $files
     * @param  string[]  $kept
     * @return string[]
     */
    public static function loaderPaths(array $files, array $kept): array
    {
        $paths = [];
        // plain php, no collection helpers: the self-check runs this without laravel
        $holds = fn (string $outer, string $inner) => str_starts_with("$inner/", "$outer/");

        foreach ($files as $file) {
            if (array_filter($kept, fn (string $dir) => $holds($dir, $file))) {
                continue;
            }

            // the outermost folder of the file that has no kept folder below it
            $path = $file;
            $parts = explode('/', $file);

            for ($depth = 1; $depth < count($parts); $depth++) {
                $folder = implode('/', array_slice($parts, 0, $depth));

                if (!array_filter($kept, fn (string $dir) => $holds($folder, $dir))) {
                    $path = $folder;

                    break;
                }
            }

            $paths[$path] = true;
        }

        return array_keys($paths);
    }

    /** @return string[] where mods live, switched on and off - removing the loader leaves these */
    private function keptDirs(): array
    {
        return array_merge(...array_map(fn (string $base) => [$base, "{$base}_disabled"], $this->bases()));
    }

    /** @return string[] the folders a package puts files into: mods, patchers, monomod, configs */
    private function bases(): array
    {
        $root = $this->rootDir();

        return array_values(array_filter([
            $this->modsDir(),
            ...array_map(fn (string $folder) => $root === '' ? '' : "$root/$folder", self::SORTED),
            $this->configDir(),
        ]));
    }

    /** The loader's folder, e.g. BepInEx for BepInEx/plugins - empty when the mods folder is top level. */
    private function rootDir(): string
    {
        $root = dirname($this->modsDir());

        return $root === '.' ? '' : $root;
    }

    private function configDir(): string
    {
        return trim((string) $this->profile->setting('config_dir'), '/');
    }

    /** Only this server's start command changes, the egg stays as it is. */
    public function applyStartPrefix(): void
    {
        $prefix = trim((string) $this->profile->setting('start_prefix'));

        if ($prefix !== '' && !str_contains((string) $this->server->startup, $prefix)) {
            $this->server->update(['startup' => "$prefix " . $this->server->startup]);
        }
    }

    /**
     * Installed packages with a newer version out, the loader included - pinned ones too, they
     * only stay out of "update all".
     *
     * @return array<string, string> package => newest version
     */
    public function updates(): array
    {
        $updates = [];

        foreach ($this->installs() as $install) {
            try {
                $latest = $this->source->latestVersion($install->package);
            } catch (\Throwable) {
                continue;
            }

            if (version_compare($latest, $install->version, '>')) {
                $updates[$install->package] = $latest;
            }
        }

        return $updates;
    }

    /** The newest version, plus any dependency it newly needs. Unpins a pinned version. */
    public function update(ModInstall $install): void
    {
        $this->install($this->plan($install->package));
    }

    /**
     * Switched off, a mod's files move next to their folders (plugins_disabled, patchers_disabled,
     * ...), where the loader does not look.
     */
    public function setEnabled(ModInstall $install, bool $enabled): void
    {
        if ($install->enabled === $enabled) {
            return;
        }

        $files = array_map(fn (string $path) => self::switched($path, $enabled, $this->bases()), $install->files);
        $moves = [];

        foreach ($install->files as $index => $from) {
            if ($from !== $files[$index]) {
                $moves[] = ['from' => $from, 'to' => $files[$index]];
            }
        }

        $this->files->move($moves);
        $install->update(['enabled' => $enabled, 'files' => $files]);
    }

    /**
     * What sits in the mods folder without the plugin having put it there - by hand, or with
     * r2modman. A folder named like a package ("Namespace-Name") can be taken over.
     *
     * @return array<int, array{path: string, name: string, version: ?string, package: ?string}>
     */
    public function unmanaged(): array
    {
        $known = $this->installs()->pluck('files')->flatten()->all();
        $found = [];

        foreach ($this->files->list($this->modsDir()) as $name => $isDirectory) {
            $path = $this->modsDir() . "/$name";

            if (in_array($path, $known, true)) {
                continue;
            }

            $manifest = $isDirectory ? $this->manifest($path) : null;
            $package = $isDirectory && preg_match('/^\w+-\w+$/', $name) && is_array($manifest) ? $name : null;

            $found[] = [
                'path' => $path,
                'name' => is_array($manifest) && isset($manifest['name']) ? str_replace('_', ' ', (string) $manifest['name']) : $name,
                'version' => is_array($manifest) ? ($manifest['version_number'] ?? null) : null,
                'package' => $package,
            ];
        }

        return $found;
    }

    /** Takes a hand-installed package over as it is, so it gets update hints and can be removed. */
    public function adopt(string $path): ModInstall
    {
        $entry = collect($this->unmanaged())->firstWhere('path', $path);

        if (!$entry || !$entry['package']) {
            throw new RuntimeException(trans('mod-manager::mods.messages.not_adoptable'));
        }

        // asks the source, so only packages that really exist there are taken over
        $latest = $this->source->latest($entry['package']);
        $manifest = $this->manifest($path) ?? [];

        return ModInstall::query()->create([
            'server_id' => $this->server->id,
            'package' => $entry['package'],
            'name' => $latest['name'],
            'version' => (string) ($entry['version'] ?? '0'),
            'icon' => $latest['icon'],
            'dependency' => false,
            'requires' => array_values(array_map(fn (string $dependency) => preg_replace('/-[^-]+$/', '', $dependency), (array) ($manifest['dependencies'] ?? []))),
            'files' => [$path],
            'size' => $this->files->usage($path),
        ]);
    }

    /** Measures installs from before sizes were kept, once. */
    public function fillSizes(Collection $installs): void
    {
        foreach ($installs->whereNull('size') as $install) {
            $install->update(['size' => array_sum(array_map(fn (string $path) => $this->files->size($path), $install->files))]);
        }
    }

    /** Deletes something hand-installed - only what unmanaged() lists, nothing else. */
    public function removeUnmanaged(string $path): void
    {
        if (!collect($this->unmanaged())->contains('path', $path)) {
            throw new RuntimeException(trans('mod-manager::mods.messages.not_adoptable'));
        }

        $this->files->delete([$path]);
    }

    /**
     * The text files in the config folder - what the mods wrote there on their first start.
     *
     * @return string[] paths from the server root
     */
    public function configs(): array
    {
        $directory = trim((string) $this->profile->setting('config_dir'), '/');

        if ($directory === '') {
            return [];
        }

        return collect($this->files->walk($directory))
            ->filter(fn (string $path) => in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::CONFIG_TYPES, true))
            ->map(fn (string $path) => "$directory/$path")
            ->sort()
            ->values()
            ->all();
    }

    /** The config a mod most likely wrote: named after its id, e.g. advize.PlantEverything.cfg */
    public static function configFor(string $modName, array $configs): ?string
    {
        $needle = preg_replace('/[^a-z0-9]/', '', strtolower($modName));

        if (strlen($needle) < 3) {
            return null;
        }

        foreach ($configs as $path) {
            if (str_contains(preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($path, PATHINFO_FILENAME))), $needle)) {
                return $path;
            }
        }

        return null;
    }

    /** Reads one config - only one configs() lists, so the editor cannot open anything else. */
    public function readConfig(string $path): string
    {
        $this->assertConfig($path);

        return (string) $this->files->read($path);
    }

    public function writeConfig(string $path, string $content): void
    {
        $this->assertConfig($path);

        $this->files->write($path, $content);
    }

    /** What players need on their side: every mod that is switched on, the loader first. */
    public function playerList(): string
    {
        $community = (string) $this->profile->setting('community');
        $loader = (string) $this->profile->setting('loader_package');

        return $this->installs()
            ->filter(fn (ModInstall $install) => $install->enabled)
            ->sortBy(fn (ModInstall $install) => [$install->package !== $loader, strtolower($install->name)])
            ->map(function (ModInstall $install) use ($community) {
                [$namespace, $name] = Thunderstore::split($install->package);

                return "$install->name $install->version - https://thunderstore.io/c/$community/p/$namespace/$name/";
            })
            ->implode("\n");
    }

    private function assertConfig(string $path): void
    {
        if (!in_array($path, $this->configs(), true)) {
            throw new RuntimeException(trans('mod-manager::mods.messages.not_a_config'));
        }
    }

    /** @return Collection<int, ModInstall> */
    public function installs(): Collection
    {
        return ModInstall::query()->where('server_id', $this->server->id)->orderBy('name')->get();
    }

    /**
     * Where a file of an unpacked package belongs, like r2modman sorts it: patchers/ and monomod/
     * into the loader's folder of that name, one folder per package; config/ into the config
     * folder as it is. A "BepInEx/" in front counts the same. Anything else stays in the
     * package's folder below the mods folder, where the loader finds plugins at any depth.
     *
     * @return ?array{to: string, track: string} track: what uninstalling removes
     */
    public static function placement(string $file, string $package, string $root, string $configDir): ?array
    {
        $parts = explode('/', $file);

        if (count($parts) > 2 && strtolower($parts[0]) === 'bepinex') {
            array_shift($parts);
        }

        if (count($parts) < 2) {
            return null;
        }

        $top = strtolower(array_shift($parts));
        $rest = implode('/', $parts);

        return match (true) {
            in_array($top, self::SORTED, true) && $root !== '' => ['to' => "$root/$top/$package/$rest", 'track' => "$root/$top/$package"],
            $top === 'config' && $configDir !== '' => ['to' => "$configDir/$rest", 'track' => "$configDir/$rest"],
            default => null,
        };
    }

    /**
     * The same path with its folder switched on or off: BepInEx/plugins/X <-> BepInEx/plugins_disabled/X,
     * likewise for patchers, monomod and config.
     *
     * @param  string[]  $bases  the folders a package puts files into
     */
    public static function switched(string $path, bool $enabled, array $bases): string
    {
        foreach ($bases as $base) {
            [$from, $to] = $enabled ? ["{$base}_disabled/", "$base/"] : ["$base/", "{$base}_disabled/"];

            if (str_starts_with($path, $from)) {
                return $to . substr($path, strlen($from));
            }
        }

        return $path;
    }

    /** @param  array<string, mixed>  $package */
    private function installPackage(array $package): ModInstall
    {
        $existing = $this->installs()->firstWhere('package', $package['id']);
        // a mod that is switched off stays off through an update
        $enabled = $existing?->enabled ?? true;
        $directory = ($enabled ? $this->modsDir() : $this->disabledDir()) . '/' . $package['id'];
        $configDir = $this->configDir();
        $isConfig = fn (string $path) => $configDir !== '' && (str_starts_with($path, "$configDir/") || str_starts_with($path, "{$configDir}_disabled/"));

        // a reinstall or update starts from empty folders - configs stay, they may have been changed
        $this->files->delete(array_values(array_filter(
            [...($existing?->files ?? []), $directory],
            fn (string $path) => !$isConfig($path) && $this->files->exists($path),
        )));
        $this->files->pull($package['download'], $directory, 'package.zip');
        $this->files->decompress($directory, 'package.zip');
        $this->files->delete(["$directory/package.zip"]);

        $files = [$directory];
        $moves = [];

        foreach ($this->files->walk($directory) as $file) {
            $place = self::placement($file, $package['id'], $this->rootDir(), $configDir);

            if ($place === null) {
                continue;
            }

            $to = self::switched($place['to'], $enabled, $this->bases());

            // a config already on the server is kept, it may have been changed by hand
            if ($isConfig($to) && $this->files->exists($to)) {
                continue;
            }

            $moves[] = ['from' => "$directory/$file", 'to' => $to];
            $files[] = self::switched($place['track'], $enabled, $this->bases());
        }

        $files = array_values(array_unique($files));
        // e.g. a patchers folder left by a hand install - Wings will not move onto it
        $this->files->delete(array_values(array_filter(array_slice($files, 1), fn (string $path) => !$isConfig($path) && $this->files->exists($path))));
        $this->files->move($moves);

        return ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => $package['id']],
            [
                'name' => $package['name'],
                'version' => $package['version'],
                'icon' => $package['icon'],
                // picked by hand once, it stays picked by hand
                'dependency' => $existing ? $existing->dependency && $package['dependency'] : $package['dependency'],
                'requires' => $package['requires'],
                'files' => $files,
                'pinned' => $package['pinned'] ?? false,
                'enabled' => $enabled,
                'size' => array_sum(array_map(fn (string $path) => $this->files->size($path), $files)),
            ],
        );
    }

    /** A package's manifest.json - written by Windows tools, so often with a byte order mark. */
    private function manifest(string $directory): ?array
    {
        $manifest = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', (string) $this->files->read("$directory/manifest.json")), true);

        return is_array($manifest) ? $manifest : null;
    }

    private function modsDir(): string
    {
        return trim((string) $this->profile->setting('mods_dir'), '/');
    }

    /** e.g. BepInEx/plugins_disabled, right next to the mods folder */
    private function disabledDir(): string
    {
        return $this->modsDir() . '_disabled';
    }

    /** @return string[] */
    private function hidden(): array
    {
        return (array) $this->profile->setting('hidden_packages', []);
    }
}
