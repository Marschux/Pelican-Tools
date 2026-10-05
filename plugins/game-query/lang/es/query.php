<?php

return [
    'query' => 'Consulta de juego|Consultas de juego',
    'no_queries' => 'No hay consultas de juego',
    'name' => 'Nombre',
    'no_name' => 'Sin nombre',
    'name_hint' => 'Opcional. Solo sirve para distinguir las entradas de esta lista.',
    'type' => 'Tipo',
    'query_label' => 'Consulta',
    'no_query' => 'Sin configurar',
    'test' => 'Probar',
    'eggs' => 'Eggs',
    'no_eggs' => 'Sin eggs',
    'yes' => 'Sí',
    'no' => 'No',

    'port_offset' => 'Desplazamiento del puerto de consulta',
    'no_offset' => 'Sin desplazamiento',
    'port_offset_hint' => 'Se suma al puerto de la asignación. Normalmente vacío/0 o 1.',
    'port_variable' => 'Variable del puerto de consulta',
    'no_variable' => 'Sin variable',
    'port_variable_hint' => 'Nombre de entorno de la variable de inicio que contiene el puerto de consulta, p. ej. "QUERY_PORT". Si está definida, se ignora el desplazamiento del puerto.',

    'visible_fields' => 'Campos visibles',
    'visible_fields_order' => 'Arrastra o usa las flechas para ordenar. Los campos marcados se muestran en la página del servidor, en este orden.',
    'field_shown' => 'Visible',
    'field_label' => 'Campo',
    'visible_fields_hint' => 'Qué campos muestra la página del servidor. Solo se ofrecen los campos que el tipo seleccionado puede informar, y un campo solo aparece cuando el protocolo lo ha informado.',

    'field' => [
        'hostname' => 'Nombre del servidor',
        'players' => 'Jugadores',
        'map' => 'Mapa',
        'bots' => 'Bots',
        'version' => 'Versión',
        'gameMode' => 'Modo de juego',
        'passworded' => 'Protegido con contraseña',
        'whitelist' => 'Lista blanca',
        'password' => 'Contraseña',
        'secure' => 'VAC',
        'world' => 'Mundo',
        'seed' => 'Semilla del mundo',
        'icon' => 'Icono del servidor',
    ],

    'gamemodes' => [
        'survival' => 'Supervivencia',
        'creative' => 'Creativo',
        'adventure' => 'Aventura',
        'spectator' => 'Espectador',
    ],

    'bots_count' => ':count bots',
    'click_to_reveal' => 'Haz clic para mostrar',

    'notifications' => [
        'test_no_server' => 'Ningún servidor usa uno de los eggs vinculados',
        'test_failed' => 'La consulta ha fallado',
        'test_failed_body' => 'Sin respuesta de :server. Comprueba el desplazamiento o la variable del puerto, el ajuste de la dirección de consulta y si el servidor está en marcha.',
        'test_success' => 'Consulta correcta (:server)',
    ],
];
