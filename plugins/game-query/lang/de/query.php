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
    'options' => 'Optionen',
    'eggs' => 'Eggs',
    'no_eggs' => 'Keine Eggs',
    'yes' => 'Ja',
    'no' => 'Nein',

    'port_offset' => 'Query Port Offset',
    'no_offset' => 'Kein Offset',
    'port_offset_hint' => 'Wird zum Allocation-Port addiert. Normalerweise leer/0 oder 1.',
    'port_variable' => 'Query Port Variable',
    'no_variable' => 'Keine Variable',
    'port_variable_hint' => 'Env-Name der Startup-Variable mit dem Query-Port, z.B. "QUERY_PORT". Wenn gesetzt, wird der Port-Offset ignoriert.',

    'visible_fields' => 'Sichtbare Felder',
    'visible_fields_hint' => 'Welche Felder die Server-Seite anzeigt. Ein Feld erscheint nur, wenn es angehakt ist UND das Protokoll es geliefert hat.',

    'field' => [
        'hostname' => 'Hostname',
        'players' => 'Spieler',
        'map' => 'Karte',
        'bots' => 'Bots',
        'version' => 'Version',
        'gameMode' => 'Spielmodus',
        'passworded' => 'Passwort',
        'secure' => 'VAC',
        'os' => 'Betriebssystem',
        'joinCode' => 'Beitrittscode',
        'seed' => 'Welt-Seed',
        'icon' => 'Server-Icon',
    ],

    'history' => 'Spielerverlauf',

    'player_actions' => 'Spieler-Aktionen',
    'player_actions_hint' => 'Konsolenbefehle, die auf der Spieler-Seite pro Spieler angeboten werden. {player} wird durch den Spielernamen ersetzt. Erfordert die Berechtigung "control.console".',
    'add_action' => 'Aktion hinzufügen',
    'action_label' => 'Bezeichnung',
    'action_command' => 'Befehl',
    'action_command_hint' => '{player} als Platzhalter verwenden',
    'action_icon' => 'Icon',
    'action_confirm' => 'Bestätigen?',

    'player' => [
        'name' => 'Name',
        'score' => 'Punkte',
        'playtime' => 'Spielzeit',
    ],

    'players_with_bots' => ':players / :max (+:bots Bots)',

    'fetch_rules' => 'Server-Regeln abfragen?',
    'fetch_rules_hint' => 'Fragt die Server-cvars (A2S_RULES) bzw. die Plugin-Liste ab. Zusätzlicher Roundtrip und bei GMod/CS2 hunderte Einträge, daher standardmäßig aus. Wird nur auf der Spieler-Seite angezeigt.',
    'track_history' => 'Verlauf aufzeichnen?',
    'track_history_hint' => 'Zeichnet die Spielerzahl regelmäßig auf und zeigt einen Graphen auf der Server-Seite.',

    'address_source' => 'Query-Adresse',
    'address_source_hint' => 'Welche Adresse das Panel abfragt. "Node" funktioniert auch, wenn die Allocation-IP 0.0.0.0/:: oder eine lokale IP ist, weil Docker den Port auf der Node selbst veröffentlicht.',
    'address_node' => 'Node-Adresse (empfohlen)',
    'address_allocation' => 'Allocation-IP',
    'address_alias' => 'Allocation-Alias',

    'timeout' => 'Query Timeout',
    'timeout_hint' => 'Wie lange auf eine Antwort gewartet wird. Niedriger heißt, dass offline Server die Seite kürzer blockieren.',
    'cache_ttl' => 'Cache-Dauer',
    'cache_ttl_hint' => 'Wie lange ein Ergebnis wiederverwendet wird. Ohne Cache öffnet jeder offene Tab und jeder API-Aufruf einen eigenen Socket. 0 deaktiviert den Cache.',
    'history_interval' => 'Verlaufs-Intervall',
    'history_interval_hint' => 'Wie oft die Spielerzahl für den Graphen aufgezeichnet wird.',
    'history_retention' => 'Verlaufs-Aufbewahrung',
    'history_retention_hint' => 'Ältere Aufzeichnungen werden gelöscht.',
    'debug' => 'Query-Fehler loggen?',
    'debug_hint' => 'Standardmäßig aus - ein offline Server ist der Normalfall und würde sonst bei jedem Poll eine Exception loggen.',

    'table' => [
        'no_players' => 'Keine Spieler gefunden',
        'no_players_description' => 'Gerade ist niemand verbunden',
        'failed' => 'Query hat nicht geantwortet',
        'failed_description' => 'Der Server läuft, antwortet aber nicht. Prüfe den Port-Offset und die Query-Adresse in den Plugin-Einstellungen.',
        'server_offline' => 'Server ist offline',
    ],

    'notifications' => [
        'settings_saved' => 'Einstellungen gespeichert',
        'test_no_server' => 'Kein Server verwendet eines der verknüpften Eggs',
        'test_failed' => 'Query fehlgeschlagen',
        'test_failed_body' => 'Keine Antwort von :server. Prüfe Port-Offset/Variable, die Query-Adresse und ob der Server läuft.',
        'test_success' => 'Query erfolgreich (:server)',
        'action_sent' => 'Befehl gesendet',
        'action_failed' => 'Befehl konnte nicht gesendet werden',
    ],
];
