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
    'options' => 'Options',
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
    'visible_fields_hint' => 'Which fields the server page shows. A field only appears if it is checked AND the protocol actually reported it.',

    'field' => [
        'hostname' => 'Hostname',
        'players' => 'Players',
        'map' => 'Map',
        'bots' => 'Bots',
        'version' => 'Version',
        'gameMode' => 'Game Mode',
        'passworded' => 'Password',
        'secure' => 'VAC',
        'os' => 'OS',
        'seed' => 'World Seed',
        'icon' => 'Server Icon',
    ],

    'history' => 'Player History',

    'player_actions' => 'Player Actions',
    'player_actions_hint' => 'Console commands offered per player on the players page. {player} is replaced with the player name. Requires the "control.console" permission.',
    'add_action' => 'Add action',
    'action_label' => 'Label',
    'action_command' => 'Command',
    'action_command_hint' => 'Use {player} as placeholder',
    'action_icon' => 'Icon',
    'action_confirm' => 'Confirm?',

    'player' => [
        'name' => 'Name',
        'score' => 'Score',
        'playtime' => 'Playtime',
    ],

    'players_with_bots' => ':players / :max (+:bots bots)',

    'fetch_rules' => 'Fetch server rules?',
    'fetch_rules_hint' => 'Requests the server cvars (A2S_RULES) or plugin list. Extra roundtrip and hundreds of entries on GMod/CS2, so off by default. Shown on the players page only.',
    'track_history' => 'Track history?',
    'track_history_hint' => 'Samples the player count on a schedule and draws a graph on the server page.',

    'address_source' => 'Query Address',
    'address_source_hint' => 'Which address the panel queries. "Node" works even when the allocation ip is 0.0.0.0/:: or a local ip, because docker publishes the port on the node itself.',
    'address_node' => 'Node address (recommended)',
    'address_allocation' => 'Allocation IP',
    'address_alias' => 'Allocation alias',

    'timeout' => 'Query Timeout',
    'timeout_hint' => 'How long to wait for an answer. Lower means offline servers block the page for less time.',
    'cache_ttl' => 'Cache Duration',
    'cache_ttl_hint' => 'How long a result is reused. Without this every open tab and api call opens its own socket. 0 disables caching.',
    'history_interval' => 'History Interval',
    'history_interval_hint' => 'How often the player count is sampled for the graph.',
    'history_retention' => 'History Retention',
    'history_retention_hint' => 'Samples older than this are deleted.',
    'debug' => 'Log query errors?',
    'debug_hint' => 'Off by default - an offline server is the normal case, and would otherwise log an exception on every poll.',

    'table' => [
        'no_players' => 'No players found',
        'no_players_description' => 'Nobody is connected right now',
        'failed' => 'Query did not answer',
        'failed_description' => 'The server is running but did not respond. Check the port offset and the query address in the plugin settings.',
        'server_offline' => 'Server is offline',
    ],

    'notifications' => [
        'settings_saved' => 'Settings saved',
        'test_no_server' => 'No server uses one of the linked eggs',
        'test_failed' => 'Query failed',
        'test_failed_body' => 'No answer from :server. Check the port offset/variable, the query address setting and whether the server is running.',
        'test_success' => 'Query successful (:server)',
        'action_sent' => 'Command sent',
        'action_failed' => 'Could not send command',
    ],
];
