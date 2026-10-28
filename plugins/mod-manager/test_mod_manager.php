<?php

/**
 * Self-check for what the plugin decides on its own. Run with: php test_mod_manager.php
 *
 * No panel, no daemon, no network.
 */

require __DIR__ . '/src/Profiles/Presets.php';
require __DIR__ . '/src/Sources/Thunderstore.php';
require __DIR__ . '/src/Sources/Modrinth.php';
require __DIR__ . '/src/Sources/Hangar.php';
require __DIR__ . '/src/Sources/GameBanana.php';
require __DIR__ . '/src/Sources/GitHub.php';
require __DIR__ . '/src/Sources/Workshop.php';
require __DIR__ . '/src/Installer.php';
require __DIR__ . '/src/GameVersion.php';

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
] as $from => $to) {
    assert(Installer::mapPlacement($from) === $to, $from);
}

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

echo "ok\n";
