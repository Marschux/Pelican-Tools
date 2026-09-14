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

    'address_source' => 'Query-Adresse',
    'address_source_hint' => 'Welche Adresse das Panel abfragt. "Node" funktioniert auch, wenn die Allocation-IP 0.0.0.0/:: oder eine lokale IP ist, weil Docker den Port auf der Node selbst veröffentlicht.',
    'address_node' => 'Node-Adresse (empfohlen)',
    'address_allocation' => 'Allocation-IP',
    'address_alias' => 'Allocation-Alias',

    'timeout' => 'Query Timeout',
    'timeout_hint' => 'Wie lange auf eine Antwort gewartet wird. Niedriger heißt, dass offline Server die Seite kürzer blockieren.',
    'cache_ttl' => 'Cache-Dauer',
    'cache_ttl_hint' => 'Wie lange ein Ergebnis wiederverwendet wird. Ohne Cache öffnet jeder offene Tab und jeder API-Aufruf einen eigenen Socket. 0 deaktiviert den Cache.',
    'debug' => 'Query-Fehler loggen?',
    'debug_hint' => 'Standardmäßig aus - ein offline Server ist der Normalfall und würde sonst bei jedem Poll eine Exception loggen.',

    'notifications' => [
        'settings_saved' => 'Einstellungen gespeichert',
        'test_no_server' => 'Kein Server verwendet eines der verknüpften Eggs',
        'test_failed' => 'Query fehlgeschlagen',
        'test_failed_body' => 'Keine Antwort von :server. Prüfe Port-Offset/Variable, die Query-Adresse und ob der Server läuft.',
        'test_success' => 'Query erfolgreich (:server)',
    ],
];
