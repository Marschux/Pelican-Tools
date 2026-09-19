<?php

return [
    'query' => 'Game Query|Game Queries',
    'no_queries' => 'No Game Queries',
    'name' => 'Label',
    'no_name' => 'Unnamed',
    'name_hint' => 'Optional. Only used to tell the entries in this list apart.',
    'type' => 'Type',
    'query_label' => 'Query',
    'query_failed' => 'No answer',
    'no_query' => 'Not configured',
    'test' => 'Test',
    'eggs' => 'Eggs',
    'no_eggs' => 'No Eggs',
    'yes' => 'Yes',
    'no' => 'No',

    'port_offset' => 'Query Port Offset',
    'no_offset' => 'No Offset',
    'port_offset_hint' => 'Added to the allocation port. Usually empty/0 or 1.',
    'port_variable' => 'Query Port Variable',
    'no_variable' => 'No Variable',
    'port_variable_hint' => 'Env name of the startup variable holding the query port, e.g. "QUERY_PORT". When set, the port offset is ignored.',

    'visible_fields' => 'Visible Fields',
    'visible_fields_order' => 'Drag or use the arrows to order. Ticked fields show on the server page, in this order.',
    'field_shown' => 'Shown',
    'field_label' => 'Field',
    'visible_fields_hint' => 'Which fields the server page shows. Only fields the selected type can actually report are offered, and a field only appears once the protocol has reported it.',

    'field' => [
        'hostname' => 'Hostname',
        'players' => 'Players',
        'map' => 'Map',
        'bots' => 'Bots',
        'version' => 'Version',
        'gameMode' => 'Game Mode',
        'passworded' => 'Password Protected',
        'whitelist' => 'Whitelist',
        'password' => 'Password',
        'secure' => 'VAC',
        'world' => 'World',
        'seed' => 'World Seed',
        'icon' => 'Server Icon',
    ],

    'gamemodes' => [
        'survival' => 'Survival',
        'creative' => 'Creative',
        'adventure' => 'Adventure',
        'spectator' => 'Spectator',
    ],

    'bots_count' => ':count bots',
    'click_to_reveal' => 'Click to reveal',

    'notifications' => [
        'test_no_server' => 'No server uses one of the linked eggs',
        'test_failed' => 'Query failed',
        'test_failed_body' => 'No answer from :server. Check the port offset/variable, the query address setting and whether the server is running.',
        'test_success' => 'Query successful (:server)',
    ],
];
