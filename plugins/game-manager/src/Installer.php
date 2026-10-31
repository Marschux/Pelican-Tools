<?php

namespace Marschux\GameManager;

use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Marschux\GameManager\Concerns\HandlesConfigs;
use Marschux\GameManager\Concerns\HandlesCounterStrikeSharp;
use Marschux\GameManager\Concerns\HandlesGoldSrc;
use Marschux\GameManager\Concerns\HandlesJars;
use Marschux\GameManager\Concerns\HandlesLoader;
use Marschux\GameManager\Concerns\HandlesMaps;
use Marschux\GameManager\Concerns\HandlesPlugins;
use Marschux\GameManager\Concerns\HandlesSourceMod;
use Marschux\GameManager\Concerns\HandlesUnmanaged;
use Marschux\GameManager\Models\ModInstall;
use Marschux\GameManager\Models\ModProfile;
use Marschux\GameManager\Sources\GameBanana;
use Marschux\GameManager\Sources\GitHub;
use Marschux\GameManager\Sources\GoldSrc;
use Marschux\GameManager\Sources\Hangar;
use Marschux\GameManager\Sources\Modrinth;
use Marschux\GameManager\Sources\Source1;
use Marschux\GameManager\Sources\Source2;
use Marschux\GameManager\Sources\Thunderstore;
use Marschux\GameManager\Sources\Workshop;
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
    use HandlesLoader;
    use HandlesGoldSrc;
    use HandlesSourceMod;
    use HandlesCounterStrikeSharp;
    use HandlesPlugins;
    use HandlesMaps;
    use HandlesJars;
    use HandlesConfigs;
    use HandlesUnmanaged;

    /**
     * what liblist.gam loads once Metamod is in, and what Metamod loads. Not metamod_i386.so as
     * it comes: current HLDS fails to load a game library with "_i386" in its name ("Couldn't
     * get DLL API" with a garbled path), so it is put there under this name.
     */
    private const METAMOD_DLL = 'addons/metamod/metamod.so';

    /** CS:GO: the mapgroup of the maps switched on here */
    public const MAPGROUP = 'mg_modmanager';

    private const AMXX_DLL = 'addons/amxmodx/dlls/amxmodx_mm_i386.so';

    /** YaPB's library, as Metamod's plugins.ini loads it */
    private const YAPB_DLL = 'addons/yapb/bin/yapb.so';

    /** the folders below addons/yapb/data it writes to */
    private const YAPB_FOLDERS = ['logs', 'pwf', 'train'];

    /** AMX Mod X's own configs: an uploaded zip that brings one along never gets it, nor deletes it */
    private const AMXX_CONFIGS = ['amxx.cfg', 'sql.cfg', 'custommenuitems.cfg', 'plugins.ini', 'modules.ini', 'users.ini', 'core.ini', 'configs.ini', 'cmdaccess.ini', 'clcmds.ini', 'cmds.ini', 'cvars.ini', 'maps.ini', 'speech.ini', 'stats.ini', 'miscstats.ini'];

    /** where Valve's files wait while ReHLDS and ReGameDLL are in */
    private const ENGINE_BACKUP = '.game-manager/valve';

    /** the package of a plugin uploaded through the page: "upload:<file name without .amxx>" */
    public const UPLOAD = 'upload:';

    /** A map uploaded through the page, by its name */
    public const MAP_UPLOAD = 'upload-map:';

    /** Counter-Strike 2: the CounterStrikeSharp plugin that changes to workshop maps, and its block in maplist.txt. */
    public const MAP_VOTE = 'RockTheVote';

    private const MAPLIST_START = '// game-manager: workshop maps, kept by the panel';

    private const MAPLIST_END = '// game-manager: end';

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
                    throw new RuntimeException(trans('game-manager::mods.messages.twin', ['name' => $twin->name, 'source' => str_starts_with($twin->package, Hangar::PREFIX) ? 'Hangar' : 'Modrinth']));
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

        // configs an uploaded archive brought along are only deleted when asked, like those a mod wrote;
        // a file another install also has (a wad two maps share) stays
        $others = $this->filesOfOthers($install->package);
        $files = array_filter(
            $removing->pluck('files')->flatten()->all(),
            fn (string $path) => (!$this->ownPlugin($install->package) || !$this->pluginConfig($path)) && !in_array($path, $others, true),
        );

        $this->files->delete([...$files, ...$configs]);

        if (self::kind($install->package) === 'resourcepack' && $install->enabled) {
            $this->useResourcePack(null);
        }

        if ($this->goldsrc() && $this->ownPlugin($install->package)) {
            $this->writeUploadedIni(fn (string $ini) => array_reduce($this->amxxFilesOf($install), fn (string $ini, string $file) => self::withoutAmxxPlugin($ini, $file), $ini));
        }

        if (self::mapPackage($install->package)) {
            $this->writeMapcycle(fn (string $cycle) => self::withMaps($cycle, self::mapNames($install->files), false));
        }

        ModInstall::query()->whereIn('id', $removing->pluck('id'))->delete();

        if (str_starts_with($install->package, Workshop::PREFIX)) {
            $this->writeMaplist();
        }

        return $removing->pluck('name')->all();
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

    /** An upload: a compiled plugin, or a zip with plugins and what they bring along. */
    public const UPLOADS = '.game-manager/upload';

    /** A startup variable of the server by its env name, '' when the egg has none or it is empty. */
    private function variable(string $name): string
    {
        $variable = $this->server->egg->variables()->where('env_variable', $name)->first();

        return $variable ? trim((string) $this->server->serverVariables()->where('variable_id', $variable->id)->value('variable_value')) : '';
    }

    /** A startup variable of the server set, when the egg has it. */
    private function setVariable(string $name, string $value): void
    {
        $variable = $this->server->egg->variables()->where('env_variable', $name)->first();

        if ($variable) {
            $this->server->serverVariables()->updateOrCreate(['variable_id' => $variable->id], ['variable_value' => $value]);
        }
    }

    /** @return string[] the files every other install of this server has */
    private function filesOfOthers(string $package): array
    {
        return $this->installs()->where('package', '!=', $package)->pluck('files')->flatten()->all();
    }

    /** @return string[] every file the page put on the server */
    private function knownFiles(): array
    {
        return $this->installs()->pluck('files')->flatten()->all();
    }

    private function goldsrc(): bool
    {
        return $this->profile->setting('loader_type') === 'goldsrc';
    }

    /** Counter-Strike 2: Metamod:Source + CounterStrikeSharp. */
    private function source2(): bool
    {
        return $this->profile->setting('loader_type') === 'source2';
    }

    /** Metamod:Source + SourceMod: Counter-Strike: Source, CS:GO. */
    private function source1(): bool
    {
        return $this->profile->setting('loader_type') === 'source1';
    }

    /** e.g. cstrike */
    private function gameDir(): string
    {
        return trim((string) $this->profile->setting('game_dir'), '/');
    }

    /**
     * Installed packages with a newer version out, the loader included - pinned ones too, they
     * only stay out of "update all".
     *
     * @return array<string, string> package => newest version
     */
    public function updates(): array
    {
        // the game keeps workshop maps up to date itself
        $installs = $this->installs()->reject(fn (ModInstall $install) => str_starts_with($install->package, Workshop::PREFIX));
        $key = 'game-manager:updates:' . $this->server->uuid . ':' . md5(json_encode([$installs->map(fn (ModInstall $install) => [$install->package, $install->version])->values(), $this->gameVersion()]));

        // the sources cache for an hour; this answer for less, so a release shows up soon
        return Cache::remember($key, now()->addMinutes(10), function () use ($installs) {
            $this->prefetchLatest($installs->pluck('package')->all());

            return $this->compareVersions($installs);
        });
    }

    /**
     * Asks every source at once, each in a process of its own, so the check takes as long as the
     * slowest answer instead of all of them in a row. What they answer lands in the sources'
     * caches, which compareVersions() then reads. A failing ask is left to compareVersions().
     *
     * @param  string[]  $packages
     */
    private function prefetchLatest(array $packages): void
    {
        $tasks = [];

        foreach ($packages as $package) {
            $source = get_class($this->sourceFor($package));
            // one listing holds every Thunderstore package of the community
            $tasks[$source === Thunderstore::class ? $source : $package] ??= $package;
        }

        if (count($tasks) < 2) {
            return;
        }

        $server = $this->server->id;
        $profile = $this->profile->id;
        $closures = [];

        // a plain loop: the closure serializer takes the outer one of nested closures
        foreach ($tasks as $package) {
            $closures[] = static function () use ($server, $profile, $package) {
                try {
                    (new Installer(Server::findOrFail($server), ModProfile::findOrFail($profile)))->latestVersion($package);
                } catch (\Throwable) {
                    // compareVersions() asks again and skips it
                }
            };
        }

        try {
            Concurrency::run($closures);
        } catch (\Throwable $exception) {
            // no processes to be had: compareVersions() asks one after the other
            report($exception);
        }
    }

    /** The newest version of a package for the server's game version, from its source. */
    public function latestVersion(string $package): string
    {
        return $this->sourceFor($package)->latestVersion($package, $this->gameVersion());
    }

    /**
     * @param  Collection<int, ModInstall>  $installs
     * @return array<string, string> package => newest version
     */
    private function compareVersions(Collection $installs): array
    {
        $updates = [];

        foreach ($installs as $install) {
            try {
                // before 0.31 a GameBanana file's name stood for its version, now the day it was added
                if (str_starts_with($install->package, GameBanana::PREFIX) && !preg_match('/^\d{4}-\d\d-\d\d\z/', $install->version)) {
                    $install->update(['version' => $this->sourceFor($install->package)->release($install->package, $install->version)['version']]);
                }

                $latest = $this->latestVersion($install->package);
            } catch (\Throwable) {
                continue;
            }

            // thunderstore numbers are plain semver, plugin versions are anything ("v5.5.71-bukkit"):
            // there the newest for the game version simply differs from what is installed
            if (self::jarPackage($install->package) || in_array($install->package, [GoldSrc::PACKAGE, GoldSrc::ENGINE, GoldSrc::BOTS, Source1::PACKAGE, Source2::PACKAGE], true) || str_starts_with($install->package, GitHub::PREFIX) ? $latest !== $install->version : version_compare($latest, $install->version, '>')) {
                $updates[$install->package] = $latest;
            }
        }

        return $updates;
    }

    /** The newest version, plus any dependency it newly needs. Unpins a pinned version. */
    public function update(ModInstall $install): void
    {
        if (str_starts_with($install->package, GitHub::PREFIX)) {
            $github = new GitHub();
            $asset = $github->asset($install->package);
            $this->installGitHubAsset($install->package, $asset, $asset['tag']);

            return;
        }

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

        // a map stays where it is: on means it is in mapcycle.txt
        if (self::mapPackage($install->package)) {
            $this->writeMapcycle(fn (string $cycle) => self::withMaps($cycle, self::mapNames($install->files), $enabled));
            $install->update(['enabled' => $enabled]);

            return;
        }

        // nothing on disk: on means it is in RockTheVote's maplist.txt
        if (str_starts_with($install->package, Workshop::PREFIX)) {
            $install->update(['enabled' => $enabled]);
            $this->writeMaplist();

            return;
        }

        // nothing to move: on means server.properties points to it, and only one can be on
        if (self::kind($install->package) === 'resourcepack') {
            $this->useResourcePack($enabled ? $this->sourceFor($install->package)->release($install->package, $install->version) : null, $install->package);
            $install->update(['enabled' => $enabled]);

            return;
        }

        // the file stays where it is, AMX Mod X skips a line commented out
        if ($this->goldsrc() && $this->ownPlugin($install->package)) {
            $this->writeUploadedIni(fn (string $ini) => array_reduce($this->amxxFilesOf($install), fn (string $ini, string $file) => self::withAmxxPlugin($ini, $file, $enabled), $ini));
            $install->update(['enabled' => $enabled]);

            return;
        }

        // CounterStrikeSharp: only the plugin folder moves (to plugins_disabled), its configs stay editable
        $bases = $this->source2() ? [$this->modsDir()] : $this->bases();
        $files = array_map(fn (string $path) => $this->source1() ? self::smxSwitched($path, $enabled, $this->modsDir()) : self::switched($path, $enabled, $bases), $install->files);
        $moves = [];

        foreach ($install->files as $index => $from) {
            if ($from !== $files[$index]) {
                $moves[] = ['from' => $from, 'to' => $files[$index]];
            }
        }

        $this->files->move($moves);
        $install->update(['enabled' => $enabled, 'files' => $files]);
    }

    /** Measures installs from before sizes were kept, once. */
    public function fillSizes(Collection $installs): void
    {
        foreach ($installs->whereNull('size') as $install) {
            $install->update(['size' => array_sum(array_map(fn (string $path) => $this->files->size($path), $install->files))]);
        }
    }

    /** What players need on their side: every mod that is switched on, the loader first. */
    public function playerList(): string
    {
        $community = (string) $this->profile->setting('community');
        $loader = $this->profile->loaderPackage();

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

                if (str_starts_with($install->package, GameBanana::PREFIX)) {
                    return "$install->name - https://gamebanana.com/mods/" . GameBanana::split($install->package);
                }

                if (str_starts_with($install->package, Workshop::PREFIX)) {
                    return "$install->name - https://steamcommunity.com/sharedfiles/filedetails/?id=" . substr($install->package, strlen(Workshop::PREFIX));
                }

                if (str_starts_with($install->package, GitHub::PREFIX)) {
                    return "$install->name $install->version - https://github.com/" . GitHub::parse($install->package)[0];
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
        if (str_starts_with($package['id'], GameBanana::PREFIX)) {
            return $this->installMap($package);
        }

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

    private function sourceFor(string $package): Thunderstore|Modrinth|Hangar|GoldSrc|Source1|Source2|GitHub|GameBanana
    {
        return match (true) {
            $package === GoldSrc::PACKAGE, $package === GoldSrc::ENGINE, $package === GoldSrc::BOTS => new GoldSrc(),
            $package === Source1::PACKAGE => new Source1(),
            $package === Source2::PACKAGE => new Source2(),
            str_starts_with($package, GitHub::PREFIX) => new GitHub(),
            str_starts_with($package, GameBanana::PREFIX) => new GameBanana((int) $this->profile->setting('gamebanana_game'), (int) $this->profile->setting('gamebanana_category')),
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
