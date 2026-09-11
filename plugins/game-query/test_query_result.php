<?php

/**
 * Self-check for the QueryResult mapping. Run with: php test_query_result.php
 *
 * Covers the parts that are easy to get wrong and have no framework around them: bot
 * subtraction, "field not reported" vs "field is zero", and the formatted player pair.
 * The socket layer is not covered - that needs a real server.
 */

require __DIR__ . '/src/Query/QueryPlayer.php';
require __DIR__ . '/src/Query/QueryResult.php';
require __DIR__ . '/src/Query/FwlSeedParser.php';
require __DIR__ . '/src/Query/ConsoleLog.php';
require __DIR__ . '/src/Query/ValheimLogParser.php';
require __DIR__ . '/src/Query/QueryTypeSchemaInterface.php';
require __DIR__ . '/src/Query/Schemas/SourceSchema.php';
require __DIR__ . '/src/Query/Schemas/GoldSourceSchema.php';
require __DIR__ . '/src/Query/Schemas/MinecraftJavaSchema.php';
require __DIR__ . '/src/Query/Schemas/ValheimSchema.php';

use Marschux\GameQuery\Query\FwlSeedParser;
use Marschux\GameQuery\Query\QueryPlayer;
use Marschux\GameQuery\Query\QueryResult;
use Marschux\GameQuery\Query\Schemas\GoldSourceSchema;
use Marschux\GameQuery\Query\Schemas\MinecraftJavaSchema;
use Marschux\GameQuery\Query\Schemas\SourceSchema;
use Marschux\GameQuery\Query\Schemas\ValheimSchema;
use Marschux\GameQuery\Query\ValheimLogParser;

/** Builds a valheim world metadata file: two int32 headers, then two length-prefixed strings. */
function fwl(string $name, string $seed): string
{
    return pack('V', 0) . pack('V', 35)
        . chr(strlen($name)) . $name
        . chr(strlen($seed)) . $seed
        . pack('V', 123456) . pack('P', 42) . pack('V', 2);
}

assert(FwlSeedParser::parse(fwl('Dedicated', 'HHcLC5acQt')) === 'HHcLC5acQt');
assert(FwlSeedParser::parse(fwl('a', 'x')) === 'x', 'shortest possible name and seed');
assert(FwlSeedParser::parse(fwl(str_repeat('w', 64), 'seed1')) === 'seed1', 'name at the limit');

// truncated, empty and nonsense input must yield null, never a garbage seed
assert(FwlSeedParser::parse('') === null);
assert(FwlSeedParser::parse(substr(fwl('Dedicated', 'HHcLC5acQt'), 0, 11)) === null, 'cut off before the seed');
assert(FwlSeedParser::parse(pack('V', 0) . pack('V', 35) . chr(0) . 'xx') === null, 'zero-length name');
assert(FwlSeedParser::parse(pack('V', 0) . pack('V', 35) . chr(200) . str_repeat('x', 300)) === null, 'name length beyond the cap');

// a changed 1.0 header shifts everything: the bytes at the seed offset are not printable,
// and that must read as "cannot tell" rather than as a seed
assert(FwlSeedParser::parse(pack('V', 0) . pack('V', 35) . chr(4) . 'name' . chr(5) . chr(0) . chr(1) . chr(2) . chr(3) . chr(4)) === null, 'binary where the seed should be');

// server names carry markup the panel cannot render, and it must not leak into the ui
assert((new QueryResult(hostname: '<color=lime>Tormax</color>', players: 0, maxPlayers: 10))->hostname === 'Tormax');
assert(QueryResult::plain('<color=#ff0000>Rot</color> Server') === 'Rot Server');
assert(QueryResult::plain('<b>Fett</b> und <i>kursiv</i>') === 'Fett und kursiv');
assert(QueryResult::plain("\u{00a7}aGruen\u{00a7}r Server") === 'Gruen Server', 'minecraft colour codes');
assert(QueryResult::plain('Ganz normal') === 'Ganz normal', 'untouched when there is no markup');
assert(QueryResult::plain('A <bogus>tag</bogus> stays') === 'A <bogus>tag</bogus> stays', 'only known tags are stripped');

// the icon is not offered as a toggle: nothing renders a data uri in a stat block
assert(!in_array('icon', QueryResult::FIELDS, true));

// os was dropped entirely - not read from A2S, not offered, not in the api output
assert(!in_array('os', QueryResult::FIELDS, true));

// a schema only offers fields its protocol can actually report - the admin field list is
// built from this, and a checkbox for something a game can never show is worse than none
$sourceFields = (new SourceSchema())->supportedFields();
assert(in_array('map', $sourceFields, true) && in_array('secure', $sourceFields, true));
assert(!in_array('seed', $sourceFields, true), 'seed only exists for valheim');

// goldsrc inherits source's field list rather than redeclaring it
assert((new GoldSourceSchema())->supportedFields() === $sourceFields);

$mcFields = (new MinecraftJavaSchema())->supportedFields();
assert(in_array('map', $mcFields, true) && !in_array('bots', $mcFields, true), 'minecraft has no bot concept');

// valheim answers A2S, but several of the fields that come back are meaningless or wrong for
// it - these must never be offered, no matter what the underlying protocol technically returns
$valheimFields = (new ValheimSchema())->supportedFields();
foreach (['map', 'bots', 'version', 'secure', 'gameMode', 'os'] as $wrong) {
    assert(!in_array($wrong, $valheimFields, true), "valheim must not offer $wrong");
}
assert(in_array('seed', $valheimFields, true) && in_array('joinCode', $valheimFields, true));

// every field a schema claims to support must be a real QueryResult field, and every schema
// must be resolvable through get() - otherwise the admin could offer a checkbox that always
// throws or silently does nothing
foreach ([new SourceSchema(), new GoldSourceSchema(), new MinecraftJavaSchema(), new ValheimSchema()] as $schema) {
    foreach ($schema->supportedFields() as $field) {
        assert(in_array($field, QueryResult::FIELDS, true), get_class($schema) . " supports unknown field $field");
    }
}

// crossplay servers never answer A2S, so the player count comes out of their own log
$log = [
    '09/10/2026 22:35:40: Session \"<color=lime>Tormax</color>\" registered with join code 001340',
    '09/10/2026 22:35:44: Session \"<color=lime>Tormax</color>\" with join code 688911 and IP 89.245.16.209:2456 is active with 0 player(s)',
    '09/10/2026 22:43:19: Got character ZDOID from Ichfrauson : 3090414758:5',
    '09/10/2026 22:44:02: Session \"<color=lime>Tormax</color>\" with join code 688911 and IP 89.245.16.209:2456 is active with 2 player(s)',
];

assert(ValheimLogParser::players($log) === 2, 'the newest count wins, not the first');
assert(ValheimLogParser::hostname($log) === 'Tormax', 'markup is stripped here too');
assert(ValheimLogParser::joinCode($log) === '688911');
assert(ValheimLogParser::players([]) === null, 'empty log is not zero players');
assert(ValheimLogParser::players(['nothing to see here']) === null);

// only the registration line present: no count yet, but name and code are already known
assert(ValheimLogParser::players([$log[0]]) === null);
assert(ValheimLogParser::joinCode([$log[0]]) === '001340');

// a CS2 server whose 12/12 are mostly bots
$cs2 = new QueryResult(
    hostname: 'Test Server',
    players: 12,
    maxPlayers: 12,
    map: 'de_dust2',
    bots: 10,
    secure: true,
    playerList: [new QueryPlayer('Alice', score: 5, playtime: 300)],
);

assert($cs2->humanPlayers() === 2, 'bots must not count as human players');
assert($cs2->get('players') === '12 / 12', 'players renders as a pair');
assert($cs2->get('map') === 'de_dust2');
assert($cs2->get('version') === null, 'unreported field stays null');
assert($cs2->get('nonsense') === null, 'unknown field must not throw');
assert($cs2->toArray()['players_list'][0]['score'] === 5);

// a Minecraft ping result: no map, no bots, but an icon
$mc = new QueryResult(
    hostname: 'MC',
    players: 0,
    maxPlayers: 20,
    version: '1.21',
    icon: 'data:image/png;base64,AAAA',
    playerList: [],
);

assert($mc->humanPlayers() === 0, 'null bots must not break the subtraction');
assert($mc->get('map') === null, 'ping reports no map');
assert($mc->get('icon') === 'data:image/png;base64,AAAA');
assert($mc->playerList === [], 'empty list differs from null: nobody online vs no list at all');
assert($mc->toArray()['players_list'] === []);

// a protocol with no player list at all
$bedrockish = new QueryResult(hostname: 'X', players: 3, maxPlayers: 10);

assert($bedrockish->playerList === null);
assert($bedrockish->toArray()['players_list'] === null, 'null list must survive toArray');
assert($bedrockish->humanPlayers() === 3);

// every advertised field must be gettable, otherwise the admin checkbox list offers a dead option
foreach (QueryResult::FIELDS as $field) {
    assert($cs2->get($field) !== false, "field $field is not resolvable");
}

echo "ok\n";
