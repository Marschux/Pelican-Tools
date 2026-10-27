<?php

/**
 * Self-check for what the plugin decides on its own. Run with: php test_mod_manager.php
 *
 * No panel, no daemon, no network.
 */

require __DIR__ . '/src/Profiles/Presets.php';
require __DIR__ . '/src/Sources/Thunderstore.php';
require __DIR__ . '/src/Installer.php';

use Marschux\ModManager\Installer;
use Marschux\ModManager\Profiles\Presets;
use Marschux\ModManager\Sources\Thunderstore;

// --- presets -------------------------------------------------------------------------------------

foreach (Presets::all() as $key => $preset) {
    $settings = $preset['settings'];

    assert(Presets::options()[$key] === $preset['name']);
    assert(isset(Presets::SOURCES[$settings['source']]), "$key: unknown source");

    foreach (['community', 'mods_dir', 'loader_check'] as $required) {
        assert(($settings[$required] ?? '') !== '', "$key: $required is needed");
    }

    // the loader is installed through its own button, so the browser never offers it
    assert(in_array($settings['loader_package'], $settings['hidden_packages'], true), "$key: the loader has to be hidden");
    assert(!str_starts_with($settings['mods_dir'], '/') && !str_contains($settings['mods_dir'], '..'), "$key: mods_dir stays inside the server");
}

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
