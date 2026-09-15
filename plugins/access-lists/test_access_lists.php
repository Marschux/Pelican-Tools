<?php

/**
 * Self-check for the list file handling. Run with: php test_access_lists.php
 *
 * Covers the part where a mistake silently corrupts somebody's ban list: parsing, rebuilding and
 * the uuid derivation. The daemon and the panel are not involved.
 */

require __DIR__ . '/src/Lists/ListDefinition.php';
require __DIR__ . '/src/Lists/Presets.php';
require __DIR__ . '/src/Lists/ListFile.php';
require __DIR__ . '/src/Lists/MinecraftUuid.php';

use Marschux\AccessLists\Lists\ListDefinition;
use Marschux\AccessLists\Lists\ListFile;
use Marschux\AccessLists\Lists\MinecraftUuid;
use Marschux\AccessLists\Lists\Presets;

// --- the presets that ship with the plugin -------------------------------------------------------

$presets = Presets::all();

assert(count($presets['minecraft']['files']) === 4 && count($presets['valheim']['files']) === 3 && count($presets['teamspeak']['files']) === 2);

foreach ($presets as $game => $preset) {
    assert(Presets::options()[$game] === $preset['name']);

    // what the admin form gets must store back into the same list
    foreach (Presets::formFiles($game) as $index => $formFile) {
        assert(ListDefinition::fromArray('x', $formFile) == ListDefinition::fromArray('x', $preset['files'][$index]), "$game/$index: form files differ from the preset");
    }

    foreach ($preset['files'] as $index => $file) {
        $definition = ListDefinition::fromArray("preset:$game:$index", $file);

        assert($definition !== null, "$game/$index: every shipped preset has to build");
        assert($definition->label !== $definition->path, "$game/$index: a preset without a real label would show its path");
        assert($definition->hint !== null, "$game/$index: a shipped preset tells the editor what goes in");

        if (!$definition->isJson()) {
            continue;
        }

        assert($definition->keyField !== null && $definition->displayField !== null, "$game/$index: a json list needs both fields");
        assert(is_array(json_decode((string) $definition->template, true)), "$game/$index: the template has to be valid json");
        assert(array_key_exists($definition->displayField, $definition->templateArray()), "$game/$index: the template must fill the field the editor types into");
    }
}

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

assert(ListDefinition::fromArray('7:2', ['label' => 'Ohne Pfad']) === null, 'a file without a path is not a list');
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
