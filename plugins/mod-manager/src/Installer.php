<?php

namespace Marschux\ModManager;

use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Marschux\ModManager\Models\ModInstall;
use Marschux\ModManager\Models\ModProfile;
use Marschux\ModManager\Sources\GameBanana;
use Marschux\ModManager\Sources\GitHub;
use Marschux\ModManager\Sources\GoldSrc;
use Marschux\ModManager\Profiles\Presets;
use Marschux\ModManager\Sources\Source1;
use Marschux\ModManager\Sources\Source2;
use Marschux\ModManager\Sources\Hangar;
use Marschux\ModManager\Sources\Modrinth;
use Marschux\ModManager\Sources\Thunderstore;
use Marschux\ModManager\Sources\Workshop;
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

    /** CS:GO: the mapgroup of the maps switched on here */
    public const MAPGROUP = 'mg_modmanager';

    private const AMXX_DLL = 'addons/amxmodx/dlls/amxmodx_mm_i386.so';

    /** AMX Mod X's own configs: an uploaded zip that brings one along never gets it, nor deletes it */
    private const AMXX_CONFIGS = ['amxx.cfg', 'sql.cfg', 'custommenuitems.cfg', 'plugins.ini', 'modules.ini', 'users.ini', 'core.ini', 'configs.ini', 'cmdaccess.ini', 'clcmds.ini', 'cmds.ini', 'cvars.ini', 'maps.ini', 'speech.ini', 'stats.ini', 'miscstats.ini'];

    /** where Valve's files wait while ReHLDS and ReGameDLL are in */
    private const ENGINE_BACKUP = '.mod-manager/valve';

    /** the package of a plugin uploaded through the page: "upload:<file name without .amxx>" */
    public const UPLOAD = 'upload:';

    /** A map uploaded through the page, by its name */
    public const MAP_UPLOAD = 'upload-map:';

    /** Counter-Strike 2: the CounterStrikeSharp plugin that changes to workshop maps, and its block in maplist.txt. */
    public const MAP_VOTE = 'RockTheVote';

    private const MAPLIST_START = '// mod-manager: workshop maps, kept by the panel';

    private const MAPLIST_END = '// mod-manager: end';

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
        if ($this->goldsrc() || $this->source1()) {
            return array_values(array_filter(
                $installs->filter(fn (ModInstall $install) => $this->ownPlugin($install->package))->pluck('files')->flatten()->all(),
                fn (string $path) => $this->pluginConfig($path),
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
        if ($this->archiveLoader()) {
            return $this->installArchiveLoader();
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
            // AMX Mod X and SourceMod keep what plugins saved (vaults, languages, sqlite) in their data folder
            $kept = match (true) {
                $this->goldsrc() => [...$this->keptDirs(), $this->gameDir() . '/addons/amxmodx/data'],
                $this->source1() => [...$this->keptDirs(), $this->gameDir() . '/addons/sourcemod/data', $this->gameDir() . '/cfg/sourcemod'],
                default => $this->keptDirs(),
            };

            $this->files->delete(self::loaderPaths($loader->files, $kept));
            $loader->delete();
        }

        if ($this->goldsrc()) {
            $this->hookGoldSrc(false);

            return;
        }

        // Source 2: the egg's start.sh takes Metamod out of gameinfo.gi once its folder is gone;
        // Source 1: Metamod's .vdf went with its files
        if ($this->source2() || $this->source1()) {
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
     * A loader made of archives - Metamod-R and AMX Mod X, or Metamod:Source and CounterStrikeSharp:
     * every archive unpacked on the server, its addons/ folder sorted into the game folder, a later
     * archive's files winning. Configs already on the server stay, they may have been changed.
     * GoldSrc then hooks Metamod into liblist.gam; Source 2 needs nothing, the egg's start.sh lists
     * Metamod in gameinfo.gi on every start; Source 1 neither, Metamod's own addons/metamod.vdf
     * loads it. SourceMod also brings cfg/sourcemod.
     */
    private function installArchiveLoader(): ModInstall
    {
        $goldsrc = $this->goldsrc();
        $release = match (true) {
            $goldsrc => (new GoldSrc())->release(),
            $this->source1() => (new Source1())->release(),
            default => (new Source2())->release(),
        };
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

                $paths = [
                    ...array_map(fn (string $path) => "addons/$path", $this->files->walk("$directory/addons")),
                    ...($this->source1() && $this->files->exists("$directory/cfg") ? array_map(fn (string $path) => "cfg/$path", $this->files->walk("$directory/cfg")) : []),
                ];

                foreach ($paths as $path) {

                    // CounterStrikeSharp is .NET: its .dll files are what runs, on Linux too
                    if ($goldsrc && !self::goldsrcFile($path)) {
                        continue;
                    }

                    $to = "$game/" . ($goldsrc ? self::goldsrcTarget($path) : $path);
                    $listings[dirname($to)] ??= $this->files->list(dirname($to));

                    // a SourceMod plugin switched on or off on the page keeps that: its new version goes where the old one is
                    $plugins = $this->modsDir();
                    if ($this->source1() && in_array(dirname($to), [$plugins, "$plugins/disabled"], true) && !isset($listings[dirname($to)][basename($to)])) {
                        $other = dirname($to) === $plugins ? "$plugins/disabled" : $plugins;
                        $listings[$other] ??= $this->files->list($other);
                        $to = isset($listings[$other][basename($to)]) ? "$other/" . basename($to) : $to;
                    }

                    if (isset($listings[dirname($to)][basename($to)])) {
                        if (self::keptOnUpdate($path) || (!$goldsrc && self::loaderConfig($path))) {
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

        if ($goldsrc) {
            $this->hookGoldSrc(true);
        }

        $previous = $this->installs()->firstWhere('package', $release['id'])?->files ?? [];

        return ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => $release['id']],
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
     * ReHLDS, ReGameDLL and ReAPI over Valve's files: what is there of Valve's moves to
     * .mod-manager/valve first and comes back on removal. A config already there (ReGameDLL's
     * game.cfg) is kept through an update. Running binaries can be replaced - the server picks
     * them up on its next start.
     */
    public function installEngine(): ModInstall
    {
        $release = (new GoldSrc())->engineRelease();
        $game = $this->gameDir();
        $scratch = '.mod-manager/engine';
        $ours = $this->installs()->firstWhere('package', GoldSrc::ENGINE)?->files ?? [];
        $targets = [];
        $moves = [];
        $kept = [];

        $this->files->delete($this->files->exists($scratch) ? [$scratch] : []);

        try {
            foreach ($release['parts'] as $part) {
                $directory = "$scratch/{$part['name']}";
                $file = basename((string) parse_url($part['url'], PHP_URL_PATH));

                $this->files->pull($part['url'], $directory, $file);

                if ($part['name'] === 'steam_api') {
                    $targets[$file] = "$directory/$file";

                    continue;
                }

                $this->files->decompress($directory, $file);

                foreach ($this->files->walk($directory) as $path) {
                    $to = self::enginePlacement($part['name'], $path, $game);

                    if ($to !== null) {
                        $targets[$to] = "$directory/$path";
                    }
                }
            }

            $backups = [];
            $replace = [];
            $listings = [];

            foreach ($targets as $to => $from) {
                $listings[dirname($to)] ??= $this->files->list(dirname($to) === '.' ? '' : dirname($to));

                if (isset($listings[dirname($to)][basename($to)])) {
                    if (self::keptOnUpdate($to)) {
                        $kept[] = $to;

                        continue;
                    }

                    if (in_array($to, $ours, true)) {
                        $replace[] = $to;
                    } else {
                        // Valve's file, put aside once; a newer one after a steamcmd validate replaces the copy
                        $replace[] = self::ENGINE_BACKUP . "/$to";
                        $backups[] = ['from' => $to, 'to' => self::ENGINE_BACKUP . "/$to"];
                    }
                }

                $moves[] = ['from' => $from, 'to' => $to];
            }

            // Wings will not move onto a file
            $this->files->delete($replace);
            $this->files->move($backups);
            $this->files->move($moves);
        } finally {
            $this->files->delete([$scratch]);
        }

        // the start binary has to stay executable, whatever the unpacking did to it
        $this->files->chmod('hlds_linux', '755');

        return ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => GoldSrc::ENGINE],
            [
                'name' => $release['name'],
                'version' => $release['version'],
                'icon' => null,
                'dependency' => false,
                'requires' => [],
                'files' => array_values(array_unique([...array_column($moves, 'to'), ...$kept])),
                'size' => null,
            ],
        );
    }

    /** Valve's engine and game library again: ours deleted, the copies put back. */
    public function uninstallEngine(): void
    {
        $engine = $this->installs()->firstWhere('package', GoldSrc::ENGINE);

        $this->files->delete($engine?->files ?? []);

        $this->files->move(array_map(
            fn (string $path) => ['from' => self::ENGINE_BACKUP . "/$path", 'to' => $path],
            $this->files->walk(self::ENGINE_BACKUP),
        ));
        $this->files->delete([self::ENGINE_BACKUP]);
        $this->files->chmod('hlds_linux', '755');

        $engine?->delete();
    }

    /**
     * Where a file of the engine's archives goes, from the server root - null to leave it out.
     * ReHLDS: its Linux build to the root. ReGameDLL: its cstrike folder to the game folder (it
     * serves Condition Zero as well). ReAPI: only the module, AMX Mod X loads it.
     */
    public static function enginePlacement(string $part, string $path, string $game): ?string
    {
        return match (true) {
            $part === 'rehlds' && str_starts_with($path, 'bin/linux32/') => substr($path, strlen('bin/linux32/')),
            $part === 'regamedll' && str_starts_with($path, 'bin/linux32/cstrike/') => "$game/" . substr($path, strlen('bin/linux32/cstrike/')),
            $part === 'reapi' && $path === 'addons/amxmodx/modules/reapi_amxx_i386.so' => "$game/$path",
            default => null,
        };
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

    /**
     * Metamod is in: liblist.gam loads it, or - Source 2 - gameinfo.gi lists it, which the egg's
     * start.sh does on the next start. Off when e.g. a "validate" of steamcmd restored the file.
     */
    public function loaderHooked(): bool
    {
        if ($this->source2()) {
            return str_contains((string) $this->files->read($this->gameDir() . '/gameinfo.gi'), 'csgo/addons/metamod');
        }

        // Source 1: Metamod's .vdf is what the engine reads to load it
        if ($this->source1()) {
            return $this->files->exists($this->gameDir() . '/addons/metamod.vdf');
        }

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

    /** CounterStrikeSharp's or SourceMod's own settings (core.json, admins_simple.ini ...): kept through an update. */
    public static function loaderConfig(string $path): bool
    {
        return str_starts_with($path, 'addons/counterstrikesharp/configs/') || str_starts_with($path, 'addons/sourcemod/configs/') || str_starts_with($path, 'cfg/');
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
        if ($this->source1()) {
            return $this->sourcemodPlugins();
        }

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
        if ($this->source1()) {
            // SourceMod loads every .smx in plugins/ and ignores plugins/disabled/
            if (!collect($this->sourcemodPlugins())->contains(fn (array $plugin) => $plugin['file'] === $file && $plugin['enabled'] !== $enabled)) {
                throw new RuntimeException(trans('mod-manager::mods.messages.not_adoptable'));
            }

            $on = $this->modsDir() . "/$file";
            $off = $this->modsDir() . "/disabled/$file";
            $this->files->move([$enabled ? ['from' => $off, 'to' => $on] : ['from' => $on, 'to' => $off]]);

            return;
        }

        $path = $this->configDir() . '/plugins.ini';
        $ini = (string) $this->files->read($path);

        if (!in_array($file, array_column(self::parseAmxxPlugins($ini), 'file'), true)) {
            throw new RuntimeException(trans('mod-manager::mods.messages.not_adoptable'));
        }

        $this->files->write($path, self::withAmxxPlugin($ini, $file, $enabled));
    }

    /**
     * The .smx in SourceMod's plugins/ (on) and plugins/disabled/ (off) not installed through the
     * plugin - those SourceMod comes with, and any put there by hand.
     *
     * @return array<int, array{file: string, enabled: bool, note: string, group: string, exists: bool}>
     */
    private function sourcemodPlugins(): array
    {
        // the loader's own files include SourceMod's plugins - those are exactly what is listed here
        $known = $this->installs()->where('package', '!=', Source1::PACKAGE)->pluck('files')->flatten()->all();
        $plugins = [];

        foreach (['' => true, '/disabled' => false] as $folder => $enabled) {
            foreach ($this->files->list($this->modsDir() . $folder) as $name => $isDirectory) {
                if (!$isDirectory && str_ends_with(strtolower($name), '.smx') && !in_array($this->modsDir() . "$folder/$name", $known, true)) {
                    $plugins[] = ['file' => $name, 'enabled' => $enabled, 'note' => '', 'group' => '', 'exists' => true];
                }
            }
        }

        usort($plugins, fn (array $a, array $b) => strcasecmp($a['file'], $b['file']));

        return $plugins;
    }

    /**
     * A plugin of plugins.ini gone: its file and its line. One AMX Mod X comes with is back as a
     * file after the next loader update - without a line, so it is not loaded.
     */
    public function deleteAmxxPlugin(string $file): void
    {
        if ($this->source1()) {
            $plugin = collect($this->sourcemodPlugins())->firstWhere('file', $file)
                ?? throw new RuntimeException(trans('mod-manager::mods.messages.not_adoptable'));

            // one SourceMod comes with is back after the next loader update, switched on
            $this->files->delete([$this->modsDir() . ($plugin['enabled'] ? '' : '/disabled') . "/$file"]);

            return;
        }

        $path = $this->configDir() . '/plugins.ini';
        $ini = (string) $this->files->read($path);

        if (!in_array($file, array_column(self::parseAmxxPlugins($ini), 'file'), true)) {
            throw new RuntimeException(trans('mod-manager::mods.messages.not_adoptable'));
        }

        $this->files->delete([$this->modsDir() . '/' . self::amxxName($file)]);
        $this->files->write($path, self::withoutAmxxPlugin($ini, $file));
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
    private function installAmxx(string $name, string $directory, ?string $package = null, ?string $version = null): ModInstall
    {
        $name = self::amxxName($name);
        $package ??= self::UPLOAD . substr($name, 0, -5);
        $existing = $this->installs()->firstWhere('package', $package);
        $path = $this->modsDir() . "/$name";

        // e.g. one AMX Mod X comes with - replacing it would be gone on the loader's next update
        if ($this->files->exists($path)) {
            if (!$existing) {
                throw new RuntimeException(trans('mod-manager::mods.messages.exists', ['file' => $name]));
            }

            // Wings will not move onto a file
            $this->files->delete([$path]);
        }

        $enabled = $existing?->enabled ?? true;

        $this->files->move([['from' => "$directory/$name", 'to' => $path]]);
        $this->writeUploadedIni(fn (string $ini) => self::withAmxxPlugin(self::withAmxxLine($ini, $name), $name, $enabled));

        return ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => $package],
            [
                'name' => substr($name, 0, -5),
                // a compiled plugin carries no version that can be read here: when it came is what there is
                'version' => $version ?? now()->format('Y-m-d H:i'),
                'icon' => null,
                'dependency' => false,
                'requires' => [],
                'files' => [$path],
                'enabled' => $enabled,
                'size' => $this->files->size($path),
            ],
        );
    }

    /** One of AMX Mod X's own configs, or a plugins-*.ini - kept, never deleted from the page. */
    public static function amxxCore(string $path): bool
    {
        $file = strtolower(basename($path));

        return in_array($file, self::AMXX_CONFIGS, true) || (bool) preg_match('/^plugins-.+\.ini$/', $file);
    }

    /** An upload: a compiled plugin, or a zip with plugins and what they bring along. */
    public const UPLOADS = '.mod-manager/upload';

    /**
     * An upload the browser sent straight to Wings, into UPLOADS/<$token>/<$name>: a compiled
     * plugin, or a zip with plugins and what they bring along. The token's folder goes afterwards.
     */
    public function installUpload(string $token, string $name): ModInstall
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new RuntimeException(trans('mod-manager::mods.messages.bad_amxx', ['file' => $name]));
        }

        $directory = self::UPLOADS . "/$token";

        try {
            if ($this->source2()) {
                return $this->installCssArchive($name, $directory, self::UPLOAD . self::cssName([], $name), now()->format('Y-m-d H:i'));
            }

            $lower = strtolower($name);

            // a .bsp, or an archive with one: a map, not a plugin
            if (preg_match('/\.(bsp|zip|rar|7z)\z/', $lower)) {
                if (!preg_match('/^[\w .()\[\]+-]+\.\w+\z/', $name)) {
                    throw new RuntimeException(trans('mod-manager::mods.messages.bad_file', ['file' => $name]));
                }

                $size = $this->files->size("$directory/$name");

                if (!str_ends_with($lower, '.bsp')) {
                    $this->files->decompress($directory, $name);
                    $this->files->delete(["$directory/$name"]);
                }

                if (array_filter($this->files->walk($directory), fn (string $path) => str_ends_with(strtolower($path), '.bsp'))) {
                    $title = pathinfo($name, PATHINFO_FILENAME);

                    return $this->placeMap(['id' => self::MAP_UPLOAD . $title, 'name' => $title, 'version' => now()->format('Y-m-d'), 'icon' => null, 'size' => $size], $directory, $name);
                }

                if (!str_ends_with($lower, '.zip')) {
                    throw new RuntimeException(trans('mod-manager::mods.messages.no_map', ['file' => $name]));
                }
            }

            return str_ends_with($lower, '.zip') || $this->source1() ? $this->installPluginArchive($name, $directory) : $this->installAmxx($name, $directory);
        } finally {
            $this->files->delete([$directory]);
        }
    }

    /**
     * A zip of AMX Mod X plugins - or SourceMod plugins, a .smx on its own too: unpacked on the
     * server, sorted into the game folder (see amxxPlacement, smxPlacement). An AMX Mod X plugin
     * gets its line; SourceMod loads every .smx in its folder. Configs and other files already
     * there are kept; a plugin file that is there and not from this upload stops it.
     */
    private function installPluginArchive(string $name, string $scratch, ?string $package = null, ?string $version = null): ModInstall
    {
        $sourcemod = $this->source1();

        if (!preg_match($sourcemod ? '/^\w[\w.+-]*\.(zip|smx)\z/i' : '/^\w[\w.-]*\.zip\z/i', $name)) {
            throw new RuntimeException(trans($sourcemod ? 'mod-manager::mods.messages.bad_smx' : 'mod-manager::mods.messages.bad_amxx', ['file' => $name]));
        }

        $package ??= self::UPLOAD . substr($name, 0, -4);
        $existing = $this->installs()->firstWhere('package', $package);
        $game = $this->gameDir();
        $extension = $sourcemod ? '.smx' : '.amxx';

        // switched off, the plugins sit in plugins/disabled: back first, so the new files replace them
        $switchOff = $sourcemod && $existing && !$existing->enabled;

        if ($switchOff) {
            $this->setEnabled($existing, true);
            $existing->refresh();
        }

        try {
            // an upload is unpacked already, to look for a map
            if (str_ends_with(strtolower($name), '.zip') && $this->files->exists("$scratch/$name")) {
                $this->files->decompress($scratch, $name);
                $this->files->delete(["$scratch/$name"]);
            }

            $placed = [];

            foreach ($this->files->walk($scratch) as $path) {
                $to = $sourcemod ? self::smxPlacement($path) : self::amxxPlacement($path);

                if ($to !== null) {
                    $placed["$game/$to"] = "$scratch/$path";
                }
            }

            $plugins = array_values(array_filter(array_keys($placed), fn (string $path) => str_ends_with(strtolower($path), $extension)));

            if ($plugins === []) {
                $sources = array_filter($this->files->walk($scratch), fn (string $path) => str_ends_with(strtolower($path), $sourcemod ? '.sp' : '.sma'));
                $message = $sourcemod ? ($sources ? 'source_only_smx' : 'no_smx') : ($sources ? 'source_only' : 'no_plugin');

                throw new RuntimeException(trans("mod-manager::mods.messages.$message", ['file' => $name]));
            }

            $ours = $existing?->files ?? [];
            $moves = [];
            $replace = [];
            $kept = [];
            $listings = [];

            foreach ($placed as $to => $from) {
                $listings[dirname($to)] ??= $this->files->list(dirname($to));

                if (isset($listings[dirname($to)][basename($to)])) {
                    if (str_ends_with(strtolower($to), $extension) && !in_array($to, $ours, true)) {
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

        if (!$sourcemod) {
            $this->writeUploadedIni(fn (string $ini) => array_reduce($names, fn (string $ini, string $file) => self::withAmxxPlugin(self::withAmxxLine($ini, $file), $file, $enabled), $ini));
        }

        $install = ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => $package],
            [
                'name' => substr($name, 0, -4),
                'version' => $version ?? now()->format('Y-m-d H:i'),
                'icon' => null,
                'dependency' => false,
                'requires' => [],
                // kept configs stay tracked, so removing with the box ticked takes them along
                'files' => array_values(array_unique([...array_column($moves, 'to'), ...$kept, ...array_intersect($ours, array_keys($placed))])),
                'enabled' => $enabled,
                // the files it put there; a shared or kept one counts too
                'size' => array_sum(array_map(fn (array $move) => $this->files->size($move['to']), $moves)),
            ],
        );

        if ($switchOff) {
            $this->setEnabled($install, false);
        }

        return $install;
    }

    /**
     * A CounterStrikeSharp plugin from a zip - uploaded, or a GitHub release's asset - or a .dll on
     * its own: sorted into the game folder (see cssPlacement), each plugin folder tracked whole.
     * A plugin folder that is there and not this package's stops it; configs already there stay.
     */
    private function installCssArchive(string $name, string $scratch, string $package, string $version): ModInstall
    {
        if (!preg_match('/^\w[\w.+-]*\.(zip|dll)\z/i', $name)) {
            throw new RuntimeException(trans('mod-manager::mods.messages.bad_css', ['file' => $name]));
        }

        $existing = $this->installs()->firstWhere('package', $package);
        $game = $this->gameDir();
        $ours = $existing?->files ?? [];

        try {
            if (str_ends_with(strtolower($name), '.zip')) {
                $this->files->decompress($scratch, $name);
                $this->files->delete(["$scratch/$name"]);
            }

            $walked = $this->files->walk($scratch);
            $placed = [];

            foreach ($walked as $path) {
                $to = self::cssPlacement($path, self::cssName($walked, $name));

                if ($to !== null) {
                    $placed["$game/$to"] = "$scratch/$path";
                }
            }

            $plugins = self::cssPlugins(array_keys($placed), $this->modsDir());

            if ($plugins === []) {
                throw new RuntimeException(trans('mod-manager::mods.messages.no_css_plugin', ['file' => $name]));
            }

            foreach ($plugins as $folder) {
                if (!in_array($folder, $ours, true) && $this->files->exists($folder)) {
                    throw new RuntimeException(trans('mod-manager::mods.messages.exists', ['file' => basename($folder)]));
                }
            }

            // an update replaces the plugin folders whole, so nothing of the old version lingers
            $this->files->delete(array_values(array_intersect($ours, $plugins)));

            $moves = [];
            $kept = [];
            $listings = [];

            foreach ($placed as $to => $from) {
                $inPlugin = (bool) array_filter($plugins, fn (string $folder) => str_starts_with($to, "$folder/"));

                if (!$inPlugin) {
                    $listings[dirname($to)] ??= $this->files->list(dirname($to));

                    // a config may have been changed, anything else is someone else's
                    if (isset($listings[dirname($to)][basename($to)])) {
                        if ($this->inConfigDir($to) || in_array($to, $ours, true)) {
                            $kept[] = $to;
                        }

                        continue;
                    }
                }

                $moves[] = ['from' => $from, 'to' => $to];
            }

            $this->files->move($moves);
        } finally {
            $this->files->delete([$scratch]);
        }

        $others = array_values(array_filter(array_column($moves, 'to'), fn (string $path) => !array_filter($plugins, fn (string $folder) => str_starts_with($path, "$folder/"))));

        $install = ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => $package],
            [
                'name' => implode(', ', array_map('basename', $plugins)),
                'version' => $version,
                'icon' => null,
                'dependency' => false,
                'requires' => [],
                'files' => array_values(array_unique([...$plugins, ...$others, ...$kept])),
                'enabled' => true,
                'size' => array_sum(array_map(fn (string $path) => $this->files->size($path), [...$plugins, ...$others])),
            ],
        );

        if (in_array(self::MAP_VOTE, array_map('basename', $plugins), true)) {
            $this->writeMaplist();
        }

        return $install;
    }

    /** Counter-Strike 2: a workshop map by id or link - nothing to download, the game fetches it. */
    public function addWorkshopMap(string $input): ModInstall
    {
        $id = Workshop::id($input) ?? throw new RuntimeException(trans('mod-manager::mods.workshop.bad_link'));
        $install = $this->storeWorkshopMap((new Workshop())->summary($id));

        $this->writeMaplist();

        return $install;
    }

    /**
     * A workshop map, or each Counter-Strike 2 map of a collection - by id or link.
     *
     * @return array{collection: bool, added: int, skipped: int}
     */
    public function addWorkshop(string $input): array
    {
        $id = Workshop::id($input) ?? throw new RuntimeException(trans('mod-manager::mods.workshop.bad_link'));
        $workshop = new Workshop();
        $children = $workshop->children($id);

        if ($children === null) {
            $this->addWorkshopMap($id);

            return ['collection' => false, 'added' => 1, 'skipped' => 0];
        }

        $maps = $workshop->maps($children);

        if ($maps === []) {
            throw new RuntimeException(trans('mod-manager::mods.workshop.collection_empty'));
        }

        foreach ($maps as $map) {
            $this->storeWorkshopMap($map);
        }

        $this->writeMaplist();

        return ['collection' => true, 'added' => count($maps), 'skipped' => count($children) - count($maps)];
    }

    /** @param  array{id: string, name: string, title: string, icon: ?string, size: int}  $map  from Workshop::summary() */
    private function storeWorkshopMap(array $map): ModInstall
    {
        return ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => Workshop::PREFIX . $map['id']],
            ['name' => $map['title'], 'version' => $map['name'], 'icon' => $map['icon'], 'dependency' => false, 'requires' => [], 'files' => [], 'enabled' => true, 'size' => $map['size']],
        );
    }

    /** Workshop maps only download with a Game Server Login Token (STEAM_ACC): the server has to be logged on to Steam. */
    public function hasLoginToken(): bool
    {
        return $this->variable('STEAM_ACC') !== '';
    }

    /** Counter-Strike 2: the Steam Web API key in WORKSHOP_AUTHKEY - browsing the workshop needs it. */
    public function steamKey(): string
    {
        return $this->variable('WORKSHOP_AUTHKEY');
    }

    /** A startup variable of the server by its env name, '' when the egg has none or it is empty. */
    private function variable(string $name): string
    {
        $variable = $this->server->egg->variables()->where('env_variable', $name)->first();

        return $variable ? trim((string) $this->server->serverVariables()->where('variable_id', $variable->id)->value('variable_value')) : '';
    }

    /**
     * RockTheVote's maplist.txt with the workshop maps switched on, in a block of its own - lines
     * written by hand stay. A new file starts from the example RockTheVote comes with. Without
     * RockTheVote installed there is nothing to write.
     */
    public function writeMaplist(): void
    {
        $folder = collect([$this->modsDir(), $this->disabledDir()])->map(fn (string $dir) => "$dir/" . self::MAP_VOTE)->first(fn (string $path) => $this->files->exists("$path/" . self::MAP_VOTE . '.dll'));

        if ($folder === null) {
            return;
        }

        $current = (string) $this->files->read("$folder/maplist.txt");
        // a new file: the example's stock maps, not its sample workshop map - that would show twice
        $current = $current !== '' ? $current : (string) preg_replace('/^[^\/\n]*:\d+\r?\n/m', '', (string) $this->files->read("$folder/maplist.example.txt"));
        $maps = $this->installs()
            ->filter(fn (ModInstall $install) => str_starts_with($install->package, Workshop::PREFIX) && $install->enabled)
            ->map(fn (ModInstall $install) => $install->version . ':' . substr($install->package, strlen(Workshop::PREFIX)))
            ->values()
            ->all();

        $this->files->write("$folder/maplist.txt", self::withWorkshopBlock($current, $maps));
    }

    /** $text with the block of workshop maps replaced by $lines, or put at its end. */
    public static function withWorkshopBlock(string $text, array $lines): string
    {
        $block = [self::MAPLIST_START, ...$lines, self::MAPLIST_END];
        $kept = [];
        $inside = false;

        foreach (preg_split('/\r?\n/', rtrim($text, "\r\n")) as $line) {
            if (trim($line) === self::MAPLIST_START) {
                $inside = true;
            } elseif (trim($line) === self::MAPLIST_END) {
                $inside = false;
            } elseif (!$inside && ($line !== '' || $kept !== [])) {
                $kept[] = $line;
            }
        }

        return implode("\n", [...$kept, ...$block]) . "\n";
    }

    /** A zip of a GitHub release, e.g. a CounterStrikeSharp plugin, or an AMX Mod X .amxx: pulled by Wings, then like an upload. */
    public function installGitHub(string $repository, string $asset): ModInstall
    {
        $release = (new GitHub())->release($repository);
        $picked = collect($release['assets'])->firstWhere('name', $asset)
            ?? throw new RuntimeException(trans('mod-manager::mods.messages.github_none', ['repository' => $repository]));

        return $this->installGitHubAsset(GitHub::package($repository, $picked['name']), $picked, $release['tag']);
    }

    /** @param  array{name: string, url: string}  $asset */
    private function installGitHubAsset(string $package, array $asset, string $tag): ModInstall
    {
        $scratch = self::UPLOADS . '/' . bin2hex(random_bytes(16));
        $this->files->pull($asset['url'], $scratch, $asset['name']);

        if ($this->source2()) {
            return $this->installCssArchive($asset['name'], $scratch, $package, $tag);
        }

        try {
            return str_ends_with(strtolower($asset['name']), '.zip') || $this->source1()
                ? $this->installPluginArchive($asset['name'], $scratch, $package, $tag)
                : $this->installAmxx($asset['name'], $scratch, $package, $tag);
        } finally {
            $this->files->delete([$scratch]);
        }
    }

    /**
     * Where a file of a CounterStrikeSharp plugin's zip goes, relative to the game folder (csgo) -
     * null to leave it out. Whatever the zip wraps things in, a counterstrikesharp/, addons/ or
     * cfg/ folder counts from there, and so does plugins/, configs/, shared/ or gamedata/; a
     * plugin folder on its own (RockTheVote/RockTheVote.dll) or loose files go into
     * plugins/<$plugin>. Source code and readmes stay out.
     */
    public static function cssPlacement(string $path, string $plugin): ?string
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        $lower = array_map('strtolower', $parts);
        $file = strtolower(end($parts));
        $extension = pathinfo($file, PATHINFO_EXTENSION);

        if (in_array($extension, ['cs', 'csproj', 'sln', 'md'], true) || preg_match('/^(readme|license|licence)\b/', $file)) {
            return null;
        }

        foreach (['counterstrikesharp' => 'addons/counterstrikesharp/', 'addons' => 'addons/', 'cfg' => 'cfg/'] as $folder => $target) {
            if (($index = array_search($folder, array_slice($lower, 0, -1), true)) !== false) {
                return $target . implode('/', array_slice($parts, $index + 1));
            }
        }

        // the first of them in the path counts: configs/plugins/X/X.json is a config
        foreach (array_slice($lower, 0, -1) as $index => $folder) {
            if (in_array($folder, ['plugins', 'configs', 'shared', 'gamedata'], true)) {
                return 'addons/counterstrikesharp/' . implode('/', array_slice($parts, $index));
            }
        }

        // RockTheVote/RockTheVote.dll and its lang/ - the plugin's own folder
        $rest = count($parts) > 1 && strcasecmp($parts[0], $plugin) === 0 ? array_slice($parts, 1) : $parts;

        return "addons/counterstrikesharp/plugins/$plugin/" . implode('/', $rest);
    }

    /**
     * A plugin's name: the .dll that has a .deps.json next to it, as .NET builds them - else the
     * file's own name up to its version.
     *
     * @param  string[]  $paths
     */
    public static function cssName(array $paths, string $file): string
    {
        foreach ($paths as $path) {
            if (str_ends_with(strtolower($path), '.deps.json')) {
                return substr(basename($path), 0, -strlen('.deps.json'));
            }
        }

        return (string) preg_replace('/[-_.]*v?\d.*$/i', '', pathinfo($file, PATHINFO_FILENAME)) ?: pathinfo($file, PATHINFO_FILENAME);
    }

    /**
     * The plugin folders among placed files: plugins/<Name> holding <Name>.dll.
     *
     * @param  string[]  $placed
     * @return string[]
     */
    public static function cssPlugins(array $placed, string $modsDir): array
    {
        $plugins = [];

        foreach ($placed as $path) {
            if (preg_match('#^' . preg_quote($modsDir, '#') . '/([^/]+)/([^/]+)\.dll$#i', $path, $match) && strcasecmp($match[1], $match[2]) === 0) {
                $plugins["$modsDir/{$match[1]}"] = true;
            }
        }

        return array_keys($plugins);
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

    /**
     * Where a file of a SourceMod plugin's zip goes, relative to the game folder - null to leave
     * it out. Whatever the zip wraps things in, an addons/sourcemod/, cfg/ or game folder
     * (sound/, models/, materials/ ...) counts from there; loose files go where SourceMod looks for
     * them by their kind. Source code, the scripting folder and readmes stay out.
     */
    public static function smxPlacement(string $path): ?string
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        $lower = array_map('strtolower', $parts);
        $file = end($parts);
        $name = strtolower($file);
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (($index = array_search('addons', $lower, true)) !== false) {
            $rest = array_slice($lower, $index);

            return ($rest[1] ?? '') === 'sourcemod' && count($rest) > 3 && in_array($rest[2], ['plugins', 'configs', 'data', 'gamedata', 'translations', 'extensions'], true) && !in_array($extension, ['sp', 'inc'], true)
                ? implode('/', array_slice($parts, $index))
                : null;
        }

        foreach (['cfg', 'sound', 'models', 'materials', 'particles', 'maps', 'resource', 'scripts'] as $folder) {
            if (($index = array_search($folder, $lower, true)) !== false && $index < count($parts) - 1) {
                return implode('/', array_slice($parts, $index));
            }
        }

        return match (true) {
            $extension === 'smx' => "addons/sourcemod/plugins/$file",
            str_ends_with($name, '.phrases.txt') => "addons/sourcemod/translations/$file",
            str_ends_with($name, '.games.txt') => "addons/sourcemod/gamedata/$file",
            str_ends_with($name, '.ext.so') => "addons/sourcemod/extensions/$file",
            $extension === 'cfg' => "cfg/sourcemod/$file",
            $extension === 'ini' => "addons/sourcemod/configs/$file",
            default => null,
        };
    }

    /**
     * SourceMod skips plugins/disabled/ on its own: switching off moves a .smx below the plugins
     * folder there, back again when switched on. Translations, configs and the rest stay.
     */
    public static function smxSwitched(string $path, bool $enabled, string $plugins): string
    {
        if ($enabled) {
            return str_starts_with($path, "$plugins/disabled/") ? "$plugins/" . substr($path, strlen("$plugins/disabled/")) : $path;
        }

        return str_starts_with($path, "$plugins/") && !str_starts_with($path, "$plugins/disabled/") && str_ends_with(strtolower($path), '.smx')
            ? "$plugins/disabled/" . substr($path, strlen("$plugins/"))
            : $path;
    }

    /**
     * A map archive from GameBanana: pulled and unpacked on the server, sorted into the game
     * folder (see mapPlacement). Files already there stay as they are and are not this map's -
     * wads and sounds are shared between maps. A map whose .bsp is there and not from this
     * install (de_dust2.bsp of the game) is not put over it.
     *
     * @param  array<string, mixed>  $package  from GameBanana::release()
     */
    private function installMap(array $package): ModInstall
    {
        $scratch = '.mod-manager/map';
        $file = basename((string) $package['file']);

        if (!preg_match('/^[\w .()\[\]+-]+\.\w+\z/', $file)) {
            throw new RuntimeException(trans('mod-manager::mods.messages.bad_file', ['file' => $file]));
        }

        $this->files->delete($this->files->exists($scratch) ? [$scratch] : []);

        try {
            $this->files->pull($package['download'], $scratch, $file);
            $this->files->decompress($scratch, $file);
            $this->files->delete(["$scratch/$file"]);

            return $this->placeMap($package, $scratch, $file);
        } finally {
            $this->files->delete([$scratch]);
        }
    }

    /**
     * The unpacked map in $scratch sorted into the game folder and into the rotation - from
     * GameBanana or uploaded. $file only names it in messages.
     *
     * @param  array<string, mixed>  $package  id, name, version, icon, size
     */
    private function placeMap(array $package, string $scratch, string $file): ModInstall
    {
        $existing = $this->installs()->firstWhere('package', $package['id']);
        $ours = $existing?->files ?? [];
        $others = $this->filesOfOthers($package['id']);
        $game = $this->gameDir();

        $placed = [];

        foreach ($this->files->walk($scratch) as $path) {
            $to = self::mapPlacement($path);

            if ($to !== null) {
                $placed["$game/$to"] = "$scratch/$path";
            }
        }

        if (!array_filter(array_keys($placed), fn (string $path) => str_ends_with(strtolower($path), '.bsp'))) {
            throw new RuntimeException(trans('mod-manager::mods.messages.no_map', ['file' => $file]));
        }

        $moves = [];
        $replace = [];
        $shared = [];
        $listings = [];

        foreach ($placed as $to => $from) {
            $listings[dirname($to)] ??= $this->files->list(dirname($to));

            if (isset($listings[dirname($to)][basename($to)])) {
                if (!in_array($to, $ours, true)) {
                    if (str_ends_with(strtolower($to), '.bsp')) {
                        throw new RuntimeException(trans('mod-manager::mods.messages.exists', ['file' => basename($to)]));
                    }

                    // a wad or sound another installed map brought: this one needs it too, so it is
                    // both maps' - removing either leaves it while the other is there
                    if (in_array($to, $others, true)) {
                        $shared[] = $to;
                    }

                    continue;
                }

                $replace[] = $to;
            }

            $moves[] = ['from' => $from, 'to' => $to];
        }

        $this->files->delete($replace);
        $this->files->move($moves);

        // what the version before brought and this one does not goes - unless another map needs it
        $files = array_values(array_unique([...array_column($moves, 'to'), ...$shared]));
        $this->files->delete(array_values(array_diff($ours, $files, $others)));

        // a new map comes into the mapcycle; one taken out of it before stays out
        $enabled = $existing?->enabled ?? true;
        $previousMaps = $existing ? self::mapNames($existing->files) : [];

        $this->writeMapcycle(fn (string $cycle) => self::withMaps(self::withMaps($cycle, $previousMaps, false), self::mapNames($files), $enabled));

        return ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => $package['id']],
            [
                'name' => $package['name'],
                'version' => $package['version'],
                'icon' => $package['icon'],
                'dependency' => false,
                'requires' => [],
                'files' => $files,
                'pinned' => $package['pinned'] ?? false,
                'enabled' => $enabled,
                'size' => $package['size'] ?: null,
            ],
        );
    }

    /** @param  callable(string): string  $change */
    private function writeMapcycle(callable $change): void
    {
        if ($this->mapgroups()) {
            $this->writeMapgroup($change);

            return;
        }

        $path = $this->gameDir() . ($this->source1() ? '/cfg/mapcycle.txt' : '/mapcycle.txt');
        // CS:S comes with only cfg/mapcycle_default.txt and reads it while there is no mapcycle.txt - that is the start
        $cycle = $this->files->read($path) ?? ($this->source1()
            // without its "do not modify this file" header
            ? preg_replace('~^\s*//.*(\R|\z)~m', '', (string) $this->files->read($this->gameDir() . '/cfg/mapcycle_default.txt'))
            : null);

        $this->files->write($path, $change((string) $cycle));
    }

    /** CS:GO: started with +mapgroup, the rotation is a mapgroup and mapcycle.txt is not read. */
    public function mapgroups(): bool
    {
        return $this->source1() && basename($this->gameDir()) === 'csgo';
    }

    /**
     * The mapgroup mg_modmanager in gamemodes_server.txt, changed like mapcycle.txt: one map a
     * line. It starts with the maps of mg_active; the server's MAPGROUP is set to it.
     *
     * @param  callable(string): string  $change
     */
    private function writeMapgroup(callable $change): void
    {
        $path = $this->gameDir() . '/gamemodes_server.txt';
        $text = (string) $this->files->read($path);
        $maps = self::mapgroupMaps($text, self::MAPGROUP)
            ?? self::mapgroupMaps((string) $this->files->read($this->gameDir() . '/gamemodes.txt'), 'mg_active')
            ?? [];
        $changed = trim($change($maps === [] ? '' : implode("\n", $maps) . "\n"));

        $this->files->write($path, self::withMapgroup($text, $changed === '' ? [] : array_map('trim', explode("\n", $changed))));
        $this->setVariable('MAPGROUP', self::MAPGROUP);
    }

    /**
     * The maps of a mapgroup in a gamemodes*.txt, null when the group is not there.
     *
     * @return string[]|null
     */
    public static function mapgroupMaps(string $text, string $group): ?array
    {
        if (!preg_match('/"' . preg_quote($group, '/') . '"\s*\{[^{}]*"maps"\s*\{([^{}]*)\}/', $text, $match)) {
            return null;
        }

        preg_match_all('/"([^"]+)"\s*"[^"]*"/', $match[1], $maps);

        return $maps[1];
    }

    /**
     * gamemodes_server.txt with the mapgroup mg_modmanager holding $maps - replaced if there, else
     * added to "mapgroups"; the rest of the file stays as it is.
     *
     * @param  string[]  $maps
     */
    public static function withMapgroup(string $text, array $maps): string
    {
        $group = self::MAPGROUP;
        $block = "\t\t\"$group\"\n\t\t{\n\t\t\t\"name\"\t\"$group\"\n\t\t\t\"maps\"\n\t\t\t{\n"
            . implode('', array_map(fn (string $map) => "\t\t\t\t\"$map\"\t\"\"\n", $maps))
            . "\t\t\t}\n\t\t}\n";

        $pattern = '/[ \t]*"' . $group . '"\s*\{[^{}]*"maps"\s*\{[^{}]*\}[^{}]*\}[ \t]*\R?/';

        if (preg_match($pattern, $text)) {
            return (string) preg_replace_callback($pattern, fn () => $block, $text, 1);
        }

        if (preg_match('/"mapgroups"\s*\{[ \t]*\R?/', $text, $match, PREG_OFFSET_CAPTURE)) {
            $at = $match[0][1] + strlen($match[0][0]);

            return substr($text, 0, $at) . $block . substr($text, $at);
        }

        $mapgroups = "\t\"mapgroups\"\n\t{\n$block\t}\n";

        // a file without mapgroups: into its outer braces; no file: a new one
        return ($at = strrpos($text, '}')) !== false
            ? substr($text, 0, $at) . $mapgroups . substr($text, $at)
            : "\"GameModes_Server.txt\"\n{\n$mapgroups}\n";
    }

    /** A startup variable of the server set, when the egg has it. */
    private function setVariable(string $name, string $value): void
    {
        $variable = $this->server->egg->variables()->where('env_variable', $name)->first();

        if ($variable) {
            $this->server->serverVariables()->updateOrCreate(['variable_id' => $variable->id], ['variable_value' => $value]);
        }
    }

    /**
     * The maps among these files, by name: the .bsp files in the maps folder.
     *
     * @param  string[]  $files
     * @return string[]
     */
    public static function mapNames(array $files): array
    {
        return array_values(array_map(
            fn (string $path) => pathinfo($path, PATHINFO_FILENAME),
            array_filter($files, fn (string $path) => (bool) preg_match('#(^|/)maps/[^/]+\.bsp$#i', $path)),
        ));
    }

    /**
     * mapcycle.txt with these maps in it (appended, unless there) or out of it. Other lines stay.
     *
     * @param  string[]  $maps
     */
    public static function withMaps(string $cycle, array $maps, bool $in): string
    {
        $lines = $cycle === '' ? [] : preg_split('/\r?\n/', rtrim($cycle, "\r\n"));
        $eol = str_contains($cycle, "\r\n") ? "\r\n" : "\n";

        if ($in) {
            $there = array_map('trim', $lines);
            $lines = [...$lines, ...array_values(array_filter($maps, fn (string $map) => !in_array($map, $there, true)))];
        } else {
            $lines = array_values(array_filter($lines, fn (string $line) => !in_array(trim($line), $maps, true)));
        }

        return $lines === [] ? '' : implode($eol, $lines) . $eol;
    }

    /**
     * Where a file of a map archive goes, relative to the game folder - null to leave it out.
     * A game folder (maps/, sound/, gfx/, ...) counts from wherever it sits in the archive; loose
     * files go by their type. Readmes, sources and the like stay out.
     */
    public static function mapPlacement(string $path): ?string
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        $lower = array_map('strtolower', $parts);
        $file = end($parts);
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        foreach (['maps', 'materials', 'sound', 'models', 'sprites', 'gfx', 'overviews', 'resource'] as $folder) {
            // the folder in lower case - Linux tells Maps/ from maps/, the game only looks in the latter
            if (($index = array_search($folder, $lower, true)) !== false && $index < count($parts) - 1) {
                return implode('/', [$folder, ...array_slice($parts, $index + 1)]);
            }
        }

        return match ($extension) {
            'bsp', 'res', 'nav' => "maps/$file",
            'wad' => $file,
            'mdl' => "models/$file",
            'wav', 'mp3' => "sound/$file",
            'spr' => "sprites/$file",
            // loose skies: <name>up.tga, <name>dn.tga, ...
            'tga' => "gfx/env/$file",
            default => null,
        };
    }

    /** @return string[] the files every other install of this server has */
    private function filesOfOthers(string $package): array
    {
        return $this->installs()->where('package', '!=', $package)->pluck('files')->flatten()->all();
    }

    /** @return string[] the plugin files of an upload, by name */
    private function amxxFilesOf(ModInstall $install): array
    {
        return array_values(array_map('basename', array_filter($install->files, fn (string $path) => str_ends_with($path, '.amxx'))));
    }

    /** A config a plugin brought along: in the config folder, on SourceMod also below cfg/ (cfg/sourcemod/...). */
    private function pluginConfig(string $path): bool
    {
        return $this->inConfigDir($path) || ($this->source1() && str_starts_with($path, $this->gameDir() . '/cfg/'));
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

    /** A map with files in the game folder and a place in the rotation: from GameBanana or uploaded. */
    public static function mapPackage(string $package): bool
    {
        return str_starts_with($package, GameBanana::PREFIX) || str_starts_with($package, self::MAP_UPLOAD);
    }

    /** An upload, or an AMX Mod X / SourceMod plugin from GitHub: on GoldSrc its lines in plugins-modmanager.ini, its configs kept. */
    private function ownPlugin(string $package): bool
    {
        return self::uploaded($package) || (($this->goldsrc() || $this->source1()) && str_starts_with($package, GitHub::PREFIX));
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

    /** A loader made of archives into the game folder instead of a package of a site. */
    private function archiveLoader(): bool
    {
        return in_array($this->profile->setting('loader_type'), Presets::ARCHIVE_LOADERS, true);
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
            // the game keeps workshop maps up to date itself
            if (str_starts_with($install->package, Workshop::PREFIX)) {
                continue;
            }

            try {
                // before 0.31 a GameBanana file's name stood for its version, now the day it was added
                if (str_starts_with($install->package, GameBanana::PREFIX) && !preg_match('/^\d{4}-\d\d-\d\d\z/', $install->version)) {
                    $install->update(['version' => $this->sourceFor($install->package)->release($install->package, $install->version)['version']]);
                }

                $latest = $this->sourceFor($install->package)->latestVersion($install->package, $this->gameVersion());
            } catch (\Throwable) {
                continue;
            }

            // thunderstore numbers are plain semver, plugin versions are anything ("v5.5.71-bukkit"):
            // there the newest for the game version simply differs from what is installed
            if (self::jarPackage($install->package) || in_array($install->package, [GoldSrc::PACKAGE, GoldSrc::ENGINE, Source1::PACKAGE, Source2::PACKAGE], true) || str_starts_with($install->package, GitHub::PREFIX) ? $latest !== $install->version : version_compare($latest, $install->version, '>')) {
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

    /**
     * What sits in the mods folder without the plugin having put it there - by hand, or with
     * r2modman. A folder named like a package ("Namespace-Name") can be taken over.
     *
     * @return array<int, array{path: string, name: string, version: ?string, package: ?string}>
     */
    public function unmanaged(): array
    {
        $known = $this->installs()->pluck('files')->flatten()->all();

        if ($this->goldsrc()) {
            return $this->unmanagedAmxx($known);
        }

        // SourceMod's own plugins and those put there by hand have their own section (amxxPlugins)
        if ($this->source1()) {
            return [];
        }

        if ($this->jarMods()) {
            return $this->unmanagedJars($known);
        }

        $found = [];

        foreach ($this->files->list($this->modsDir()) as $name => $isDirectory) {
            $path = $this->modsDir() . "/$name";

            if (in_array($path, $known, true)) {
                continue;
            }

            // CounterStrikeSharp's own folder for plugins switched off by hand
            if ($this->source2()) {
                if ($isDirectory && $name !== 'disabled') {
                    $found[] = ['path' => $path, 'name' => $name, 'version' => null, 'package' => self::UPLOAD . $name];
                }

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
     * Compiled plugins in AMX Mod X's plugins folder that no plugins*.ini names - put there by
     * hand and never loaded. Those an ini names are AMX Mod X's own or registered by hand, the
     * section of plugins.ini shows them.
     *
     * @param  string[]  $known  paths of what is installed through the plugin
     * @return array<int, array{path: string, name: string, version: ?string, package: ?string}>
     */
    private function unmanagedAmxx(array $known): array
    {
        $registered = [];

        foreach (array_keys(array_filter($this->files->list($this->configDir()), fn (bool $isDirectory) => !$isDirectory)) as $file) {
            if (preg_match('/^plugins(-.+)?\.ini$/i', $file)) {
                $registered = [...$registered, ...array_column(self::parseAmxxPlugins((string) $this->files->read($this->configDir() . "/$file")), 'file')];
            }
        }

        $found = [];

        foreach ($this->files->list($this->modsDir()) as $name => $isDirectory) {
            $path = $this->modsDir() . "/$name";

            if ($isDirectory || !str_ends_with(strtolower($name), '.amxx') || in_array($name, $registered, true) || in_array($path, $known, true)) {
                continue;
            }

            $found[] = [
                'path' => $path,
                'name' => substr($name, 0, -5),
                'version' => null,
                // a name that cannot go into an ini line cannot be taken over
                'package' => preg_match('/^\w[\w.-]*\.amxx\z/i', $name) ? self::UPLOAD . substr($name, 0, -5) : null,
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

        if ($this->source2()) {
            return ModInstall::query()->create([
                'server_id' => $this->server->id,
                'package' => $entry['package'],
                'name' => $entry['name'],
                'version' => '-',
                'icon' => null,
                'dependency' => false,
                'requires' => [],
                'files' => [$path],
                'size' => $this->files->usage($path),
            ]);
        }

        // registered in plugins-modmanager.ini like an upload, switched on
        if ($this->goldsrc()) {
            $this->writeUploadedIni(fn (string $ini) => self::withAmxxPlugin(self::withAmxxLine($ini, basename($path)), basename($path), true));

            return ModInstall::query()->create([
                'server_id' => $this->server->id,
                'package' => $entry['package'],
                'name' => $entry['name'],
                'version' => '-',
                'icon' => null,
                'dependency' => false,
                'requires' => [],
                'files' => [$path],
                'size' => $this->files->size($path),
            ]);
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

    private function sourceFor(string $package): Thunderstore|Modrinth|Hangar|GoldSrc|Source1|Source2|GitHub|GameBanana
    {
        return match (true) {
            $package === GoldSrc::PACKAGE, $package === GoldSrc::ENGINE => new GoldSrc(),
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
