<?php

namespace Marschux\GameManager\Concerns;

use Marschux\GameManager\Models\ModInstall;
use RuntimeException;

/** Counter-Strike 2: CounterStrikeSharp plugins. Part of Installer. */
trait HandlesCounterStrikeSharp
{
    /**
     * A CounterStrikeSharp plugin from a zip - uploaded, or a GitHub release's asset - or a .dll on
     * its own: sorted into the game folder (see cssPlacement), each plugin folder tracked whole.
     * A plugin folder that is there and not this package's stops it; configs already there stay.
     */
    private function installCssArchive(string $name, string $scratch, string $package, string $version): ModInstall
    {
        if (!preg_match('/^\w[\w.+-]*\.(zip|dll)\z/i', $name)) {
            throw new RuntimeException(trans('game-manager::mods.messages.bad_css', ['file' => $name]));
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
                throw new RuntimeException(trans('game-manager::mods.messages.no_css_plugin', ['file' => $name]));
            }

            foreach ($plugins as $folder) {
                if (!in_array($folder, $ours, true) && $this->files->exists($folder)) {
                    throw new RuntimeException(trans('game-manager::mods.messages.exists', ['file' => basename($folder)]));
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
}
