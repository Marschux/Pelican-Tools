<?php

return [
    'cpu' => 'CPU',
    'memory' => 'Memory',
    'disk' => 'Disk',

    'host' => [
        'heading' => 'Panel host',
        'cores' => ':count cores',
        'load' => 'Load',
        'load_hint' => '1 · 5 · 15 minutes',
        'uptime' => 'Uptime',
    ],

    'node' => [
        'maintenance' => 'Maintenance mode',
        'unreachable' => 'Node unreachable',
        'no_servers' => 'No servers on this node',
        'none' => 'No nodes set up',
        'cpus' => ':count cores (limit :limit %)',
        'servers' => ':count servers',
    ],

    'power' => [
        'start' => 'Start',
        'restart' => 'Restart',
        'stop' => 'Stop',
        'not_now' => 'Not possible in the current state',
        'failed' => 'Could not send the command',
        'sent' => [
            'start' => 'Starting :server',
            'restart' => 'Restarting :server',
            'stop' => 'Stopping :server',
        ],
    ],

    'server' => [
        'external_id' => 'External ID',
        'name' => 'Server',
        'egg' => 'Egg',
        'port' => 'Port',
        'status' => 'Status',
        'players' => 'Players',
    ],
];
