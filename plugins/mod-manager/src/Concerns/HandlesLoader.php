<?php

namespace Marschux\ModManager\Concerns;

use Marschux\ModManager\Models\ModInstall;
use Marschux\ModManager\Profiles\Presets;
use Marschux\ModManager\Sources\GoldSrc;
use Marschux\ModManager\Sources\Source1;
use Marschux\ModManager\Sources\Source2;
use RuntimeException;

/** The mod loader: installing, updating and removing it, and whether the server runs it. Part of Installer. */
trait HandlesLoader
{
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

        $package = $this->source->release($this->profile->loaderPackage(), $version);
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
        $loader = $this->installs()->firstWhere('package', $this->profile->loaderPackage());

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

    /** Only this server's start command changes, the egg stays as it is. */
    public function applyStartPrefix(): void
    {
        $prefix = trim((string) $this->profile->setting('start_prefix'));

        if ($prefix !== '' && !str_contains((string) $this->server->startup, $prefix)) {
            $this->server->update(['startup' => "$prefix " . $this->server->startup]);
        }
    }

    /** A loader made of archives into the game folder instead of a package of a site. */
    private function archiveLoader(): bool
    {
        return in_array($this->profile->setting('loader_type'), Presets::ARCHIVE_LOADERS, true);
    }
}
