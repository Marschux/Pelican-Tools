<?php

return [
    'navigation' => 'Mods',

    'tabs' => [
        'installed' => 'Installed',
        'browse' => 'Browse',
    ],

    'nothing_installed' => 'No mods installed yet.',
    'search' => 'Search',
    'search_placeholder' => 'Search mods',
    'all_categories' => 'All categories',
    'orderings' => [
        'most-downloaded' => 'Most downloaded',
        'top-rated' => 'Top rated',
        'last-updated' => 'Last updated',
        'newest' => 'Newest',
    ],
    'by' => 'by :author',
    'downloads' => 'Downloads',
    'rating' => 'Rating',
    'deprecated' => 'Deprecated',
    'install' => 'Install',
    'update' => 'Update',
    'details' => 'Details',
    'no_results' => 'Nothing found.',
    'previous' => 'Previous page',
    'next' => 'Next page',
    'website' => 'Website',
    'dependencies' => 'Dependencies',
    'no_dependencies' => 'None',
    'loader_dependency' => 'Mod loader',
    'versions' => 'Versions',

    'loader' => [
        'ready' => 'Installed',
        'not_started' => 'Installed, not started through it',
        'missing' => 'Not installed',
        'hint' => 'Mods only load through :loader.',
    ],

    'messages' => [
        'search_failed' => 'The search failed, try again later.',
        'details_failed' => 'Could not load the details, try again later.',
        'not_yet' => 'Installing comes in the next step.',
    ],

    'profile' => [
        'label' => 'Mod profile|Mod profiles',
        'none' => 'No mod profiles',
        'fields' => [
            'preset' => 'Preset',
            'preset_hint' => 'Fills the fields below with a built-in game, which you can then change.',
            'name' => 'Name',
            'eggs' => 'Eggs',
            'eggs_hint' => 'Servers with these eggs get the mods page. An egg can have one profile only.',
            'source_section' => 'Mods',
            'source' => 'Source',
            'community' => 'Community',
            'community_hint' => 'The game on the source, e.g. "valheim" in thunderstore.io/c/valheim.',
            'mods_dir' => 'Mods folder',
            'config_dir' => 'Config folder',
            'hidden_packages' => 'Hidden packages',
            'hidden_packages_hint' => 'Never shown and never installed as a dependency, as Namespace-Name.',
            'loader_section' => 'Mod loader',
            'loader_name' => 'Name',
            'loader_package' => 'Package',
            'loader_package_hint' => 'The loader on the source, as Namespace-Name.',
            'loader_check' => 'Check path',
            'loader_check_hint' => 'A path that exists once the loader is installed.',
            'start_prefix' => 'Start command prefix',
            'start_prefix_hint' => 'Put in front of a server\'s start command when the loader is installed, so the game starts through it. The egg is not changed.',
        ],
    ],
];
