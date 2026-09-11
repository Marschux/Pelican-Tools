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
    'visible_fields_hint' => 'Which fields the server page shows. Only fields the selected type can actually report are offered, and a field only appears once the protocol has reported it.',

    'field' => [
        'hostname' => 'Hostname',
        'players' => 'Players',
        'map' => 'Map',
        'bots' => 'Bots',
        'version' => 'Version',
        'gameMode' => 'Game Mode',
        'passworded' => 'Password',
        'secure' => 'VAC',
        'joinCode' => 'Join Code',
        'seed' => 'World Seed',
        'icon' => 'Server Icon',
    ],

    'players_with_bots' => ':players / :max (+:bots bots)',

    'address_source' => 'Query Address',
    'address_source_hint' => 'Which address the panel queries. "Node" works even when the allocation ip is 0.0.0.0/:: or a local ip, because docker publishes the port on the node itself.',
    'address_node' => 'Node address (recommended)',
    'address_allocation' => 'Allocation IP',
    'address_alias' => 'Allocation alias',

    'timeout' => 'Query Timeout',
    'timeout_hint' => 'How long to wait for an answer. Lower means offline servers block the page for less time.',
    'cache_ttl' => 'Cache Duration',
    'cache_ttl_hint' => 'How long a result is reused. Without this every open tab and api call opens its own socket. 0 disables caching.',
    'debug' => 'Log query errors?',
    'debug_hint' => 'Off by default - an offline server is the normal case, and would otherwise log an exception on every poll.',

    'notifications' => [
        'settings_saved' => 'Settings saved',
        'test_no_server' => 'No server uses one of the linked eggs',
        'test_failed' => 'Query failed',
        'test_failed_body' => 'No answer from :server. Check the port offset/variable, the query address setting and whether the server is running.',
        'test_success' => 'Query successful (:server)',
    ],
];
