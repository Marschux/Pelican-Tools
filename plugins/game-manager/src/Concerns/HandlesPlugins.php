<?php

namespace Marschux\GameManager\Concerns;

use Marschux\GameManager\Models\ModInstall;
use Marschux\GameManager\Sources\GitHub;
use RuntimeException;

/** Plugins uploaded or from GitHub, and the loader's own plugins (AMX Mod X, SourceMod). Part of Installer. */
trait HandlesPlugins
{
    /**
     * The plugins AMX Mod X lists in its plugins.ini - those it comes with, and any put there by
     * hand - with whether the file is there at all.
     *
     * @return array<int, array{file: string, enabled: bool, note: string, group: string, exists: bool}>
     */
    public function loaderPlugins(): array
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
    public function setLoaderPlugin(string $file, bool $enabled): void
    {
        if ($this->source1()) {
            // SourceMod loads every .smx in plugins/ and ignores plugins/disabled/
            if (!collect($this->sourcemodPlugins())->contains(fn (array $plugin) => $plugin['file'] === $file && $plugin['enabled'] !== $enabled)) {
                throw new RuntimeException(trans('game-manager::mods.messages.not_adoptable'));
            }

            $on = $this->modsDir() . "/$file";
            $off = $this->modsDir() . "/disabled/$file";
            $this->files->move([$enabled ? ['from' => $off, 'to' => $on] : ['from' => $on, 'to' => $off]]);

            return;
        }

        $path = $this->configDir() . '/plugins.ini';
        $ini = (string) $this->files->read($path);

        if (!in_array($file, array_column(self::parseAmxxPlugins($ini), 'file'), true)) {
            throw new RuntimeException(trans('game-manager::mods.messages.not_adoptable'));
        }

        $this->files->write($path, self::withAmxxPlugin($ini, $file, $enabled));
    }

    /**
     * A plugin of plugins.ini gone: its file and its line. One AMX Mod X comes with is back as a
     * file after the next loader update - without a line, so it is not loaded.
     */
    public function deleteLoaderPlugin(string $file): void
    {
        if ($this->source1()) {
            $plugin = collect($this->sourcemodPlugins())->firstWhere('file', $file)
                ?? throw new RuntimeException(trans('game-manager::mods.messages.not_adoptable'));

            // one SourceMod comes with is back after the next loader update, switched on
            $this->files->delete([$this->modsDir() . ($plugin['enabled'] ? '' : '/disabled') . "/$file"]);

            return;
        }

        $path = $this->configDir() . '/plugins.ini';
        $ini = (string) $this->files->read($path);

        if (!in_array($file, array_column(self::parseAmxxPlugins($ini), 'file'), true)) {
            throw new RuntimeException(trans('game-manager::mods.messages.not_adoptable'));
        }

        $this->files->delete([$this->modsDir() . '/' . self::amxxName($file)]);
        $this->files->write($path, self::withoutAmxxPlugin($ini, $file));
    }

    /**
     * An upload the browser sent straight to Wings, into UPLOADS/<$token>/<$name>: a map, as a .bsp
     * or an archive with one. Plugins are not uploaded - they come in too many shapes to place them
     * on their own. The token's folder goes afterwards.
     */
    public function installUpload(string $token, string $name): ModInstall
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new RuntimeException(trans('game-manager::mods.messages.bad_amxx', ['file' => $name]));
        }

        $directory = self::UPLOADS . "/$token";

        try {
            $lower = strtolower($name);

            // Counter-Strike 2's maps come from the workshop, not as a .bsp
            if ($this->source2() || !preg_match('/\.(bsp|zip|rar|7z)\z/', $lower)) {
                throw new RuntimeException(trans('game-manager::mods.messages.no_map', ['file' => $name]));
            }

            if (!self::plainFileName($name)) {
                throw new RuntimeException(trans('game-manager::mods.messages.bad_file', ['file' => $name]));
            }

            $size = $this->files->size("$directory/$name");

            if (!str_ends_with($lower, '.bsp')) {
                $this->files->decompress($directory, $name);
                $this->files->delete(["$directory/$name"]);
            }

            if (!array_filter($this->files->walk($directory), fn (string $path) => str_ends_with(strtolower($path), '.bsp'))) {
                throw new RuntimeException(trans('game-manager::mods.messages.no_map', ['file' => $name]));
            }

            $title = pathinfo($name, PATHINFO_FILENAME);

            return $this->placeMap(['id' => self::MAP_UPLOAD . $title, 'name' => $title, 'version' => now()->format('Y-m-d'), 'icon' => null, 'size' => $size], $directory, $name);
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
            throw new RuntimeException(trans($sourcemod ? 'game-manager::mods.messages.bad_smx' : 'game-manager::mods.messages.bad_amxx', ['file' => $name]));
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

                throw new RuntimeException(trans("game-manager::mods.messages.$message", ['file' => $name]));
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
                        throw new RuntimeException(trans('game-manager::mods.messages.exists', ['file' => basename($to)]));
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

    /** A config a plugin brought along: in the config folder, on SourceMod also below cfg/ (cfg/sourcemod/...). */
    private function pluginConfig(string $path): bool
    {
        return $this->inConfigDir($path) || ($this->source1() && str_starts_with($path, $this->gameDir() . '/cfg/'));
    }

    private function inConfigDir(string $path): bool
    {
        return $this->configDir() !== '' && str_starts_with($path, $this->configDir() . '/');
    }

    /** A plugin uploaded through the page, see installAmxx(). */
    public static function uploaded(string $package): bool
    {
        return str_starts_with($package, self::UPLOAD);
    }

    /** An upload, or an AMX Mod X / SourceMod plugin from GitHub: on GoldSrc its lines in plugins-modmanager.ini, its configs kept. */
    private function ownPlugin(string $package): bool
    {
        return self::uploaded($package) || (($this->goldsrc() || $this->source1()) && str_starts_with($package, GitHub::PREFIX));
    }
}
