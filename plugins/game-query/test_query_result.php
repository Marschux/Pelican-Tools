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

use Marschux\GameQuery\Query\FwlSeedParser;
use Marschux\GameQuery\Query\QueryPlayer;
use Marschux\GameQuery\Query\QueryResult;

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
