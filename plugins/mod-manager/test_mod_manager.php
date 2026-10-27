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
    assert($settings['sources'] !== [] && array_diff($settings['sources'], array_keys(Presets::SOURCES)) === [], "$key: unknown source");
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
    if (($settings['loader_type'] ?? 'thunderstore') === 'goldsrc') {
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

echo "ok\n";
