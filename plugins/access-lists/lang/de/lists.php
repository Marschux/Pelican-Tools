<?php

return [
    'list' => 'Zugriffsliste|Zugriffslisten',
    'no_lists' => 'Keine Zugriffslisten',

    'fields' => [
        'preset' => 'Vorlage',
        'preset_hint' => 'Füllt die Dateien unten mit einem eingebauten Spiel, danach frei anpassbar. Ersetzt die bereits eingetragenen Dateien.',
        'name' => 'Bezeichnung',
        'name_placeholder' => 'z. B. Minecraft',
        'files' => 'Dateien',
        'add_file' => 'Datei hinzufügen',
        'label' => 'Bezeichnung der Datei',
        'label_placeholder' => 'z. B. Admins',
        'path' => 'Dateipfad',
        'path_hint' => 'Pfad relativ zum Serververzeichnis, z. B. "savegames/adminlist.txt" oder "whitelist.json".',
        'format' => 'Format',
        'key_field' => 'Schlüsselfeld',
        'key_field_hint' => 'Feld, das einen Eintrag eindeutig macht, z. B. "uuid" oder "ip".',
        'display_field' => 'Anzeigefeld',
        'display_field_hint' => 'Feld, das in der Bearbeitungsmaske steht und dort eingegeben wird, z. B. "name".',
        'entry_template' => 'Vorlage für neue Einträge',
        'entry_template_hint' => 'JSON-Objekt für einen neuen Eintrag. Platzhalter: {name}, {uuid}, {ip}, {now}. Enthält die Vorlage {uuid}, sucht das Panel die UUID zum Namen (Mojang, oder lokal berechnet im Offline-Modus).',
        'eggs' => 'Eggs',
    ],

    'formats' => [
        'lines' => 'Textzeilen',
        'json' => 'JSON',
    ],

    'page' => [
        'navigation' => 'Zugriffslisten',
        'entries' => 'Einträge',
        'entry' => 'Eintrag',
        'add' => 'Eintrag hinzufügen',
        'raw' => 'Als Text bearbeiten',
        'raw_hint' => 'Bearbeitet die Datei unverändert. Beim Speichern wird nur geprüft, ob JSON gültig ist.',
        'text' => 'Dateiinhalt',
        'save' => 'Speichern',
        'reload' => 'Neu laden',
    ],

    'messages' => [
        'saved' => 'Gespeichert',
        'reloaded' => 'Neu geladen',
        'failed' => 'Speichern fehlgeschlagen',
        'unreadable' => 'Die Datei ist kein gültiges JSON und wurde deshalb nicht überschrieben',
        'invalid_json' => 'Der eingegebene Text ist kein gültiges JSON',
        'unknown_player' => 'Spieler bei Mojang nicht gefunden: :name',
        'mojang_unreachable' => 'Mojang ist nicht erreichbar',
        'no_lists' => 'Für dieses Egg ist keine Zugriffsliste eingerichtet',
        'missing_file' => 'Die Datei gibt es noch nicht - beim Speichern wird sie angelegt',
    ],
];
