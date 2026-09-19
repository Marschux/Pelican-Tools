<?php

return [
    'cpu' => 'CPU',
    'memory' => 'Arbeitsspeicher',
    'disk' => 'Speicher',

    'host' => [
        'heading' => 'Panel-Host',
        'cores' => ':count Kerne',
        'load' => 'Last',
        'load_hint' => '1 · 5 · 15 Minuten',
        'uptime' => 'Laufzeit',
    ],

    'node' => [
        'maintenance' => 'Wartungsmodus',
        'unreachable' => 'Node nicht erreichbar',
        'no_servers' => 'Keine Server auf diesem Node',
        'none' => 'Keine Nodes eingerichtet',
        'cpus' => ':count Kerne (Limit :limit %)',
        'servers' => ':count Server',
    ],

    'power' => [
        'start' => 'Starten',
        'restart' => 'Neustarten',
        'stop' => 'Stoppen',
        'not_now' => 'Im aktuellen Zustand nicht möglich',
        'failed' => 'Befehl konnte nicht gesendet werden',
        'sent' => [
            'start' => ':server wird gestartet',
            'restart' => ':server wird neu gestartet',
            'stop' => ':server wird gestoppt',
        ],
    ],

    'server' => [
        'external_id' => 'Externe ID',
        'name' => 'Server',
        'egg' => 'Egg',
        'port' => 'Port',
        'status' => 'Status',
        'bots' => ':count Bots',
        'players' => 'Spieler',
    ],
];
