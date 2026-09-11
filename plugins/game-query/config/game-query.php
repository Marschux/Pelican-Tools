<?php

return [
    // node | allocation | alias - see Marschux\GameQuery\Models\GameQuery::resolveAddress()
    'address_source' => env('GAME_QUERY_ADDRESS_SOURCE', 'node'),

    'timeout' => (int) env('GAME_QUERY_TIMEOUT', 3),
    'cache_ttl' => (int) env('GAME_QUERY_CACHE_TTL', 15),

    // a failed query is the normal case for an offline server, so it is not logged by default
    'debug' => (bool) env('GAME_QUERY_DEBUG', false),
];
