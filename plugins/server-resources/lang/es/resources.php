<?php

return [
    'cpu' => 'CPU',
    'memory' => 'Memoria',
    'disk' => 'Disco',

    'host' => [
        'heading' => 'Host del panel',
        'cores' => ':count núcleos',
        'load' => 'Carga',
        'load_hint' => '1 · 5 · 15 minutos',
        'uptime' => 'Tiempo activo',
    ],

    'node' => [
        'maintenance' => 'Modo de mantenimiento',
        'unreachable' => 'Nodo inaccesible',
        'no_servers' => 'No hay servidores en este nodo',
        'none' => 'No hay nodos configurados',
        'cpus' => ':count núcleos (límite :limit %)',
        'servers' => ':count servidores',
    ],

    'power' => [
        'start' => 'Iniciar',
        'restart' => 'Reiniciar',
        'stop' => 'Detener',
        'not_now' => 'No es posible en el estado actual',
        'failed' => 'No se pudo enviar el comando',
        'sent' => [
            'start' => 'Iniciando :server',
            'restart' => 'Reiniciando :server',
            'stop' => 'Deteniendo :server',
        ],
    ],

    'server' => [
        'external_id' => 'ID externo',
        'name' => 'Servidor',
        'egg' => 'Egg',
        'port' => 'Puerto',
        'status' => 'Estado',
        'bots' => ':count bots',
        'players' => 'Jugadores',
    ],
];
