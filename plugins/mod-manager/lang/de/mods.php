<?php

return [
    'navigation' => 'Mods',

    'tabs' => [
        'installed' => 'Installiert',
        'browse' => 'Durchsuchen',
    ],

    'nothing_installed' => 'Noch keine Mods installiert.',
    'search' => 'Suchen',
    'search_placeholder' => 'Mods suchen',
    'all_categories' => 'Alle Kategorien',
    'orderings' => [
        'most-downloaded' => 'Meiste Downloads',
        'top-rated' => 'Beste Bewertung',
        'last-updated' => 'Zuletzt aktualisiert',
        'newest' => 'Neueste',
    ],
    'by' => 'von :author',
    'downloads' => 'Downloads',
    'rating' => 'Bewertung',
    'deprecated' => 'Veraltet',
    'install' => 'Installieren',
    'update' => 'Aktualisieren',
    'details' => 'Details',
    'no_results' => 'Nichts gefunden.',
    'previous' => 'Vorherige Seite',
    'next' => 'Nächste Seite',
    'website' => 'Website',
    'dependencies' => 'Abhängigkeiten',
    'no_dependencies' => 'Keine',
    'loader_dependency' => 'Mod-Loader',
    'versions' => 'Versionen',

    'loader' => [
        'ready' => 'Installiert',
        'not_started' => 'Installiert, Server startet nicht darüber',
        'missing' => 'Nicht installiert',
        'hint' => 'Mods laden nur über :loader.',
    ],

    'messages' => [
        'search_failed' => 'Die Suche ist fehlgeschlagen, später nochmal versuchen.',
        'details_failed' => 'Details konnten nicht geladen werden, später nochmal versuchen.',
        'not_yet' => 'Installieren kommt im nächsten Schritt.',
    ],

    'profile' => [
        'label' => 'Mod-Profil|Mod-Profile',
        'none' => 'Keine Mod-Profile',
        'fields' => [
            'preset' => 'Vorlage',
            'preset_hint' => 'Füllt die Felder unten mit einem eingebauten Spiel, danach frei anpassbar.',
            'name' => 'Bezeichnung',
            'eggs' => 'Eggs',
            'eggs_hint' => 'Server mit diesen Eggs bekommen die Mods-Seite. Ein Egg kann nur ein Profil haben.',
            'source_section' => 'Mods',
            'source' => 'Quelle',
            'community' => 'Community',
            'community_hint' => 'Das Spiel auf der Quelle, z. B. "valheim" in thunderstore.io/c/valheim.',
            'mods_dir' => 'Mod-Ordner',
            'config_dir' => 'Config-Ordner',
            'hidden_packages' => 'Ausgeblendete Pakete',
            'hidden_packages_hint' => 'Nie angezeigt und nie als Abhängigkeit installiert, als Namespace-Name.',
            'loader_section' => 'Mod-Loader',
            'loader_name' => 'Name',
            'loader_package' => 'Paket',
            'loader_package_hint' => 'Der Loader auf der Quelle, als Namespace-Name.',
            'loader_check' => 'Prüfpfad',
            'loader_check_hint' => 'Ein Pfad, den es gibt, sobald der Loader installiert ist.',
            'start_prefix' => 'Zusatz zum Startbefehl',
            'start_prefix_hint' => 'Wird beim Installieren des Loaders vor den Startbefehl des Servers gesetzt, damit das Spiel darüber startet. Das Egg bleibt unverändert.',
        ],
    ],
];
