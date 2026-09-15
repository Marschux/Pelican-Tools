<?php

return [
    'query' => 'Game Query|Game Queries',
    'no_queries' => 'Keine Game Queries',
    'name' => 'Beschriftung',
    'no_name' => 'Ohne Beschriftung',
    'name_hint' => 'Optional. Dient nur dazu, die Einträge in dieser Liste auseinanderzuhalten.',
    'type' => 'Typ',
    'query_label' => 'Query',
    'query_failed' => 'Keine Antwort',
    'no_query' => 'Nicht konfiguriert',
    'test' => 'Testen',
    'eggs' => 'Eggs',
    'no_eggs' => 'Keine Eggs',
    'yes' => 'Ja',
    'no' => 'Nein',

    'port_offset' => 'Query Port Offset',
    'no_offset' => 'Kein Offset',
    'port_offset_hint' => 'Der Offset wird zum Allocation Port addiert. Normalerweise leer/0 oder 1.',
    'port_variable' => 'Query Port Variable',
    'no_variable' => 'Keine Variable',
    'port_variable_hint' => 'Der Env Name der Startup Variable, die für den Query Port verwendet werden soll, z.B. "QUERY_PORT". Wenn ein Wert gesetzt wurde wird der Query Port Offset ignoriert!',

    'visible_fields' => 'Sichtbare Felder',
    'visible_fields_hint' => 'Welche Felder die Server-Seite anzeigt. Angeboten werden nur Felder, die der gewählte Typ überhaupt liefern kann, und ein Feld erscheint erst, wenn das Protokoll es tatsächlich gemeldet hat.',

    'field' => [
        'hostname' => 'Hostname',
        'players' => 'Spieler',
        'map' => 'Karte',
        'bots' => 'Bots',
        'version' => 'Version',
        'gameMode' => 'Spielmodus',
        'passworded' => 'Passwortschutz',
        'whitelist' => 'Whitelist',
        'password' => 'Passwort',
        'secure' => 'VAC',
        'seed' => 'Welt-Seed',
        'icon' => 'Server-Icon',
    ],

    'gamemodes' => [
        'survival' => 'Überleben',
        'creative' => 'Kreativ',
        'adventure' => 'Abenteuer',
        'spectator' => 'Zuschauer',
    ],

    'click_to_reveal' => 'Zum Anzeigen klicken',

    'players_with_bots' => ':players / :max (+:bots Bots)',

    'notifications' => [
        'test_no_server' => 'Kein Server verwendet eines der verknüpften Eggs',
        'test_failed' => 'Query fehlgeschlagen',
        'test_failed_body' => 'Keine Antwort von :server. Prüfe Port-Offset/Variable, die Query-Adresse und ob der Server läuft.',
        'test_success' => 'Query erfolgreich (:server)',
    ],
];
