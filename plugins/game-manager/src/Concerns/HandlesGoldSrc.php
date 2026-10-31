<?php

namespace Marschux\GameManager\Concerns;

use Marschux\GameManager\Models\ModInstall;
use Marschux\GameManager\Sources\GoldSrc;
use RuntimeException;

/** Counter-Strike 1.6 and Condition Zero: AMX Mod X, its plugins.ini, liblist.gam and the ReHLDS engine. Part of Installer. */
trait HandlesGoldSrc
{
    /**
     * ReHLDS, ReGameDLL and ReAPI over Valve's files: what is there of Valve's moves to
     * .game-manager/valve first and comes back on removal. A config already there (ReGameDLL's
     * game.cfg) is kept through an update. Running binaries can be replaced - the server picks
     * them up on its next start.
     */
    public function installEngine(): ModInstall
    {
        $release = (new GoldSrc())->engineRelease();
        $game = $this->gameDir();
        $scratch = '.game-manager/engine';
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
     * YaPB into the game folder's addons/yapb and into Metamod's plugins.ini. Its configs
     * already there are kept through an update, the waypoints it worked out stay too.
     */
    public function installBots(): ModInstall
    {
        $release = (new GoldSrc())->botsRelease();
        $game = $this->gameDir();
        $scratch = '.game-manager/bots';
        $ours = $this->installs()->firstWhere('package', GoldSrc::BOTS)?->files ?? [];
        $moves = [];
        $kept = [];

        $this->files->delete($this->files->exists($scratch) ? [$scratch] : []);

        try {
            $url = $release['parts'][0]['url'];
            $file = basename((string) parse_url($url, PHP_URL_PATH));

            $this->files->pull($url, $scratch, $file);
            $this->files->decompress($scratch, $file);

            foreach ($this->files->walk("$scratch/addons/yapb") as $path) {
                $to = "$game/addons/yapb/$path";

                if (in_array(substr($path, strlen('data/')), self::YAPB_FOLDERS, true)) {
                    continue;
                }

                if (in_array($to, $ours, true) && self::keptOnUpdate($to)) {
                    $kept[] = $to;

                    continue;
                }

                $moves[] = ['from' => "$scratch/addons/yapb/$path", 'to' => $to];
            }

            // Wings will not move onto a file
            $this->files->delete(array_values(array_intersect($ours, array_column($moves, 'to'))));
            $this->files->move($moves);
        } finally {
            $this->files->delete([$scratch]);
        }

        // where YaPB writes its logs and what it learns: empty folders in the archive, which Wings
        // unpacks as empty files - one left by an earlier install makes way
        $data = $this->files->list("$game/addons/yapb/data");
        $this->files->delete(array_map(fn (string $folder) => "$game/addons/yapb/data/$folder", array_keys(array_filter($data, fn (bool $isDirectory, string $name) => !$isDirectory && in_array($name, self::YAPB_FOLDERS, true), ARRAY_FILTER_USE_BOTH))));

        foreach (self::YAPB_FOLDERS as $folder) {
            if (!($data[$folder] ?? false)) {
                $this->files->mkdir("$game/addons/yapb/data/$folder");
            }
        }

        $plugins = "$game/addons/metamod/plugins.ini";
        $this->files->write($plugins, self::withMetamodPlugin((string) $this->files->read($plugins), self::YAPB_DLL));

        return ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => GoldSrc::BOTS],
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

    /** YaPB gone: its whole folder, with what it wrote, and its line in plugins.ini. */
    public function uninstallBots(): void
    {
        $game = $this->gameDir();
        $plugins = "$game/addons/metamod/plugins.ini";

        $this->files->write($plugins, self::withoutMetamodPlugin((string) $this->files->read($plugins), self::YAPB_DLL));
        $this->files->delete($this->files->exists("$game/addons/yapb") ? ["$game/addons/yapb"] : []);

        $this->installs()->firstWhere('package', GoldSrc::BOTS)?->delete();
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

    /** Metamod's plugins.ini without the lines loading $dll. */
    public static function withoutMetamodPlugin(string $ini, string $dll): string
    {
        $lines = array_filter(preg_split('/\r?\n/', $ini), fn (string $line) => !preg_match('/^\s*linux\s+(\S+)/', $line, $match) || $match[1] !== $dll);

        return implode("\n", $lines);
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
                throw new RuntimeException(trans('game-manager::mods.messages.exists', ['file' => $name]));
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
            throw new RuntimeException(trans('game-manager::mods.messages.bad_amxx', ['file' => $file]));
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
}
