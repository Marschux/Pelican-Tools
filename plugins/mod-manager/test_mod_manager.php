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

echo "ok\n";
