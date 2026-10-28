<?php

namespace Marschux\ModManager\Profiles;

/**
 * The games that ship with the plugin, offered as templates in the admin form. A preset only
 * fills the form; nothing is switched on until a profile is linked to an egg.
 *
 * Settings of a profile:
 * - sources: where mods come from (thunderstore, modrinth, hangar, gamebanana), the first one is opened first
 * - community: the game on Thunderstore, e.g. "valheim"
 * - modrinth_loaders: a Modrinth project needs one of these, e.g. paper
 * - gamebanana_game / gamebanana_category: the game and the category on GameBanana, by number
 * - mods_dir / config_dir: where mods and their configs live on the server
 * - loader_type: thunderstore (a package of the site, the default) or goldsrc (Metamod-R and
 *   AMX Mod X, hooked in through liblist.gam)
 * - loader_name / loader_package / loader_check: the mod loader, its package on the site and a
 *   path that exists once it is installed
 * - game_dir: the mod folder of a GoldSrc game, e.g. cstrike
 * - start_prefix: put in front of the server's start command so the loader gets loaded
 * - hidden_packages: never offered in the browser and never installed as a dependency
 * - version_file / version_variable: where the server's game version can be read, see GameVersion
 */
class Presets
{
    public const SOURCES = ['thunderstore' => 'Thunderstore', 'modrinth' => 'Modrinth', 'hangar' => 'Hangar', 'gamebanana' => 'GameBanana', 'workshop' => 'Steam Workshop'];

    public const LOADER_TYPES = ['thunderstore' => 'Thunderstore', 'goldsrc' => 'Metamod-R + AMX Mod X', 'source2' => 'Metamod:Source + CounterStrikeSharp'];

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
                    // plugins for Spigot and Bukkit run on Paper too
                    'modrinth_loaders' => ['paper', 'spigot', 'bukkit'],
                    // a plugin is one jar in here, its data folder plugins/<Name> sits next to it
                    'mods_dir' => 'plugins',
                    // older Paper wrote it next to server.jar, those fall back to PAPER_VERSION
                    'version_file' => '.paper/version_history.json',
                    'version_variable' => 'PAPER_VERSION',
                    'hidden_packages' => [],
                ],
            ],
            'counter-strike-16' => [
                'name' => 'Counter-Strike 1.6',
                'settings' => [
                    // maps; AMX Mod X plugins have no site to browse, they are uploaded
                    'sources' => ['gamebanana'],
                    // Counter-Strike 1.6, its category Maps
                    'gamebanana_game' => 4254,
                    'gamebanana_category' => 5474,
                    'game_dir' => 'cstrike',
                    'mods_dir' => 'cstrike/addons/amxmodx/plugins',
                    'config_dir' => 'cstrike/addons/amxmodx/configs',
                    'loader_type' => 'goldsrc',
                    'loader_name' => 'Metamod-R + AMX Mod X',
                    // Sources\GoldSrc::PACKAGE
                    'loader_package' => 'goldsrc:loader',
                    'loader_check' => 'cstrike/addons/metamod/metamod.so',
                    // what liblist.gam loads again once Metamod is removed
                    'game_dll' => 'dlls/cs.so',
                    'hidden_packages' => ['goldsrc:loader'],
                ],
            ],
            // the same as 1.6 in its own game folder; AMX Mod X's cstrike addon covers both
            'counter-strike-cz' => [
                'name' => 'Counter-Strike: Condition Zero',
                'settings' => [
                    'sources' => ['gamebanana'],
                    // Counter-Strike: Condition Zero, its category Maps
                    'gamebanana_game' => 7,
                    'gamebanana_category' => 5181,
                    'game_dir' => 'czero',
                    'mods_dir' => 'czero/addons/amxmodx/plugins',
                    'config_dir' => 'czero/addons/amxmodx/configs',
                    'loader_type' => 'goldsrc',
                    'loader_name' => 'Metamod-R + AMX Mod X',
                    'loader_package' => 'goldsrc:loader',
                    'loader_check' => 'czero/addons/metamod/metamod.so',
                    'game_dll' => 'dlls/cs.so',
                    'hidden_packages' => ['goldsrc:loader'],
                ],
            ],
            'counter-strike-2' => [
                'name' => 'Counter-Strike 2',
                'settings' => [
                    // maps; plugins are uploaded or come from a GitHub release. Browsing the
                    // workshop needs a Steam Web API key (steam_api_key), without one maps go in by id
                    'sources' => ['workshop'],
                    'steam_api_key' => '',
                    'game_dir' => 'game/csgo',
                    'mods_dir' => 'game/csgo/addons/counterstrikesharp/plugins',
                    'config_dir' => 'game/csgo/addons/counterstrikesharp/configs',
                    'loader_type' => 'source2',
                    'loader_name' => 'Metamod:Source + CounterStrikeSharp',
                    // Sources\Source2::PACKAGE
                    'loader_package' => 'cs2:loader',
                    'loader_check' => 'game/csgo/addons/metamod/bin/linuxsteamrt64/metamod.2.cs2.so',
                    'hidden_packages' => ['cs2:loader'],
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
