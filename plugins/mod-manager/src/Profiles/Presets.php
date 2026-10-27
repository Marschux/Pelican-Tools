<?php

namespace Marschux\ModManager\Profiles;

/**
 * The games that ship with the plugin, offered as templates in the admin form. A preset only
 * fills the form; nothing is switched on until a profile is linked to an egg.
 *
 * Settings of a profile:
 * - sources: where mods come from (thunderstore, modrinth, hangar), the first one is opened first
 * - community: the game on Thunderstore, e.g. "valheim"
 * - mods_dir / config_dir: where mods and their configs live on the server
 * - loader_name / loader_package / loader_check: the mod loader, its package on the site and a
 *   path that exists once it is installed
 * - start_prefix: put in front of the server's start command so the loader gets loaded
 * - hidden_packages: never offered in the browser and never installed as a dependency
 * - version_file / version_variable: where the server's game version can be read, see GameVersion
 */
class Presets
{
    public const SOURCES = ['thunderstore' => 'Thunderstore', 'modrinth' => 'Modrinth', 'hangar' => 'Hangar'];

    /** @return array<string, array{name: string, settings: array<string, mixed>}> */
    public static function all(): array
    {
        return [
            'valheim' => [
                'name' => 'Valheim',
                'settings' => [
                    'sources' => ['thunderstore'],
                    'community' => 'valheim',
                    'mods_dir' => 'BepInEx/plugins',
                    'config_dir' => 'BepInEx/config',
                    'loader_name' => 'BepInEx',
                    'loader_package' => 'denikson-BepInExPack_Valheim',
                    'loader_check' => 'BepInEx/core',
                    'start_prefix' => 'export DOORSTOP_ENABLED=1 DOORSTOP_TARGET_ASSEMBLY=./BepInEx/core/BepInEx.Preloader.dll LD_LIBRARY_PATH="./doorstop_libs:${LD_LIBRARY_PATH}" LD_PRELOAD="libdoorstop_x64.so:${LD_PRELOAD}";',
                    // the loader comes in through its own button, r2modman is the desktop client
                    'hidden_packages' => ['denikson-BepInExPack_Valheim', 'ebkr-r2modman'],
                ],
            ],
            'minecraft-paper' => [
                'name' => 'Minecraft Paper',
                'settings' => [
                    'sources' => ['modrinth', 'hangar'],
                    // a plugin is one jar in here, its data folder plugins/<Name> sits next to it
                    'mods_dir' => 'plugins',
                    'version_file' => 'version_history.json',
                    'version_variable' => 'PAPER_VERSION',
                    'hidden_packages' => [],
                ],
            ],
        ];
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_map(fn (array $preset) => $preset['name'], self::all());
    }
}
