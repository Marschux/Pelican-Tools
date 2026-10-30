<?php

/**
 * Self-check for what the plugin decides on its own. Run with: php test_mod_manager.php
 *
 * No panel, no daemon, no network.
 */

require __DIR__ . '/src/Profiles/Presets.php';
require __DIR__ . '/src/Lists/ListDefinition.php';
require __DIR__ . '/src/Lists/Presets.php';
require __DIR__ . '/src/Lists/ListFile.php';
require __DIR__ . '/src/Lists/MinecraftUuid.php';
require __DIR__ . '/src/Sources/Thunderstore.php';
require __DIR__ . '/src/Sources/Modrinth.php';
require __DIR__ . '/src/Sources/Hangar.php';
require __DIR__ . '/src/Sources/GameBanana.php';
require __DIR__ . '/src/Sources/GitHub.php';
require __DIR__ . '/src/Sources/Workshop.php';
foreach (glob(__DIR__ . '/src/Concerns/*.php') as $concern) {
    require $concern;
}
require __DIR__ . '/src/Installer.php';
require __DIR__ . '/src/GameVersion.php';

use Marschux\ModManager\Lists\ListDefinition;
use Marschux\ModManager\Lists\ListFile;
use Marschux\ModManager\Lists\MinecraftUuid;
use Marschux\ModManager\Lists\Presets as ListPresets;
use Marschux\ModManager\GameVersion;
use Marschux\ModManager\Installer;
use Marschux\ModManager\Profiles\Presets;
use Marschux\ModManager\Sources\Thunderstore;

// --- presets -------------------------------------------------------------------------------------

foreach (Presets::all() as $key => $preset) {
    $settings = $preset['settings'];

    assert(Presets::options()[$key] === $preset['name']);
    // Counter-Strike 2 has no site to browse yet: plugins are uploaded
    assert(($settings['sources'] !== [] || $settings['loader_type'] === 'source2') && array_diff($settings['sources'], array_keys(Presets::SOURCES)) === [], "$key: unknown source");
    assert(($settings['mods_dir'] ?? '') !== '', "$key: mods_dir is needed");

    if (in_array('thunderstore', $settings['sources'], true)) {
        assert(($settings['community'] ?? '') !== '', "$key: thunderstore needs a community");
    }

    if (in_array('modrinth', $settings['sources'], true)) {
        assert(($settings['modrinth_loaders'] ?? []) !== [], "$key: modrinth needs loaders");
    }

    // the loader is installed through its own button, so the browser never offers it
    if (isset($settings['loader_package'])) {
        assert(($settings['loader_check'] ?? '') !== '', "$key: a loader needs a path to check");
        assert(in_array($settings['loader_package'], $settings['hidden_packages'], true), "$key: the loader has to be hidden");
    }
    if (in_array($settings['loader_type'] ?? 'thunderstore', ['goldsrc', 'source2'], true)) {
        assert(($settings['game_dir'] ?? '') !== '' && str_starts_with($settings['mods_dir'], $settings['game_dir'] . '/'), "$key: goldsrc mods live in the game folder");
        assert(str_starts_with($settings['loader_check'] ?? '', $settings['game_dir'] . '/'), "$key: goldsrc needs a check path in the game folder");
    }
    assert(!str_starts_with($settings['mods_dir'], '/') && !str_contains($settings['mods_dir'], '..'), "$key: mods_dir stays inside the server");
}

// --- modrinth ids -------------------------------------------------------------------------------

assert(Marschux\ModManager\Sources\Modrinth::split('modrinth:hXiIvTyT') === 'hXiIvTyT');
assert(Marschux\ModManager\Sources\Modrinth::split('modrinth:luckperms-gui') === 'luckperms-gui');

foreach (['hXiIvTyT', 'modrinth:', 'modrinth:../x', 'thunderstore:a-b'] as $bad) {
    try {
        Marschux\ModManager\Sources\Modrinth::split($bad);
        assert(false, "$bad should not pass");
    } catch (RuntimeException) {
    }
}

use Marschux\ModManager\Sources\Modrinth;

assert(Modrinth::split('modrinth-datapack:abc') === 'abc' && Modrinth::split('modrinth-resourcepack:x-y') === 'x-y');
assert(Modrinth::kindOf('modrinth:a') === 'plugin' && Modrinth::kindOf('modrinth-datapack:a') === 'datapack' && Modrinth::kindOf('modrinth-resourcepack:a') === 'resourcepack');
assert(Modrinth::kindOf('hangar:a') === null && Modrinth::kindOf('Foo-Bar') === null);
assert(Installer::kind('modrinth-datapack:a') === 'datapack' && Installer::kind('hangar:a') === 'plugin' && Installer::kind('A-B') === 'plugin');
assert(Installer::jarPackage('modrinth-resourcepack:a'));

// --- goldsrc loader ------------------------------------------------------------------------------

$liblist = "game \"Counter-Strike\"\r\ngamedll \"dlls\\mp.dll\"\r\ngamedll_linux \"dlls/cs.so\"\r\ngamedll_osx \"dlls/cs.dylib\"\r\n";
assert(Installer::gameDll($liblist) === 'dlls/cs.so');
$hooked = Installer::withGameDll($liblist, 'addons/metamod/metamod_i386.so');
assert(Installer::gameDll($hooked) === 'addons/metamod/metamod_i386.so' && str_contains($hooked, "gamedll \"dlls\\mp.dll\"\r\n") && str_contains($hooked, 'gamedll_osx'));
assert(Installer::withGameDll($hooked, 'dlls/cs.so') === $liblist, 'unhooked again');
assert(Installer::gameDll(Installer::withGameDll("game \"x\"", 'a.so')) === 'a.so', 'line added');

$amxx = 'addons/amxmodx/dlls/amxmodx_mm_i386.so';
assert(Installer::withMetamodPlugin('', $amxx) === "linux $amxx\n");
assert(Installer::withMetamodPlugin("linux addons/x.so\n", $amxx) === "linux addons/x.so\nlinux $amxx\n");
assert(Installer::withMetamodPlugin("linux $amxx\r\n", $amxx) === "linux $amxx\r\n", 'there already');
assert(Installer::withMetamodPlugin("; linux $amxx\n", $amxx) === "; linux $amxx\nlinux $amxx\n", 'commented out is not there');

assert(Installer::goldsrcFile('addons/metamod/metamod_i386.so') && !Installer::goldsrcFile('addons/metamod/metamod.dll'));
assert(Installer::goldsrcFile('addons/amxmodx/plugins/admin.amxx') && !Installer::goldsrcFile('addons/amxmodx/scripting/admin.sma'));
assert(!Installer::goldsrcFile('example_plugin/Makefile'));
assert(Installer::loaderConfig('addons/counterstrikesharp/configs/core.json') && !Installer::loaderConfig('addons/counterstrikesharp/api/CounterStrikeSharp.API.dll'));
assert(Installer::loaderConfig('addons/sourcemod/configs/admins_simple.ini') && Installer::loaderConfig('cfg/sourcemod/sourcemod.cfg') && !Installer::loaderConfig('addons/sourcemod/plugins/basechat.smx'));
assert(Installer::goldsrcTarget('addons/metamod/metamod_i386.so') === 'addons/metamod/metamod.so', 'HLDS will not load a game library named _i386');
assert(Installer::goldsrcTarget('addons/amxmodx/dlls/amxmodx_mm_i386.so') === 'addons/amxmodx/dlls/amxmodx_mm_i386.so', 'metamod loads its plugins by any name');
foreach ([
    ['rehlds', 'bin/linux32/engine_i486.so', 'engine_i486.so'],
    ['rehlds', 'bin/linux32/valve/dlls/director.so', 'valve/dlls/director.so'],
    ['rehlds', 'bin/win32/engine.dll', null],
    ['rehlds', 'hlsdk/x.h', null],
    ['regamedll', 'bin/linux32/cstrike/dlls/cs.so', 'czero/dlls/cs.so'],
    ['regamedll', 'bin/linux32/cstrike/game.cfg', 'czero/game.cfg'],
    ['regamedll', 'bin/win32/cstrike/dlls/mp.dll', null],
    ['reapi', 'addons/amxmodx/modules/reapi_amxx_i386.so', 'czero/addons/amxmodx/modules/reapi_amxx_i386.so'],
    ['reapi', 'addons/amxmodx/modules/reapi_amxx.dll', null],
    ['reapi', 'addons/amxmodx/scripting/include/reapi.inc', null],
] as [$part, $from, $to]) {
    assert(Installer::enginePlacement($part, $from, 'czero') === $to, "$part $from");
}
assert(Installer::keptOnUpdate('addons/amxmodx/configs/users.ini') && Installer::keptOnUpdate('addons/amxmodx/configs/amxx.cfg'));
assert(!Installer::keptOnUpdate('addons/amxmodx/data/lang/admin.txt'));

$ini = "; AMX Mod X plugins\r\n\r\n; Admin Base - Always one has to be activated\r\nadmin.amxx\t\t; admin base (required for any admin-related)\r\n;admin_sql.amxx\t\t; admin base - SQL version (comment admin.amxx)\r\n\r\n; Basic\r\nadmincmd.amxx debug\r\n; telemenu.amxx\t\t; teleport menu\r\n\r\n; Custom - Add 3rd party plugins here\r\n";
$plugins = Installer::parseAmxxPlugins($ini);
assert(array_column($plugins, 'file') === ['admin.amxx', 'admin_sql.amxx', 'admincmd.amxx', 'telemenu.amxx']);
assert(array_column($plugins, 'enabled') === [true, false, true, false]);
assert($plugins[0]['note'] === 'admin base (required for any admin-related)' && $plugins[0]['group'] === 'Admin Base - Always one has to be activated');
assert($plugins[2]['group'] === 'Basic' && $plugins[2]['note'] === '', 'a flag is not a note');

$off = Installer::withAmxxPlugin($ini, 'admin.amxx', false);
assert(str_contains($off, ";admin.amxx\t\t; admin base (required for any admin-related)\r\n") && !Installer::parseAmxxPlugins($off)[0]['enabled']);
assert(Installer::withAmxxPlugin($off, 'admin.amxx', true) === $ini, 'back on as it was');
assert(Installer::parseAmxxPlugins(Installer::withAmxxPlugin($ini, 'telemenu.amxx', true))[3]['enabled']);
assert(Installer::withAmxxPlugin($ini, 'admin', false) === $ini, 'only a whole file name');
assert(str_contains($off, ";admin_sql.amxx\t\t; admin base - SQL version (comment admin.amxx)\r\n"), 'a note naming the file is not its line');

assert(Installer::amxxName('mm_test.amxx') === 'mm_test.amxx' && Installer::amxxName('Zombie.Plague-5.amxx') === 'Zombie.Plague-5.amxx');
foreach (['x.sma', '../x.amxx', 'a b.amxx', '.x.amxx', 'x.amxx/', "x.amxx\n"] as $bad) {
    try {
        Installer::amxxName($bad);
        assert(false, "$bad should not pass");
    } catch (Throwable $exception) {
        assert(!$exception instanceof AssertionError, $exception->getMessage());
    }
}
assert(Installer::withAmxxLine('', 'a.amxx') === "a.amxx\n");
assert(Installer::withAmxxLine("; head\n", 'a.amxx') === "; head\na.amxx\n");
assert(Installer::withAmxxLine(";a.amxx\n", 'a.amxx') === ";a.amxx\n", 'there already, switched off');
assert(Installer::withoutAmxxPlugin("; head\n;a.amxx\nb.amxx ; uses a.amxx\n", 'a.amxx') === "; head\nb.amxx ; uses a.amxx\n");
assert(Installer::withoutAmxxPlugin("; head\r\nb.amxx\r\na.amxx", 'a.amxx') === "; head\r\nb.amxx\r\n");
assert(Installer::uploaded('upload:x') && !Installer::uploaded('modrinth:x'));
assert(Installer::amxxCore('cstrike/addons/amxmodx/configs/plugins.ini') && Installer::amxxCore('x/users.ini') && Installer::amxxCore('x/plugins-zp.ini'));
assert(!Installer::amxxCore('x/mm_pack.cfg') && !Installer::amxxCore('x/plugins.cfg'));

foreach ([
    'MyPlugin/addons/amxmodx/plugins/x.amxx' => 'addons/amxmodx/plugins/x.amxx',
    'cstrike/addons/amxmodx/configs/x.cfg' => 'addons/amxmodx/configs/x.cfg',
    'addons/amxmodx/data/lang/x.txt' => 'addons/amxmodx/data/lang/x.txt',
    'addons/amxmodx/scripting/x.sma' => null,
    'addons/amxmodx/plugins/x.sma' => null,
    'addons/metamod/plugins.ini' => null,
    'MyPlugin/cstrike/sound/x/hello.wav' => 'sound/x/hello.wav',
    'sound/cstrike/x.wav' => 'sound/cstrike/x.wav',
    'models/x/v_knife.mdl' => 'models/x/v_knife.mdl',
    'x.amxx' => 'addons/amxmodx/plugins/x.amxx',
    'pack/x.ini' => 'addons/amxmodx/configs/x.ini',
    'lang/x.txt' => 'addons/amxmodx/data/lang/x.txt',
    'readme.txt' => null,
    'x.sma' => null,
] as $from => $to) {
    assert(Installer::amxxPlacement($from) === $to, $from);
}

foreach ([
    'de_x/cstrike/maps/de_x.bsp' => 'maps/de_x.bsp',
    'maps/de_x.res' => 'maps/de_x.res',
    'de_x.bsp' => 'maps/de_x.bsp',
    'de_x.wad' => 'de_x.wad',
    'pack/gfx/env/skyup.tga' => 'gfx/env/skyup.tga',
    'skyup.tga' => 'gfx/env/skyup.tga',
    'sound/ambience/x.wav' => 'sound/ambience/x.wav',
    'x.wav' => 'sound/x.wav',
    'overviews/de_x.bmp' => 'overviews/de_x.bmp',
    'readme.txt' => null,
    'de_x.rmf' => null,
    'Maps/De_X.BSP' => 'maps/De_X.BSP',
    'de_x/cstrike/materials/de_x/wall.vtf' => 'materials/de_x/wall.vtf',
    'de_x.nav' => 'maps/de_x.nav',
] as $from => $to) {
    assert(Installer::mapPlacement($from) === $to, $from);
}

assert(Installer::plainFileName('de_x (v2).zip') && !Installer::plainFileName('../x.zip') && !Installer::plainFileName('x'));

// CS:GO mapgroup: a new file, a group replaced, other groups and keys stay
$new = Installer::withMapgroup('', ['de_dust2', 'de_x']);
assert(Installer::mapgroupMaps($new, 'mg_modmanager') === ['de_dust2', 'de_x']);
$changed = Installer::withMapgroup($new, ['de_x']);
assert(Installer::mapgroupMaps($changed, 'mg_modmanager') === ['de_x'] && substr_count($changed, 'mg_modmanager"') === 2);
$own = "\"GameModes_Server.txt\"\n{\n\t\"gameTypes\" { }\n\t\"mapgroups\"\n\t{\n\t\t\"mg_mine\"\n\t\t{\n\t\t\t\"maps\"\n\t\t\t{\n\t\t\t\t\"cs_a\"\t\"\"\n\t\t\t}\n\t\t}\n\t}\n}\n";
$with = Installer::withMapgroup($own, ['de_y']);
assert(Installer::mapgroupMaps($with, 'mg_mine') === ['cs_a'] && Installer::mapgroupMaps($with, 'mg_modmanager') === ['de_y'] && str_contains($with, '"gameTypes" { }'));
assert(Installer::mapgroupMaps(Installer::withMapgroup("\"x\"\n{\n}\n", []), 'mg_modmanager') === []);
assert(Installer::mapgroupMaps($own, 'mg_active') === null);

assert(Installer::mapNames(['cstrike/maps/fy_a.bsp', 'cstrike/maps/fy_a.res', 'cstrike/de_x.wad', 'cstrike/maps/sub/x.bsp', 'cstrike/maps/fy_b.BSP']) === ['fy_a', 'fy_b']);
$cycle = "de_dust2\r\nde_aztec\r\n";
assert(Installer::withMaps($cycle, ['fy_a', 'de_aztec'], true) === "de_dust2\r\nde_aztec\r\nfy_a\r\n", 'appended once, line ends kept');
assert(Installer::withMaps("de_dust2\nfy_a \n", ['fy_a'], false) === "de_dust2\n");
assert(Installer::withMaps('', ['fy_a'], true) === "fy_a\n" && Installer::withMaps("fy_a\n", ['fy_a'], false) === '');
assert(Installer::withMaps($cycle, ['de_dust'], false) === $cycle, 'only whole names');

// --- server.properties ---------------------------------------------------------------------------

$properties = "#Minecraft server properties\nlevel-name=my world\nresource-pack=\nresource-pack-sha1=\nresource-pack-prompt=\nmotd=a\:b\n";
assert(Installer::property($properties, 'level-name') === 'my world');
assert(Installer::property($properties, 'motd') === 'a:b');
assert(Installer::property($properties, 'resource-pack') === '');
assert(Installer::property($properties, 'gamemode') === null);

$set = Installer::withProperties($properties, ['resource-pack' => 'https://cdn.modrinth.com/a.zip', 'resource-pack-sha1' => 'abc']);
assert(Installer::property($set, 'resource-pack') === 'https://cdn.modrinth.com/a.zip');
assert(Installer::property($set, 'resource-pack-sha1') === 'abc');
assert(Installer::property($set, 'resource-pack-prompt') === '' && substr_count($set, "\n") === substr_count($properties, "\n"), 'other lines stay');
assert(Installer::withProperties($set, ['resource-pack' => '', 'resource-pack-sha1' => '']) === $properties, 'cleared again');
assert(Installer::withProperties('', ['resource-pack' => 'u']) === "resource-pack=u\n", 'no file yet');
assert(Installer::withProperties("a=1", ['b' => "x\ny"]) === "a=1\nb=xy\n", 'one line only');
assert(Installer::withProperties("a=1\r\nb=2\r\n", ['a' => '$1']) === "a=\$1\r\nb=2\r\n", 'no regex back references');

assert(Marschux\ModManager\Sources\GameBanana::split('gamebanana:83688') === '83688');
foreach (['gamebanana:', 'gamebanana:12a', 'gamebanana:../1', '83688'] as $bad) {
    try {
        Marschux\ModManager\Sources\GameBanana::split($bad);
        assert(false, "$bad should not pass");
    } catch (RuntimeException) {
    }
}

// --- hangar ids ---------------------------------------------------------------------------------

assert(Marschux\ModManager\Sources\Hangar::split('hangar:ViaBackwards') === 'ViaBackwards');
assert(Marschux\ModManager\Sources\Hangar::split('hangar:Plan.Player-Analytics') === 'Plan.Player-Analytics');

foreach (['ViaBackwards', 'hangar:', 'hangar:..', 'hangar:a/b', 'modrinth:abc'] as $bad) {
    try {
        Marschux\ModManager\Sources\Hangar::split($bad);
        assert(false, "$bad should not pass");
    } catch (RuntimeException) {
    }
}

// --- game version --------------------------------------------------------------------------------

assert(GameVersion::fromFile('{"currentVersion":"26.3-226-4f49049 (MC: 26.3)"}') === '26.3');
assert(GameVersion::fromFile('{"oldVersion": "git-Paper-1 (MC: 1.20.4)", "currentVersion": "1.21.4-232-main@abc (MC: 1.21.4)"}') === '1.21.4', 'the current one, not the old one');
assert(GameVersion::fromFile('') === null);
assert(GameVersion::fromFile('{"currentVersion": "something else"}') === null);

// --- package ids ---------------------------------------------------------------------------------

assert(Thunderstore::split('ValheimModding-Jotunn') === ['ValheimModding', 'Jotunn']);
assert(Thunderstore::split('denikson-BepInExPack_Valheim') === ['denikson', 'BepInExPack_Valheim']);

foreach (['Jotunn', '../x-y', 'a-b/c', 'a-', '-b', ''] as $bad) {
    try {
        Thunderstore::split($bad);
        assert(false, "$bad should be refused");
    } catch (RuntimeException) {
    }
}

// --- configs a mod wrote on its own ---------------------------------------------------------------

$configs = [
    'BepInEx/config/BepInEx.cfg',
    'BepInEx/config/advize.PlantEverything.cfg',
    'BepInEx/config/randyknapp.mods.epicloot.cfg',
    'BepInEx/config/EpicLoot/baseconfig/abilities.json',
    'BepInEx/config/EpicLoot/patches/AllTheHeims/mp.loottables.json',
    'BepInEx/config/Jotunn/x.cfg',
];

assert(Installer::configsOf('PlantEverything', $configs, 'BepInEx/config') === ['BepInEx/config/advize.PlantEverything.cfg']);
assert(Installer::configsOf('EpicLoot', $configs, 'BepInEx/config', ['BepInEx/config/EpicLoot/patches/AllTheHeims/mp.loottables.json']) === [
    'BepInEx/config/randyknapp.mods.epicloot.cfg',
    'BepInEx/config/EpicLoot/baseconfig/abilities.json',
], 'a file another mod brought along stays');
// a folder only counts with the whole name; file names match on part of it, so a short name can
// catch more - the remove modal lists every file and the box is off by default
assert(Installer::configsOf('Loot', $configs, 'BepInEx/config') === ['BepInEx/config/randyknapp.mods.epicloot.cfg', 'BepInEx/config/EpicLoot/patches/AllTheHeims/mp.loottables.json']);
assert(Installer::configsOf('X', $configs, 'BepInEx/config') === [], 'too short a name matches nothing');
assert(Installer::configsOf('PlantEverything', $configs, '') === []);
assert(Installer::configFor('PlantEverything', $configs) === 'BepInEx/config/advize.PlantEverything.cfg');

$plugins = ['plugins/Essentials/config.yml', 'plugins/LuckPerms/contexts.json', 'plugins/LuckPerms/config.yml', 'plugins/bStats/config.yml'];
assert(Installer::configFor('LuckPerms', $plugins) === 'plugins/LuckPerms/config.yml', 'config.yml first');
assert(Installer::configFor('EssentialsX', $plugins) === 'plugins/Essentials/config.yml', 'by folder, not file name');
assert(Installer::configFor('WorldEdit', $plugins) === null);

// --- plugin jars ---------------------------------------------------------------------------------

assert(Installer::jarName('EssentialsX-2.22.0.jar') === 'EssentialsX-2.22.0.jar');
assert(Installer::jarName('ViaBackwards-5.12.2-SNAPSHOT+665.jar') === 'ViaBackwards-5.12.2-SNAPSHOT+665.jar');
assert(Installer::jarName('Chunky-Bukkit [1.4].jar') === 'Chunky-Bukkit [1.4].jar');
assert(Installer::jarName('Terralith_1.21.x_v2.5.8.zip') === 'Terralith_1.21.x_v2.5.8.zip', 'a datapack');

// the name ends up in a path on the server
foreach (['', '.jar', '../x.jar', 'a/b.jar', '.hidden.jar', 'x.exe', "x.jar\n", 'x.jar/'] as $bad) {
    try {
        Installer::jarName($bad);
        assert(false, "$bad should not pass");
    } catch (Throwable $exception) {
        assert(!$exception instanceof AssertionError, $exception->getMessage());
    }
}

assert(Installer::jarPackage('modrinth:hXiIvTyT') && Installer::jarPackage('hangar:ViaVersion'));
assert(!Installer::jarPackage('ValheimModding-Jotunn'));

// --- data folders of plugins ---------------------------------------------------------------------

$folders = ['Essentials', 'LuckPerms', 'ViaVersion', 'bStats', 'WorldEdit', 'WorldGuard', 'spark'];

assert(Installer::dataFoldersOf('LuckPerms', $folders) === ['LuckPerms']);
assert(Installer::dataFoldersOf('EssentialsX', $folders) === ['Essentials'], 'EssentialsX writes to Essentials');
assert(Installer::dataFoldersOf('EssentialsX', $folders, ['EssentialsX Chat']) === [], 'shared with another plugin: not certain whose');
assert(Installer::dataFoldersOf('ViaBackwards', $folders) === [], 'not the folder of ViaVersion');
assert(Installer::dataFoldersOf('WorldEdit', $folders, ['WorldGuard']) === ['WorldEdit']);
assert(Installer::dataFoldersOf('Simple Voice Chat', $folders) === []);
assert(Installer::dataFoldersOf('sp', $folders) === [], 'too short to tell');

// --- pages of 12 out of thunderstore's list -------------------------------------------------------

assert(Thunderstore::sourceStart(1, [0, 3]) === 1, 'a hidden one right at the start is skipped');
assert(Thunderstore::sourceStart(1, [3]) === 0, 'one further down is dropped from the page instead');
assert(Thunderstore::sourceStart(2, []) === 12);
assert(Thunderstore::sourceStart(2, [0, 3]) === 14, 'two hidden before it shift page 2 by two');
assert(Thunderstore::sourceStart(2, [12, 13]) === 14, 'hidden right where it would start are skipped too');
assert(Thunderstore::sourceStart(2, [15]) === 12, 'a hidden one after the start does not shift it');
assert(Thunderstore::sourceStart(3, [1, 5]) === 26);

// --- what removing the loader deletes ------------------------------------------------------------

$kept = ['BepInEx/plugins', 'BepInEx/plugins_disabled', 'BepInEx/config'];
$paths = Installer::loaderPaths([
    'BepInEx/core/BepInEx.dll',
    'BepInEx/core/BepInEx.Preloader.dll',
    'BepInEx/config/BepInEx.cfg',
    'BepInEx/plugins/README.txt',
    'doorstop_libs/libdoorstop_x64.so',
    'winhttp.dll',
    'start_server_bepinex.sh',
], $kept);
sort($paths);

assert($paths === ['BepInEx/core', 'doorstop_libs', 'start_server_bepinex.sh', 'winhttp.dll'], json_encode($paths));

// --- where the files of a package go ------------------------------------------------------------

$place = fn (string $file) => Installer::placement($file, 'A-B', 'BepInEx', 'BepInEx/config');

assert($place('B.dll') === null);
assert($place('plugins/B.dll') === null, 'plugins stay in the package folder');
assert($place('BepInEx/plugins/B.dll') === null);
assert($place('config') === null, 'a file called config is no folder');
assert($place('patchers/B.Patcher.dll') === ['to' => 'BepInEx/patchers/A-B/B.Patcher.dll', 'track' => 'BepInEx/patchers/A-B']);
assert($place('BepInEx/Patchers/x/B.dll') === ['to' => 'BepInEx/patchers/A-B/x/B.dll', 'track' => 'BepInEx/patchers/A-B']);
assert($place('monomod/B.mm.dll')['to'] === 'BepInEx/monomod/A-B/B.mm.dll');
assert($place('config/EpicLoot/patches/b.json') === ['to' => 'BepInEx/config/EpicLoot/patches/b.json', 'track' => 'BepInEx/config/EpicLoot/patches/b.json']);
assert($place('BepInEx/config/b.cfg')['to'] === 'BepInEx/config/b.cfg');
// without a loader folder or config folder nothing is sorted
assert(Installer::placement('patchers/B.dll', 'A-B', '', '') === null);
assert(Installer::placement('config/b.cfg', 'A-B', 'BepInEx', '') === null);

// --- switching on and off ------------------------------------------------------------------------

$bases = ['BepInEx/plugins', 'BepInEx/patchers', 'BepInEx/monomod', 'BepInEx/config'];

foreach (['BepInEx/plugins/A-B' => 'BepInEx/plugins_disabled/A-B', 'BepInEx/patchers/A-B' => 'BepInEx/patchers_disabled/A-B', 'BepInEx/config/x/b.json' => 'BepInEx/config_disabled/x/b.json'] as $on => $off) {
    assert(Installer::switched($on, false, $bases) === $off, $on);
    assert(Installer::switched($off, true, $bases) === $on, $off);
    assert(Installer::switched($on, true, $bases) === $on, 'switching on what is on changes nothing');
}

assert(Installer::switched('BepInEx/pluginsX/A', false, $bases) === 'BepInEx/pluginsX/A', 'only whole folder names');

// --- counterstrikesharp plugins ------------------------------------------------------------------

use Marschux\ModManager\Sources\GitHub;

$css = 'addons/counterstrikesharp/';
assert(Installer::cssPlacement('RockTheVote/RockTheVote.dll', 'RockTheVote') === $css . 'plugins/RockTheVote/RockTheVote.dll', 'a plugin folder on its own');
assert(Installer::cssPlacement('RockTheVote/lang/en.json', 'RockTheVote') === $css . 'plugins/RockTheVote/lang/en.json');
assert(Installer::cssPlacement('MatchZy/addons/counterstrikesharp/plugins/MatchZy/MatchZy.dll', 'MatchZy') === $css . 'plugins/MatchZy/MatchZy.dll', 'wrapped addons folder');
assert(Installer::cssPlacement('cfg/MatchZy/config.cfg', 'MatchZy') === 'cfg/MatchZy/config.cfg', 'game configs');
assert(Installer::cssPlacement('plugins/X/X.dll', 'X') === $css . 'plugins/X/X.dll');
assert(Installer::cssPlacement('configs/plugins/X/X.json', 'X') === $css . 'configs/plugins/X/X.json');
assert(Installer::cssPlacement('X.dll', 'X') === $css . 'plugins/X/X.dll', 'loose files go into the plugin folder');
assert(Installer::cssPlacement('README.md', 'X') === null && Installer::cssPlacement('src/Plugin.cs', 'X') === null);
assert(Installer::cssName(['RockTheVote/RockTheVote.deps.json'], 'RockTheVote-v1.9.6.RELEASE.zip') === 'RockTheVote');
assert(Installer::cssName([], 'RockTheVote_v1.8.5.zip') === 'RockTheVote' && Installer::cssName([], 'MyPlugin.dll') === 'MyPlugin');
assert(Installer::cssPlugins(['g/plugins/A/A.dll', 'g/plugins/A/Dep.dll', 'g/plugins/A/lang/en.json', 'g/plugins/B/b.dll'], 'g/plugins') === ['g/plugins/A', 'g/plugins/B'], 'a folder with its own .dll is a plugin');

assert(GitHub::repository('https://github.com/Oz-Lin/cs2-rockthevote/releases/tag/v1.9.6') === 'Oz-Lin/cs2-rockthevote');
assert(GitHub::repository('github.com/a/b.git') === 'a/b' && GitHub::repository('a/b') === 'a/b' && GitHub::repository('https://example.com/a/b') === null);
assert(GitHub::shape('RockTheVote-v1.9.6.RELEASE.zip') === 'RockTheVote-#.RELEASE.zip' && GitHub::shape('MatchZy-0.9.2-with-cssharp-linux.zip') === 'MatchZy-#-with-cssharp-linux.zip');
assert(GitHub::parse(GitHub::package('a/b', 'X-1.2.zip')) === ['a/b', 'X-#.zip']);

// --- counter-strike 2 workshop maps --------------------------------------------------------------

use Marschux\ModManager\Sources\Workshop;

assert(Workshop::id('3070284539') === '3070284539' && Workshop::id('https://steamcommunity.com/sharedfiles/filedetails/?id=3070284539&searchtext=') === '3070284539');
assert(Workshop::id('https://steamcommunity.com/sharedfiles/filedetails/?l=german&id=123456789') === '123456789' && Workshop::id('de_dust2') === null);
assert(Workshop::mapName('Mirage Night (CS2)') === 'mirage_night_cs2' && Workshop::mapName('!!!') === 'workshop_map');

$example = "//Put your map list here\nde_thera:3121217565\nde_vertigo\n";
$once = Installer::withWorkshopBlock($example, ['aim_map:111111']);
assert(str_starts_with($once, $example) && str_contains($once, "aim_map:111111\n"), 'the block goes at the end, the rest stays');
$twice = Installer::withWorkshopBlock($once, ['fy_pool:222222']);
assert(!str_contains($twice, 'aim_map') && str_contains($twice, 'fy_pool:222222') && substr_count($twice, 'mod-manager: end') === 1, 'the block is replaced, not added again');
assert(str_starts_with(Installer::withWorkshopBlock('', []), '// mod-manager'), 'an empty file gets only the block');

// SourceMod: where a plugin zip's files go, and switching a .smx off and on
foreach ([
    ['MyPlugin/addons/sourcemod/plugins/my.smx', 'addons/sourcemod/plugins/my.smx'],
    ['addons/sourcemod/translations/my.phrases.txt', 'addons/sourcemod/translations/my.phrases.txt'],
    ['addons/sourcemod/scripting/my.sp', null],
    ['addons/sourcemod/scripting/include/my.inc', null],
    ['pack/cfg/sourcemod/plugin.my.cfg', 'cfg/sourcemod/plugin.my.cfg'],
    ['pack/sound/my/hit.wav', 'sound/my/hit.wav'],
    ['my.smx', 'addons/sourcemod/plugins/my.smx'],
    ['my.phrases.txt', 'addons/sourcemod/translations/my.phrases.txt'],
    ['my.games.txt', 'addons/sourcemod/gamedata/my.games.txt'],
    ['my.ext.so', 'addons/sourcemod/extensions/my.ext.so'],
    ['plugin.my.cfg', 'cfg/sourcemod/plugin.my.cfg'],
    ['my.sp', null],
    ['README.md', null],
] as [$from, $to]) {
    assert(Installer::smxPlacement($from) === $to, $from);
}
$plugins = 'cstrike/addons/sourcemod/plugins';
assert(Installer::smxSwitched("$plugins/my.smx", false, $plugins) === "$plugins/disabled/my.smx");
assert(Installer::smxSwitched("$plugins/disabled/my.smx", true, $plugins) === "$plugins/my.smx");
assert(Installer::smxSwitched('cstrike/addons/sourcemod/translations/my.phrases.txt', false, $plugins) === 'cstrike/addons/sourcemod/translations/my.phrases.txt', 'only plugins move');
assert(Installer::smxSwitched("$plugins/disabled/my.smx", false, $plugins) === "$plugins/disabled/my.smx", 'off stays off');

// --- access lists: the presets that ship with the plugin -------------------------------------------------------

$presets = ListPresets::all();

// every preset hint is a key that all three languages translate, with nothing left over
$hintKeys = array_unique(array_merge(...array_values(array_map(fn (array $preset) => array_column($preset['files'], 'hint'), $presets))));

foreach (ListPresets::LANGUAGES as $language) {
    $translated = array_keys((require __DIR__ . "/lang/$language/lists.php")['preset_hints']);
    sort($translated);
    $expected = array_values($hintKeys);
    sort($expected);

    assert($translated === $expected, "$language: preset hints and translations differ");
}

assert(count($presets['minecraft']['files']) === 5 && count($presets['valheim']['files']) === 3 && count($presets['teamspeak']['files']) === 2 && count($presets['cs2']['files']) === 3);

foreach ($presets as $game => $preset) {
    assert(ListPresets::options()[$game] === $preset['name']);

    // what the admin form gets must store back into the same list
    foreach (ListPresets::formFiles($game) as $index => $formFile) {
        assert(ListDefinition::fromArray('x', $formFile) == ListDefinition::fromArray('x', $preset['files'][$index]), "$game/$index: form files differ from the preset");
    }

    foreach ($preset['files'] as $index => $file) {
        $definition = ListDefinition::fromArray("preset:$game:$index", $file);

        assert($definition !== null, "$game/$index: every shipped preset has to build");
        assert($definition->label !== $definition->path, "$game/$index: a preset without a real label would show its path");
        assert($definition->hint !== null, "$game/$index: a shipped preset tells the editor what goes in");

        if ($definition->isPairs()) {
            assert(str_contains($definition->pairTemplate(), '{key}') && str_contains($definition->pairTemplate(), '{value}'), "$game/$index: a settings file needs both placeholders");

            continue;
        }

        if (!$definition->isJson()) {
            continue;
        }

        assert($definition->keyField !== null && $definition->displayField !== null, "$game/$index: a json list needs both fields");
        assert(is_array(json_decode((string) $definition->template, true)), "$game/$index: the template has to be valid json");
        assert(array_key_exists($definition->displayField, $definition->templateArray()), "$game/$index: the template must fill the field the editor types into");
    }
}

// --- line templates ------------------------------------------------------------------------------

$bans = ListDefinition::fromArray('preset:cs2:0', $presets['cs2']['files'][0]);
assert($bans->lineTemplate() === 'banid 0 {value}');
assert(ListFile::wrapLine($bans->lineTemplate(), 'STEAM_1:0:1') === 'banid 0 STEAM_1:0:1');
assert(ListFile::wrapLine($bans->lineTemplate(), 'banid 0 STEAM_1:0:1') === 'banid 0 STEAM_1:0:1', 'an entry read back and saved again must not nest');
assert(ListFile::unwrapLine($bans->lineTemplate(), 'banid 0 STEAM_1:0:1') === 'STEAM_1:0:1');
assert(ListFile::unwrapLine($bans->lineTemplate(), 'something else') === 'something else', 'a line that does not fit the shape stays as it is');
assert(ListFile::unwrapLine($bans->lineTemplate(), 'banid 0 ') === 'banid 0 ', 'an empty value is not a value');

$whitelist = ListDefinition::fromArray('preset:minecraft:0', $presets['minecraft']['files'][0]);
assert($whitelist->lineTemplate() === null, 'a json list has no line shape');

// --- key = value settings ------------------------------------------------------------------------

$properties = ListDefinition::fromArray('preset:minecraft:4', $presets['minecraft']['files'][4]);
assert($properties->isPairs() && $properties->pairTemplate() === '{key}={value}');
assert($properties->lineTemplate() === null, 'a settings file is not a templated line list');

$file = "# Minecraft server properties
# Sat Sep 12
max-players=20
motd=A Minecraft Server
white-list=false
";
$pairs = ListFile::pairEntries($file, $properties->pairTemplate());
assert($pairs === ['max-players' => '20', 'motd' => 'A Minecraft Server', 'white-list' => 'false']);

$saved = ListFile::buildPairs($file, ['max-players' => '30', 'motd' => 'A Minecraft Server', 'pvp' => 'true'], $properties->pairTemplate());
assert(str_contains($saved, "# Minecraft server properties
"), 'the comments of the file survive a save');
assert(str_contains($saved, 'max-players=30'), 'a changed setting is written in place');
assert(!str_contains($saved, 'white-list'), 'a setting removed in the editor is gone');
assert(str_ends_with($saved, "motd=A Minecraft Server
pvp=true
"), 'a new setting is appended, with no gap left by the removed one');
assert(ListFile::pairEntries($saved, '{key}={value}') === ['max-players' => '30', 'motd' => 'A Minecraft Server', 'pvp' => 'true']);

// a cvar config quotes its values
$cvars = ListDefinition::fromArray('preset:cs2:2', $presets['cs2']['files'][2]);
assert($cvars->pairTemplate() === '{key} "{value}"');
assert(ListFile::pairEntries("// server
hostname \"Mein Server\"
mp_maxrounds \"24\"
", $cvars->pairTemplate())
    === ['hostname' => 'Mein Server', 'mp_maxrounds' => '24']);
assert(ListFile::buildPair('sv_alltalk', '1', $cvars->pairTemplate()) === 'sv_alltalk "1"');
assert(ListFile::parsePair('exec banned_user.cfg', '{key}={value}') === null, 'a line without the separator is no setting');

// the game takes cvars bare too; commands and keys set twice stay out of the editor and as they were
$serverCfg = "// default server name\nhostname \"CS 1.6 Test\"\nsv_aim 0\nmp_timelimit 20\nexec listip.cfg\nexec banned.cfg\nlog on\nlog off\n";
assert(ListFile::pairEntries($serverCfg, $cvars->pairTemplate()) === ['hostname' => 'CS 1.6 Test', 'sv_aim' => '0', 'mp_timelimit' => '20']);
assert(ListFile::buildPairs($serverCfg, ['hostname' => 'CS 1.6 Test', 'sv_aim' => '0', 'mp_timelimit' => '30'], $cvars->pairTemplate())
    === "// default server name\nhostname \"CS 1.6 Test\"\nsv_aim 0\nmp_timelimit \"30\"\nexec listip.cfg\nexec banned.cfg\nlog on\nlog off\n", 'only the changed line is rewritten');
assert(ListFile::buildPairs($serverCfg, ['hostname' => 'CS 1.6 Test', 'mp_timelimit' => '20'], $cvars->pairTemplate())
    === "// default server name\nhostname \"CS 1.6 Test\"\nmp_timelimit 20\nexec listip.cfg\nexec banned.cfg\nlog on\nlog off\n", 'a removed bare cvar goes');

// a whitelist entry has to end up with a real uuid, not the placeholder
$whitelistPreset = ListDefinition::fromArray('preset:minecraft:0', $presets['minecraft']['files'][0]);
assert($whitelistPreset->needsUuid());
assert(ListFile::fillTemplate($whitelistPreset->templateArray(), ['{uuid}' => 'u', '{name}' => 'Notch']) === ['uuid' => 'u', 'name' => 'Notch']);

// the ip ban list must not ask for a uuid - there is no player behind an address
$ipPreset = ListDefinition::fromArray('preset:minecraft:3', $presets['minecraft']['files'][3]);
assert(!$ipPreset->needsUuid() && $ipPreset->valueField() === 'ip');

// a typed uuid is taken as one, dashed or not; a name never is
assert(MinecraftUuid::fromInput('a160cfdf15704999ae8d8bf42b0f8ae3') === 'a160cfdf-1570-4999-ae8d-8bf42b0f8ae3');
assert(MinecraftUuid::fromInput(' A160CFDF-1570-4999-AE8D-8BF42B0F8AE3 ') === 'a160cfdf-1570-4999-ae8d-8bf42b0f8ae3');
assert(MinecraftUuid::fromInput('Notch') === null && MinecraftUuid::fromInput('') === null);

// --- what the admin form produces ---------------------------------------------------------------

$json = ListDefinition::fromArray('7:0', [
    'label' => 'Whitelist',
    'path' => 'whitelist.json',
    'format' => 'json',
    'key_field' => 'uuid',
    'display_field' => 'name',
    'entry_template' => '{"uuid": "{uuid}", "name": "{name}"}',
]);

assert($json !== null && $json->isJson() && $json->needsUuid());
assert($json->valueField() === 'name');
assert($json->templateArray() === ['uuid' => '{uuid}', 'name' => '{name}']);

$lines = ListDefinition::fromArray('7:1', ['path' => 'savegames/adminlist.txt']);

assert($lines !== null && !$lines->isJson(), 'text lines are the default format');
assert($lines->label === 'savegames/adminlist.txt', 'without a label the path has to do');
assert(!$lines->needsUuid() && $lines->templateArray() === []);
assert($lines->valueField() === 'name', 'a line list has no fields, but the editor still needs a key');

assert(ListDefinition::fromArray('7:2', ['label' => 'No path']) === null, 'a file without a path is not a list');
assert(ListDefinition::fromArray('7:3', ['path' => 'x', 'format' => 'nonsense'])->format === 'lines', 'an unknown format falls back rather than blowing up');
assert(ListDefinition::fromArray('7:4', ['path' => 'x', 'format' => 'json', 'display_field' => ''])->valueField() === 'name', 'empty fields count as unset');

// --- line lists (valheim and anything else one-per-line) ---------------------------------------

$adminlist = "// List admin players ID  ONE per line\r\nSteam_123\n\n  Steam_456 \n";

assert(ListFile::lineEntries($adminlist) === ['Steam_123', 'Steam_456'], 'comments, blank lines and stray spaces are not entries');
assert(ListFile::lineEntries('') === []);
assert(ListFile::lineEntries("# hash comments too\nabc") === ['abc']);

$rebuilt = ListFile::buildLines($adminlist, ['Steam_123', 'Steam_789']);
assert(str_starts_with($rebuilt, '// List admin players ID'), 'the header the game wrote must survive');
assert(ListFile::lineEntries($rebuilt) === ['Steam_123', 'Steam_789'], 'removed entry is gone, new one is there');
assert(str_ends_with($rebuilt, "\n"), 'files end with a newline');
assert(ListFile::buildLines('', []) === '', 'an empty list writes an empty file, not a stray newline');
assert(ListFile::lineEntries(ListFile::buildLines($adminlist, ['Steam_1', 'Steam_1'])) === ['Steam_1'], 'a duplicate is written once');

// --- json lists (minecraft) ---------------------------------------------------------------------

$whitelist = '[{"uuid":"069a79f4-44e9-4726-a5be-fca90e38aaf5","name":"Notch","futureField":"keep me"}]';
$entries = ListFile::jsonEntries($whitelist);

assert(is_array($entries) && count($entries) === 1);
assert(ListFile::displayValues($entries, 'name') === ['Notch']);
assert(ListFile::jsonEntries('') === [], 'an empty file is an empty list, not an error');
assert(ListFile::jsonEntries('not json') === null, 'unreadable must be distinguishable from empty');
assert(ListFile::jsonEntries('{"a":1}') === null, 'an object is not a list of entries');
assert(ListFile::jsonEntries('[1,2]') === null, 'entries have to be objects');

$made = 0;
$make = function (string $value) use (&$made): array {
    $made++;

    return ['uuid' => 'new-uuid', 'name' => $value];
};

$built = ListFile::buildJson($entries, ['Notch', 'Second'], 'name', $make);
assert($made === 1, 'only the genuinely new value is built from the template');
assert($built[0]['futureField'] === 'keep me', 'an entry that stays keeps fields this plugin knows nothing about');
assert($built[1]['name'] === 'Second');
assert(ListFile::buildJson($entries, [], 'name', $make) === [], 'removing everything leaves an empty list');
assert(ListFile::encodeJson(ListFile::buildJson($entries, [], 'name', $make))[0] === '[', 'must stay a json array, the games refuse an object');

$kept = ListFile::buildJson($entries, ['notch'], 'name', $make);
assert(count($kept) === 1 && $kept[0]['futureField'] === 'keep me', 'matching ignores case, or an entry would be rebuilt and lose its fields');

// --- entry templates ---------------------------------------------------------------------------

$template = ['uuid' => '{uuid}', 'name' => '{name}', 'level' => 4, 'nested' => ['created' => '{now}']];
$filled = ListFile::fillTemplate($template, ['{uuid}' => 'u', '{name}' => 'n', '{now}' => 't']);

assert($filled === ['uuid' => 'u', 'name' => 'n', 'level' => 4, 'nested' => ['created' => 't']], 'non-strings stay untouched, nested objects are filled too');
assert(ListFile::fillTemplate(['x' => '{unknown}'], []) === ['x' => '{unknown}'], 'an unknown placeholder is left alone rather than emptied');

// --- uuids ---------------------------------------------------------------------------------------

assert(MinecraftUuid::offline('Notch') === 'b50ad385-829d-3141-a216-7e7d7539ba7f', 'what an offline-mode server itself computes');
assert(MinecraftUuid::dashed('069a79f444e94726a5befca90e38aaf5') === '069a79f4-44e9-4726-a5be-fca90e38aaf5');
assert(MinecraftUuid::dashed('nope') === null && MinecraftUuid::dashed('') === null);

assert(MinecraftUuid::isOfflineMode("online-mode=false\n") === true);
assert(MinecraftUuid::isOfflineMode("online-mode=true\n") === false);
assert(MinecraftUuid::isOfflineMode("motd=A server\n") === false, 'a missing setting means the default, which is online mode');
assert(MinecraftUuid::isOfflineMode(null) === false, 'no file at all is not an offline server');

echo "ok\n";
