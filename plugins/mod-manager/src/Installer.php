<?php

namespace Marschux\ModManager;

use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Marschux\ModManager\Models\ModInstall;
use Marschux\ModManager\Models\ModProfile;
use Marschux\ModManager\Sources\GoldSrc;
use Marschux\ModManager\Sources\Hangar;
use Marschux\ModManager\Sources\Modrinth;
use Marschux\ModManager\Sources\Thunderstore;
use RuntimeException;

/**
 * Puts mods on a server and takes them off again, the way the server's mod profile says.
 *
 * Every Thunderstore package is unpacked whole into a folder of its own below the mods folder,
 * like r2modman does it - BepInEx loads plugins from any depth there. A Modrinth or Hangar
 * plugin is a single jar, pulled straight into the mods folder, a datapack a single zip in the
 * world's datapacks folder. A resource pack is not put on the server at all: server.properties
 * points the players to it.
 */
class Installer
{
    /**
     * what liblist.gam loads once Metamod is in, and what Metamod loads. Not metamod_i386.so as
     * it comes: current HLDS fails to load a game library with "_i386" in its name ("Couldn't
     * get DLL API" with a garbled path), so it is put there under this name.
     */
    private const METAMOD_DLL = 'addons/metamod/metamod.so';

    private const AMXX_DLL = 'addons/amxmodx/dlls/amxmodx_mm_i386.so';

    /** AMX Mod X's own configs: an uploaded zip that brings one along never gets it, nor deletes it */
    private const AMXX_CONFIGS = ['amxx.cfg', 'sql.cfg', 'custommenuitems.cfg', 'plugins.ini', 'modules.ini', 'users.ini', 'core.ini', 'configs.ini', 'cmdaccess.ini', 'clcmds.ini', 'cmds.ini', 'cvars.ini', 'maps.ini', 'speech.ini', 'stats.ini', 'miscstats.ini'];

    /** the package of a plugin uploaded through the page: "upload:<file name without .amxx>" */
    public const UPLOAD = 'upload:';

    /** where Minecraft keeps its settings: the world's name and the resource pack */
    private const PROPERTIES = 'server.properties';

    /** what the config editor opens: text, never a binary a mod keeps in the config folder */
    private const CONFIG_TYPES = ['cfg', 'json', 'yml', 'yaml', 'txt', 'ini', 'xml', 'toml'];

    /** folders of a package that go next to the mods folder instead of into it */
    private const SORTED = ['patchers', 'monomod'];

    private ServerFiles $files;

    private Thunderstore $source;

    /** @var array{0: ?string}|null the server's game version once read, see gameVersion() */
    private ?array $gameVersion = null;

    public function __construct(private Server $server, private ModProfile $profile)
    {
        $this->files = new ServerFiles($server);
        $this->source = new Thunderstore((string) $profile->setting('community'), $this->hidden());
    }

    /**
     * What installing $id means: its dependencies that are not there yet, then $id itself.
     * Packages the profile hides (the loader) are left out. Of $id's optional dependencies,
     * those in $optional come along. Without $version every package comes in the newest version
     * for the server's game version.
     *
     * @param  string[]  $optional
     * @return array<int, array<string, mixed>> from the source's release(), plus "dependency"
     */
    public function plan(string $id, ?string $version = null, array $optional = []): array
    {
        $installed = $this->installs()->pluck('package')->all();
        $plan = [];

        $collect = function (string $package, bool $root) use (&$collect, &$plan, $installed, $version, $optional) {
            if (array_key_exists($package, $plan) || in_array($package, $this->hidden(), true) || (!$root && in_array($package, $installed, true))) {
                return;
            }

            // taken before the dependencies, so a dependency cycle stops here
            $plan[$package] = null;
            // a version picked by hand only for the package itself, dependencies come newest
            $latest = $this->sourceFor($package)->release($package, $root ? $version : null, $this->gameVersion());

            // the same plugin from the other source: Paper would load neither of the two jars
            if ($twin = $this->twin($package, $latest['name'])) {
                if ($root) {
                    throw new RuntimeException(trans('mod-manager::mods.messages.twin', ['name' => $twin->name, 'source' => str_starts_with($twin->package, Hangar::PREFIX) ? 'Hangar' : 'Modrinth']));
                }

                // as a dependency it is there already
                unset($plan[$package]);

                return;
            }
            $picked = $root ? array_values(array_intersect(array_keys($latest['optional'] ?? []), $optional)) : [];

            foreach ([...$latest['requires'], ...$picked] as $required) {
                $collect($required, false);
            }

            // dependencies first, so nothing is installed before what it needs
            unset($plan[$package]);
            $plan[$package] = $latest + ['dependency' => !$root, 'pinned' => $root && $version !== null];
        };

        $collect($id, true);

        return array_values($plan);
    }

    /** An installed plugin of another source with the same name - Modrinth's and Hangar's ViaVersion. */
    private function twin(string $package, string $name): ?ModInstall
    {
        if (!self::jarPackage($package) || self::kind($package) !== 'plugin') {
            return null;
        }

        return $this->installs()->first(fn (ModInstall $other) => $other->package !== $package
            && self::jarPackage($other->package)
            && self::kind($other->package) === 'plugin'
            && self::needle($other->name) === self::needle($name));
    }

    /** @param  array<int, array<string, mixed>>  $plan  from plan() */
    public function install(array $plan): void
    {
        foreach ($plan as $package) {
            $this->installPackage($package);
        }
    }

    /** @return string[] the names removed */
    public function uninstall(ModInstall $install, bool $withConfigs = false): array
    {
        // only this mod - its dependencies stay, another mod may come along that needs them
        $removing = new Collection([$install]);
        // worked out before anything is deleted, while the configs are still listed
        $configs = $withConfigs ? $this->runtimeConfigs($removing) : [];

        // configs an uploaded archive brought along are only deleted when asked, like those a mod wrote
        $files = array_filter($removing->pluck('files')->flatten()->all(), fn (string $path) => !self::uploaded($install->package) || !$this->inConfigDir($path));

        $this->files->delete([...$files, ...$configs]);

        if (self::kind($install->package) === 'resourcepack' && $install->enabled) {
            $this->useResourcePack(null);
        }

        if (self::uploaded($install->package)) {
            $this->writeUploadedIni(fn (string $ini) => array_reduce($this->amxxFilesOf($install), fn (string $ini, string $file) => self::withoutAmxxPlugin($ini, $file), $ini));
        }

        ModInstall::query()->whereIn('id', $removing->pluck('id'))->delete();

        return $removing->pluck('name')->all();
    }

    /**
     * The configs these mods wrote on their own when they ran - what uninstalling leaves behind
     * unless asked. Never a file another installed mod brought along.
     *
     * @param  Collection<int, ModInstall>  $installs
     * @return string[]
     */
    public function runtimeConfigs(Collection $installs): array
    {
        // what an uploaded archive brought into the config folder
        if ($this->goldsrc()) {
            return array_values(array_filter(
                $installs->filter(fn (ModInstall $install) => self::uploaded($install->package))->pluck('files')->flatten()->all(),
                fn (string $path) => $this->inConfigDir($path),
            ));
        }

        if ($this->jarMods()) {
            // datapacks and resource packs keep no data of their own
            return $this->dataFolders($installs->filter(fn (ModInstall $install) => self::kind($install->package) === 'plugin'));
        }

        $directory = $this->configDir();
        $others = $this->installs()->reject(fn (ModInstall $other) => $installs->contains('id', $other->id))->pluck('files')->flatten()->all();
        $configs = $this->configs();
        $found = [];

        foreach ($installs as $install) {
            $found = [...$found, ...self::configsOf($install->name, $configs, $directory, [...$others, ...$install->files])];
        }

        return array_values(array_unique($found));
    }

    /**
     * Configs that belong to a mod by name: the file is named after it (advize.PlantEverything.cfg)
     * or sits in a folder named after it (EpicLoot/...). Paths in $skip stay.
     *
     * @param  string[]  $configs  from configs()
     * @param  string[]  $skip
     * @return string[]
     */
    public static function configsOf(string $modName, array $configs, string $directory, array $skip = []): array
    {
        $needle = self::needle($modName);

        if (strlen($needle) < 3 || $directory === '') {
            return [];
        }

        return array_values(array_filter($configs, function (string $path) use ($needle, $directory, $skip) {
            if (in_array($path, $skip, true) || !str_starts_with($path, "$directory/")) {
                return false;
            }

            $parts = explode('/', substr($path, strlen($directory) + 1));

            return (count($parts) > 1 && self::needle($parts[0]) === $needle)
                || str_contains(self::needle(pathinfo($path, PATHINFO_FILENAME)), $needle);
        }));
    }

    /**
     * The folders these plugins keep their data in: plugins/<plugin name> next to the jars.
     *
     * @param  Collection<int, ModInstall>  $installs
     * @return string[]
     */
    private function dataFolders(Collection $installs): array
    {
        $folders = array_keys(array_filter($this->files->list($this->modsDir())));
        $others = $this->installs()->reject(fn (ModInstall $other) => $installs->contains('id', $other->id))->pluck('name')->all();
        $found = [];

        foreach ($installs as $install) {
            foreach (self::dataFoldersOf($install->name, $folders, $others) as $folder) {
                $found[] = $this->modsDir() . "/$folder";
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * A plugin's data folder is named after the plugin as its jar names it, which is close to
     * but not always the project's name: "EssentialsX" writes to "Essentials". So a folder
     * named exactly like it, else one whose name the plugin's name starts with - unless that
     * folder fits one of the $others too, then it is not certain whose it is.
     *
     * @param  string[]  $folders
     * @param  string[]  $others  names of the other installed plugins
     * @return string[]
     */
    public static function dataFoldersOf(string $name, array $folders, array $others = []): array
    {
        $needle = self::needle($name);

        if (strlen($needle) < 3) {
            return [];
        }

        $exact = array_values(array_filter($folders, fn (string $folder) => self::needle($folder) === $needle));

        if ($exact !== []) {
            return $exact;
        }

        $fits = fn (string $folder, string $plugin) => strlen(self::needle($folder)) >= 4 && str_starts_with(self::needle($plugin), self::needle($folder));

        return array_values(array_filter($folders, fn (string $folder) => $fits($folder, $name)
            && !array_filter($others, fn (string $other) => $fits($folder, $other))));
    }

    /** A name as it shows up in file names: lower case, letters and digits only. */
    private static function needle(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($name));
    }

    /**
     * The mod loader: its pack folder's content goes to the server root, then this server's
     * start command gets the profile's prefix. Configs the loader already has are kept. The
     * newest version without $version.
     */
    public function installLoader(?string $version = null): ModInstall
    {
        if ($this->goldsrc()) {
            return $this->installGoldSrcLoader();
        }

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
            // AMX Mod X keeps what plugins saved (vaults, languages) in its data folder
            $kept = $this->goldsrc() ? [...$this->keptDirs(), $this->gameDir() . '/addons/amxmodx/data'] : $this->keptDirs();

            $this->files->delete(self::loaderPaths($loader->files, $kept));
            $loader->delete();
        }

        if ($this->goldsrc()) {
            $this->hookGoldSrc(false);

            return;
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
            $this->jarMods() ? $this->datapacksDir() : '',
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

    /**
     * Metamod-R and AMX Mod X: every archive unpacked on the server, its addons/ folder sorted
     * into the game folder - the game's AMX Mod X addon after the base, so its files win. Configs
     * already on the server stay, they may have been changed. Then liblist.gam loads Metamod
     * instead of the game, and Metamod loads AMX Mod X.
     */
    private function installGoldSrcLoader(): ModInstall
    {
        $release = (new GoldSrc())->release();
        $game = $this->gameDir();
        $scratch = '.mod-manager/loader';
        $moves = [];
        $replace = [];
        $listings = [];

        $this->files->delete($this->files->exists($scratch) ? [$scratch] : []);

        try {
            foreach ($release['parts'] as $part) {
                $directory = "$scratch/{$part['name']}";
                $file = basename((string) parse_url($part['url'], PHP_URL_PATH));

                $this->files->pull($part['url'], $directory, $file);
                $this->files->decompress($directory, $file);

                foreach ($this->files->walk("$directory/addons") as $path) {
                    $path = "addons/$path";

                    if (!self::goldsrcFile($path)) {
                        continue;
                    }

                    $to = "$game/" . self::goldsrcTarget($path);
                    $listings[dirname($to)] ??= $this->files->list(dirname($to));

                    if (isset($listings[dirname($to)][basename($to)])) {
                        if (self::keptOnUpdate($path)) {
                            continue;
                        }

                        $replace[$to] = $to;
                    }

                    $moves[$to] = ['from' => "$directory/$path", 'to' => $to];
                }
            }

            // Wings refuses to move onto an existing file
            $this->files->delete(array_values($replace));
            $this->files->move(array_values($moves));
        } finally {
            $this->files->delete([$scratch]);
        }

        $this->hookGoldSrc(true);

        $previous = $this->installs()->firstWhere('package', GoldSrc::PACKAGE)?->files ?? [];

        return ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => GoldSrc::PACKAGE],
            [
                'name' => (string) $this->profile->setting('loader_name', $release['name']),
                'version' => $release['version'],
                'icon' => null,
                'dependency' => false,
                'requires' => [],
                // what the version before put there stays tracked, so removing the loader takes it along
                'files' => array_values(array_unique([...array_keys($moves), ...$previous])),
            ],
        );
    }

    /**
     * Hooks Metamod in (liblist.gam loads it instead of the game, Metamod's plugins.ini loads
     * AMX Mod X) or out again.
     */
    private function hookGoldSrc(bool $on): void
    {
        $game = $this->gameDir();
        $liblist = "$game/liblist.gam";

        $this->files->write($liblist, self::withGameDll((string) $this->files->read($liblist), $on ? self::METAMOD_DLL : (string) $this->profile->setting('game_dll', 'dlls/cs.so')));

        if ($on) {
            $plugins = "$game/addons/metamod/plugins.ini";

            $this->files->write($plugins, self::withMetamodPlugin((string) $this->files->read($plugins), self::AMXX_DLL));
        }
    }

    /** Metamod is in: liblist.gam loads it. Off when e.g. a "validate" of steamcmd restored the file. */
    public function loaderHooked(): bool
    {
        return self::gameDll((string) $this->files->read($this->gameDir() . '/liblist.gam')) === self::METAMOD_DLL;
    }

    /** What liblist.gam loads on Linux, null without the line. */
    public static function gameDll(string $liblist): ?string
    {
        return preg_match('/^[ \t]*gamedll_linux[ \t]+"([^"]*)"/m', $liblist, $match) ? $match[1] : null;
    }

    /** liblist.gam loading $dll on Linux; everything else stays. */
    public static function withGameDll(string $liblist, string $dll): string
    {
        $line = 'gamedll_linux "' . str_replace('"', '', $dll) . '"';
        $replaced = preg_replace_callback('/^[ \t]*gamedll_linux[ \t]+"[^"]*"/m', fn () => $line, $liblist, 1, $count);

        return $count ? $replaced : rtrim($liblist, "\r\n") . "\n$line\n";
    }

    /** Metamod's plugins.ini with a line loading $dll on Linux, unless one does already. */
    public static function withMetamodPlugin(string $ini, string $dll): string
    {
        foreach (preg_split('/\r?\n/', $ini) as $line) {
            if (preg_match('/^\s*linux\s+(\S+)/', $line, $match) && $match[1] === $dll) {
                return $ini;
            }
        }

        return ($ini === '' ? '' : rtrim($ini, "\r\n") . "\n") . "linux $dll\n";
    }

    /**
     * What of the loader archives goes on the server: the Linux builds, not AMX Mod X's
     * scripting folder - its compiler cannot run from the panel, and it is most of the files.
     */
    public static function goldsrcFile(string $path): bool
    {
        return str_starts_with($path, 'addons/')
            && !str_starts_with($path, 'addons/amxmodx/scripting/')
            && strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'dll';
    }

    /** Where a file of the loader archives goes below the game folder - Metamod renamed, see METAMOD_DLL. */
    public static function goldsrcTarget(string $path): string
    {
        return $path === 'addons/metamod/metamod_i386.so' ? self::METAMOD_DLL : $path;
    }

    /** A config of the loader: one on the server already is kept through an update. */
    public static function keptOnUpdate(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['ini', 'cfg'], true);
    }

    /**
     * The plugins AMX Mod X lists in its plugins.ini - those it comes with, and any put there by
     * hand - with whether the file is there at all.
     *
     * @return array<int, array{file: string, enabled: bool, note: string, group: string, exists: bool}>
     */
    public function amxxPlugins(): array
    {
        if (!$this->goldsrc()) {
            return [];
        }

        $present = $this->files->list($this->modsDir());

        return array_map(
            fn (array $plugin) => $plugin + ['exists' => isset($present[$plugin['file']])],
            self::parseAmxxPlugins((string) $this->files->read($this->configDir() . '/plugins.ini')),
        );
    }

    /** Switches a plugin of plugins.ini on or off: its line commented out or back in. */
    public function setAmxxPlugin(string $file, bool $enabled): void
    {
        $path = $this->configDir() . '/plugins.ini';
        $ini = (string) $this->files->read($path);

        if (!in_array($file, array_column(self::parseAmxxPlugins($ini), 'file'), true)) {
            throw new RuntimeException(trans('mod-manager::mods.messages.not_adoptable'));
        }

        $this->files->write($path, self::withAmxxPlugin($ini, $file, $enabled));
    }

    /**
     * The plugin lines of an AMX Mod X plugins.ini. A line "; Basic" heads the plugins below it,
     * a plugin commented out (";telemenu.amxx") is one switched off.
     *
     * @return array<int, array{file: string, enabled: bool, note: string, group: string}>
     */
    public static function parseAmxxPlugins(string $ini): array
    {
        $plugins = [];
        $group = '';

        foreach (preg_split('/\r?\n/', $ini) as $line) {
            if (preg_match('/^\s*(;\s*)?([\w.-]+\.amxx)(?=[\s;]|$)[^;]*(?:;\s*(.*))?$/i', $line, $match)) {
                $plugins[] = ['file' => $match[2], 'enabled' => $match[1] === '', 'note' => trim($match[3] ?? ''), 'group' => $group];
            } elseif (preg_match('/^\s*;\s*(.+?)\s*$/', $line, $match)) {
                $group = $match[1];
            }
        }

        return $plugins;
    }

    /** plugins.ini with $file's line commented in or out - only that line changes. */
    public static function withAmxxPlugin(string $ini, string $file, bool $enabled): string
    {
        return (string) preg_replace_callback(
            '/^[ \t]*(?:;[ \t]*)?(' . preg_quote($file, '/') . '(?=[ \t;\r]|$).*)$/m',
            fn (array $match) => ($enabled ? '' : ';') . $match[1],
            $ini,
            1,
        );
    }

    /**
     * A compiled AMX Mod X plugin uploaded through the page: into the plugins folder, its line
     * into plugins-modmanager.ini - AMX Mod X reads every plugins-*.ini, so the plugins.ini the
     * admin keeps stays untouched. The same name again replaces it.
     */
    public function installAmxx(string $name, string $content): ModInstall
    {
        $name = self::amxxName($name);
        $package = self::UPLOAD . substr($name, 0, -5);
        $existing = $this->installs()->firstWhere('package', $package);
        $path = $this->modsDir() . "/$name";

        // e.g. one AMX Mod X comes with - replacing it would be gone on the loader's next update
        if (!$existing && $this->files->exists($path)) {
            throw new RuntimeException(trans('mod-manager::mods.messages.exists', ['file' => $name]));
        }

        $enabled = $existing?->enabled ?? true;

        $this->files->write($path, $content);
        $this->writeUploadedIni(fn (string $ini) => self::withAmxxPlugin(self::withAmxxLine($ini, $name), $name, $enabled));

        return ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => $package],
            [
                'name' => substr($name, 0, -5),
                // a compiled plugin carries no version that can be read here: when it came is what there is
                'version' => now()->format('Y-m-d H:i'),
                'icon' => null,
                'dependency' => false,
                'requires' => [],
                'files' => [$path],
                'enabled' => $enabled,
                'size' => strlen($content),
            ],
        );
    }

    /** An upload: a compiled plugin, or a zip with plugins and what they bring along. */
    public function installUpload(string $name, string $content): ModInstall
    {
        return str_ends_with(strtolower($name), '.zip') ? $this->installAmxxArchive($name, $content) : $this->installAmxx($name, $content);
    }

    /**
     * A zip of AMX Mod X plugins: unpacked on the server, sorted into the game folder (see
     * amxxPlacement), every plugin in it gets its line. Configs and other files already there are
     * kept; a plugin file that is there and not from this upload stops it.
     */
    public function installAmxxArchive(string $name, string $content): ModInstall
    {
        if (!preg_match('/^\w[\w.-]*\.zip\z/i', $name)) {
            throw new RuntimeException(trans('mod-manager::mods.messages.bad_amxx', ['file' => $name]));
        }

        $package = self::UPLOAD . substr($name, 0, -4);
        $existing = $this->installs()->firstWhere('package', $package);
        $game = $this->gameDir();
        $scratch = '.mod-manager/upload';

        $this->files->delete($this->files->exists($scratch) ? [$scratch] : []);

        try {
            $this->files->write("$scratch/$name", $content);
            $this->files->decompress($scratch, $name);
            $this->files->delete(["$scratch/$name"]);

            $placed = [];

            foreach ($this->files->walk($scratch) as $path) {
                $to = self::amxxPlacement($path);

                if ($to !== null) {
                    $placed["$game/$to"] = "$scratch/$path";
                }
            }

            $plugins = array_values(array_filter(array_keys($placed), fn (string $path) => str_ends_with($path, '.amxx')));

            if ($plugins === []) {
                $sources = array_filter($this->files->walk($scratch), fn (string $path) => str_ends_with(strtolower($path), '.sma'));

                throw new RuntimeException(trans($sources ? 'mod-manager::mods.messages.source_only' : 'mod-manager::mods.messages.no_plugin', ['file' => $name]));
            }

            $ours = $existing?->files ?? [];
            $moves = [];
            $replace = [];
            $kept = [];
            $listings = [];

            foreach ($placed as $to => $from) {
                $listings[dirname($to)] ??= $this->files->list(dirname($to));

                if (isset($listings[dirname($to)][basename($to)])) {
                    if (str_ends_with($to, '.amxx') && !in_array($to, $ours, true)) {
                        throw new RuntimeException(trans('mod-manager::mods.messages.exists', ['file' => basename($to)]));
                    }

                    // a config may have been changed - it stays, and stays this upload's unless AMX Mod X's own;
                    // anything else of someone else's stays theirs
                    if ($this->inConfigDir($to)) {
                        if (!in_array(strtolower(basename($to)), self::AMXX_CONFIGS, true)) {
                            $kept[] = $to;
                        }

                        continue;
                    }

                    if (!in_array($to, $ours, true)) {
                        continue;
                    }

                    $replace[] = $to;
                }

                $moves[] = ['from' => $from, 'to' => $to];
            }

            $this->files->delete($replace);
            $this->files->move($moves);
        } finally {
            $this->files->delete([$scratch]);
        }

        $enabled = $existing?->enabled ?? true;
        $names = array_map('basename', $plugins);

        $this->writeUploadedIni(fn (string $ini) => array_reduce($names, fn (string $ini, string $file) => self::withAmxxPlugin(self::withAmxxLine($ini, $file), $file, $enabled), $ini));

        return ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => $package],
            [
                'name' => substr($name, 0, -4),
                'version' => now()->format('Y-m-d H:i'),
                'icon' => null,
                'dependency' => false,
                'requires' => [],
                // kept configs stay tracked, so removing with the box ticked takes them along
                'files' => array_values(array_unique([...array_column($moves, 'to'), ...$kept, ...array_intersect($ours, array_keys($placed))])),
                'enabled' => $enabled,
                'size' => strlen($content),
            ],
        );
    }

    /**
     * Where a file of an uploaded zip goes, relative to the game folder - null to leave it out.
     * Whatever folders the zip wraps things in (MyPlugin/, cstrike/), an addons/amxmodx/ or a game
     * folder (sound/, models/, ...) inside counts from there; loose plugins and configs go to AMX Mod X's
     * folders. Source code, the scripting folder and readmes stay out.
     */
    public static function amxxPlacement(string $path): ?string
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        $lower = array_map('strtolower', $parts);
        $file = end($parts);
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (($index = array_search('addons', $lower, true)) !== false) {
            $rest = array_slice($lower, $index);

            return ($rest[1] ?? '') === 'amxmodx' && count($rest) > 3 && in_array($rest[2], ['plugins', 'configs', 'data', 'modules'], true) && !in_array($extension, ['sma', 'inc'], true)
                ? implode('/', array_slice($parts, $index))
                : null;
        }

        foreach (['sound', 'models', 'sprites', 'gfx', 'maps', 'overviews', 'resource'] as $folder) {
            if (($index = array_search($folder, $lower, true)) !== false && $index < count($parts) - 1) {
                return implode('/', array_slice($parts, $index));
            }
        }

        return match (true) {
            $extension === 'amxx' => "addons/amxmodx/plugins/$file",
            in_array($extension, ['cfg', 'ini'], true) => "addons/amxmodx/configs/$file",
            $extension === 'txt' && ($lower[count($lower) - 2] ?? '') === 'lang' => "addons/amxmodx/data/lang/$file",
            default => null,
        };
    }

    /** @return string[] the plugin files of an upload, by name */
    private function amxxFilesOf(ModInstall $install): array
    {
        return array_values(array_map('basename', array_filter($install->files, fn (string $path) => str_ends_with($path, '.amxx'))));
    }

    private function inConfigDir(string $path): bool
    {
        return $this->configDir() !== '' && str_starts_with($path, $this->configDir() . '/');
    }

    /** @param  callable(string): string  $change */
    private function writeUploadedIni(callable $change): void
    {
        $path = $this->configDir() . '/plugins-modmanager.ini';
        $ini = (string) $this->files->read($path);

        $this->files->write($path, $change($ini === '' ? "; Plugins uploaded through the Mods page of the panel\n" : $ini));
    }

    /** An uploaded plugin's name - it ends up in a path and a line of an ini file, so a plain one. */
    public static function amxxName(string $file): string
    {
        if (!preg_match('/^\w[\w.-]*\.amxx\z/i', $file)) {
            throw new RuntimeException(trans('mod-manager::mods.messages.bad_amxx', ['file' => $file]));
        }

        return $file;
    }

    /** plugins.ini with a line for $file, unless it has one - switched on or off. */
    public static function withAmxxLine(string $ini, string $file): string
    {
        if (in_array($file, array_column(self::parseAmxxPlugins($ini), 'file'), true)) {
            return $ini;
        }

        return ($ini === '' ? '' : rtrim($ini, "\r\n") . "\n") . "$file\n";
    }

    /** plugins.ini without $file's line. */
    public static function withoutAmxxPlugin(string $ini, string $file): string
    {
        return (string) preg_replace('/^[ \t]*(?:;[ \t]*)?' . preg_quote($file, '/') . '(?=[ \t;\r]|$).*(\r?\n|$)/m', '', $ini, 1);
    }

    /** A plugin uploaded through the page, see installAmxx(). */
    public static function uploaded(string $package): bool
    {
        return str_starts_with($package, self::UPLOAD);
    }

    private function goldsrc(): bool
    {
        return $this->profile->setting('loader_type') === 'goldsrc';
    }

    /** e.g. cstrike */
    private function gameDir(): string
    {
        return trim((string) $this->profile->setting('game_dir'), '/');
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
                $latest = $this->sourceFor($install->package)->latestVersion($install->package, $this->gameVersion());
            } catch (\Throwable) {
                continue;
            }

            // thunderstore numbers are plain semver, plugin versions are anything ("v5.5.71-bukkit"):
            // there the newest for the game version simply differs from what is installed
            if (self::jarPackage($install->package) || $install->package === GoldSrc::PACKAGE ? $latest !== $install->version : version_compare($latest, $install->version, '>')) {
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

        // nothing to move: on means server.properties points to it, and only one can be on
        if (self::kind($install->package) === 'resourcepack') {
            $this->useResourcePack($enabled ? $this->sourceFor($install->package)->release($install->package, $install->version) : null, $install->package);
            $install->update(['enabled' => $enabled]);

            return;
        }

        // the jar stays where it is, AMX Mod X skips a line commented out
        if (self::uploaded($install->package)) {
            $this->writeUploadedIni(fn (string $ini) => array_reduce($this->amxxFilesOf($install), fn (string $ini, string $file) => self::withAmxxPlugin($ini, $file, $enabled), $ini));
            $install->update(['enabled' => $enabled]);

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

        if ($this->jarMods()) {
            return $this->unmanagedJars($known);
        }

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

    /**
     * The jars in the plugins folder the plugin did not put there, recognised by their hash on
     * Modrinth or Hangar. Data folders and other files next to them are left out.
     *
     * @param  string[]  $known  paths of what is installed through the plugin
     * @return array<int, array{path: string, name: string, version: ?string, package: ?string, icon: ?string, requires: string[]}>
     */
    private function unmanagedJars(array $known): array
    {
        $found = [];

        foreach ($this->files->entries($this->modsDir()) as $name => $entry) {
            $path = $this->modsDir() . "/$name";

            if ($entry['directory'] || !str_ends_with(strtolower($name), '.jar') || in_array($path, $known, true)) {
                continue;
            }

            $match = $this->identify($path, $entry);

            $found[] = [
                'path' => $path,
                'name' => $match['name'] ?? $name,
                'version' => $match['version'] ?? null,
                'package' => $match['id'] ?? null,
                'icon' => $match['icon'] ?? null,
                'requires' => $match['requires'] ?? [],
            ];
        }

        return $found;
    }

    /**
     * What a jar is, by its hash: asked once per file content and kept, the tab lists these on
     * every visit.
     *
     * @param  array{size: int, modified: string}  $entry
     * @return array<string, mixed>|null
     */
    private function identify(string $path, array $entry): ?array
    {
        $key = 'mod-manager:jar:' . $this->server->uuid . ':' . md5("$path:{$entry['size']}:{$entry['modified']}");

        // not found is kept too ([]), else every visit would read the jar and ask again
        $match = Cache::remember($key, now()->addDay(), function () use ($path) {
            // ponytail: the jar passes through the panel once to be hashed; Wings has no checksum call
            $content = $this->files->read($path, 64 * 1048576);

            if ($content === null) {
                return [];
            }

            $sources = $this->profile->sources();
            $sha1 = sha1($content);

            if (in_array('modrinth', $sources, true)) {
                $found = (new Modrinth((array) $this->profile->setting('modrinth_loaders', [])))->byHashes([$sha1])[$sha1] ?? null;

                if ($found) {
                    return $found;
                }
            }

            return in_array('hangar', $sources, true) ? ((new Hangar())->byHash(hash('sha256', $content)) ?? []) : [];
        });

        return $match ?: null;
    }

    /** Takes a hand-installed package over as it is, so it gets update hints and can be removed. */
    public function adopt(string $path): ModInstall
    {
        $entry = collect($this->unmanaged())->firstWhere('path', $path);

        if (!$entry || !$entry['package']) {
            throw new RuntimeException(trans('mod-manager::mods.messages.not_adoptable'));
        }

        if ($this->jarMods()) {
            // the same plugin twice: Paper refuses to load either, so one has to go
            if ($this->installs()->contains('package', $entry['package'])) {
                throw new RuntimeException(trans('mod-manager::mods.messages.duplicate', ['name' => $entry['name']]));
            }

            return ModInstall::query()->create([
                'server_id' => $this->server->id,
                'package' => $entry['package'],
                'name' => $entry['name'],
                'version' => (string) ($entry['version'] ?? '0'),
                'icon' => $entry['icon'],
                'dependency' => false,
                'requires' => $entry['requires'],
                'files' => [$path],
                'size' => $this->files->size($path),
            ]);
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
        if ($this->jarMods()) {
            return $this->pluginConfigs();
        }

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

    /**
     * Plugins write their configs to plugins/<plugin name>/. Only that first level: deeper down
     * are languages, player data and the like.
     *
     * @return string[]
     */
    private function pluginConfigs(): array
    {
        $directory = $this->modsDir();

        return collect($this->files->list($directory))
            ->filter(fn (bool $isDirectory, string $name) => $isDirectory && !str_starts_with($name, '.'))
            ->keys()
            ->flatMap(fn (string $folder) => collect($this->files->list("$directory/$folder"))
                ->reject()
                ->keys()
                ->map(fn (string $file) => "$directory/$folder/$file"))
            ->filter(fn (string $path) => in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::CONFIG_TYPES, true))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The config a mod most likely wrote: in a folder named after it (plugins/LuckPerms/config.yml,
     * config.yml first), else named after its id (advize.PlantEverything.cfg).
     */
    public static function configFor(string $modName, array $configs): ?string
    {
        $needle = self::needle($modName);

        if (strlen($needle) < 3) {
            return null;
        }

        $inFolder = array_filter($configs, fn (string $path) => self::dataFoldersOf($modName, [basename(dirname($path))]) !== []);

        if ($inFolder !== []) {
            usort($inFolder, fn (string $a, string $b) => (basename($a) !== 'config.yml') <=> (basename($b) !== 'config.yml'));

            return $inFolder[0];
        }

        foreach ($configs as $path) {
            if (str_contains(self::needle(pathinfo($path, PATHINFO_FILENAME)), $needle)) {
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

    /** Back to defaults: the mod writes a fresh one on its next start. */
    public function deleteConfig(string $path): void
    {
        $this->assertConfig($path);

        $this->files->delete([$path]);
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
                if (Modrinth::kindOf($install->package)) {
                    return "$install->name $install->version - https://modrinth.com/project/" . Modrinth::split($install->package);
                }

                if (str_starts_with($install->package, Hangar::PREFIX)) {
                    try {
                        return "$install->name $install->version - " . (new Hangar())->url($install->package);
                    } catch (\Throwable) {
                        return "$install->name $install->version";
                    }
                }

                // e.g. the GoldSrc loader: nothing to link to
                if (!preg_match('/^\w+-\w+$/', $install->package)) {
                    return "$install->name $install->version";
                }

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
        if (isset($package['file'])) {
            return $this->installJar($package);
        }

        $existing = $this->installs()->firstWhere('package', $package['id']);
        // a mod that is switched off stays off through an update
        $enabled = $existing?->enabled ?? true;
        $directory = ($enabled ? $this->modsDir() : $this->disabledDir()) . '/' . $package['id'];
        $configDir = $this->configDir();
        $isConfig = fn (string $path) => $configDir !== '' && (str_starts_with($path, "$configDir/") || str_starts_with($path, "{$configDir}_disabled/"));

        // a reinstall or update starts from empty folders - configs stay, they may have been changed
        $this->files->delete(array_values(array_filter(
            array_unique([...($existing?->files ?? []), $directory]),
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

    /**
     * A plugin: one jar, pulled straight into the mods folder - or next to it while switched off.
     * A datapack likewise into the world's datapacks folder.
     */
    private function installJar(array $package): ModInstall
    {
        if (self::kind($package['id']) === 'resourcepack') {
            return $this->installResourcePack($package);
        }

        $existing = $this->installs()->firstWhere('package', $package['id']);
        $enabled = $existing?->enabled ?? true;
        $directory = self::kind($package['id']) === 'datapack' ? $this->datapacksDir() : $this->modsDir();
        $path = ($enabled ? $directory : "{$directory}_disabled") . '/' . self::jarName((string) $package['file']);

        // an update replaces the old jar, which may be named differently; Wings will not pull onto a file
        $this->files->delete(array_values(array_filter(array_unique([...($existing?->files ?? []), $path]), fn (string $old) => $this->files->exists($old))));
        $this->files->pull($package['download'], dirname($path), basename($path));

        return ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => $package['id']],
            [
                'name' => $package['name'],
                'version' => $package['version'],
                'icon' => $package['icon'],
                'dependency' => $existing ? $existing->dependency && $package['dependency'] : $package['dependency'],
                'requires' => $package['requires'],
                'files' => [$path],
                'pinned' => $package['pinned'] ?? false,
                'enabled' => $enabled,
                'size' => $package['size'] ?: $this->files->size($path),
            ],
        );
    }

    /**
     * A resource pack: server.properties points the players to it, no file on the server. A new
     * one is switched on, which switches the one before off.
     */
    private function installResourcePack(array $package): ModInstall
    {
        $existing = $this->installs()->firstWhere('package', $package['id']);
        $enabled = $existing?->enabled ?? true;

        if ($enabled) {
            $this->useResourcePack($package, $package['id']);
        }

        return ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => $package['id']],
            [
                'name' => $package['name'],
                'version' => $package['version'],
                'icon' => $package['icon'],
                'dependency' => false,
                'requires' => [],
                'files' => [],
                'pinned' => $package['pinned'] ?? false,
                'enabled' => $enabled,
                'size' => $package['size'],
            ],
        );
    }

    /**
     * Points server.properties to this resource pack, or to none. The other resource packs are
     * switched off - Minecraft offers only one.
     *
     * @param  array<string, mixed>|null  $package  from release()
     */
    private function useResourcePack(?array $package, ?string $id = null): void
    {
        $this->files->write(self::PROPERTIES, self::withProperties((string) $this->files->read(self::PROPERTIES), [
            'resource-pack' => (string) ($package['download'] ?? ''),
            'resource-pack-sha1' => (string) ($package['sha1'] ?? ''),
        ]));

        if ($id !== null) {
            ModInstall::query()
                ->where('server_id', $this->server->id)
                ->where('package', 'like', Modrinth::KINDS['resourcepack']['prefix'] . '%')
                ->where('package', '!=', $id)
                ->update(['enabled' => false]);
        }
    }

    /** The world's datapacks folder, e.g. world/datapacks - the world named in server.properties. */
    private function datapacksDir(): string
    {
        $level = trim((string) self::property((string) $this->files->read(self::PROPERTIES), 'level-name'), '/');

        return ($level === '' || str_contains($level, '..') ? 'world' : $level) . '/datapacks';
    }

    /** A value of a Java properties file, "\:" read as ":"; null when it is not there. */
    public static function property(string $text, string $key): ?string
    {
        if (!preg_match('/^' . preg_quote($key, '/') . '[ \t]*[=:][ \t]*([^\r\n]*)/m', $text, $match)) {
            return null;
        }

        return preg_replace('/\\\\(.)/', '$1', trim($match[1]));
    }

    /**
     * The properties file with these values set: a line that is there is replaced, else one is
     * added. Everything else stays as it is.
     *
     * @param  array<string, string>  $values
     */
    public static function withProperties(string $text, array $values): string
    {
        foreach ($values as $key => $value) {
            // one line, and a backslash would start an escape
            $line = "$key=" . str_replace(['\\', "\r", "\n"], ['\\\\', '', ''], $value);
            $replaced = preg_replace_callback('/^' . preg_quote($key, '/') . '[ \t]*[=:][^\r\n]*/m', fn () => $line, $text, 1, $count);
            $text = $count ? $replaced : ($text === '' ? '' : rtrim($text, "\r\n") . "\n") . "$line\n";
        }

        return $text;
    }

    /** plugin (any mod), datapack or resourcepack */
    public static function kind(string $package): string
    {
        return Modrinth::kindOf($package) ?? 'plugin';
    }

    /** The file's name as the source gives it - it ends up in a path, so nothing but a plain name. */
    public static function jarName(string $file): string
    {
        if (!preg_match('/^\w[\w .+()\[\]-]*\.(jar|zip)\z/i', $file)) {
            throw new RuntimeException(trans('mod-manager::mods.messages.bad_file', ['file' => $file]));
        }

        return $file;
    }

    /** A package of a source that ships single jars: Modrinth or Hangar. */
    public static function jarPackage(string $package): bool
    {
        return Modrinth::kindOf($package) !== null || str_starts_with($package, Hangar::PREFIX);
    }

    /** Mods of this game are single jars: the profile gets them from Modrinth or Hangar. */
    public function jarMods(): bool
    {
        return array_intersect($this->profile->sources(), ['modrinth', 'hangar']) !== [];
    }

    private function sourceFor(string $package): Thunderstore|Modrinth|Hangar|GoldSrc
    {
        return match (true) {
            $package === GoldSrc::PACKAGE => new GoldSrc(),
            Modrinth::kindOf($package) !== null => new Modrinth((array) $this->profile->setting('modrinth_loaders', []), (string) Modrinth::kindOf($package)),
            str_starts_with($package, Hangar::PREFIX) => new Hangar(),
            default => $this->source,
        };
    }

    /** The version the server's game runs, read once - null where the profile does not say. */
    private function gameVersion(): ?string
    {
        $this->gameVersion ??= [GameVersion::of($this->server, $this->profile)['version'] ?? null];

        return $this->gameVersion[0];
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
