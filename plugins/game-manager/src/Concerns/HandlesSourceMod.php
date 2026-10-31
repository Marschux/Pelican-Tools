<?php

namespace Marschux\GameManager\Concerns;

use Marschux\GameManager\Sources\Source1;

/** Counter-Strike: Source and Global Offensive: SourceMod plugins and their switch. Part of Installer. */
trait HandlesSourceMod
{
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
}
