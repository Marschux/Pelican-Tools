<?php

return [
    'navigation' => 'Mods',

    'tabs' => [
        'installed' => 'Instalados',
        'browse' => 'Explorar',
    ],

    'nothing_installed' => 'Todavía no hay mods instalados.',
    'search' => 'Buscar',
    'search_placeholder' => 'Buscar mods',
    'all_categories' => 'Todas las categorías',
    'orderings' => [
        'most-downloaded' => 'Más descargados',
        'top-rated' => 'Mejor valorados',
        'last-updated' => 'Actualizados recientemente',
        'newest' => 'Más nuevos',
    ],
    'by' => 'de :author',
    'downloads' => 'Descargas',
    'rating' => 'Valoración',
    'deprecated' => 'Obsoleto',
    'install' => 'Instalar',
    'update' => 'Actualizar',
    'details' => 'Detalles',
    'no_results' => 'No se encontró nada.',
    'previous' => 'Página anterior',
    'next' => 'Página siguiente',
    'website' => 'Sitio web',
    'dependencies' => 'Dependencias',
    'no_dependencies' => 'Ninguna',
    'loader_dependency' => 'Cargador de mods',
    'versions' => 'Versiones',

    'loader' => [
        'ready' => 'Instalado',
        'not_started' => 'Instalado, el servidor no arranca con él',
        'missing' => 'No instalado',
        'hint' => 'Los mods solo se cargan a través de :loader.',
    ],

    'messages' => [
        'search_failed' => 'La búsqueda falló, inténtalo más tarde.',
        'details_failed' => 'No se pudieron cargar los detalles, inténtalo más tarde.',
        'not_yet' => 'La instalación llega en el siguiente paso.',
    ],

    'profile' => [
        'label' => 'Perfil de mods|Perfiles de mods',
        'none' => 'No hay perfiles de mods',
        'fields' => [
            'preset' => 'Plantilla',
            'preset_hint' => 'Rellena los campos de abajo con un juego incluido, que luego puedes cambiar.',
            'name' => 'Nombre',
            'eggs' => 'Eggs',
            'eggs_hint' => 'Los servidores con estos eggs tienen la página de mods. Un egg solo puede tener un perfil.',
            'source_section' => 'Mods',
            'source' => 'Fuente',
            'community' => 'Comunidad',
            'community_hint' => 'El juego en la fuente, p. ej. "valheim" en thunderstore.io/c/valheim.',
            'mods_dir' => 'Carpeta de mods',
            'config_dir' => 'Carpeta de configuración',
            'hidden_packages' => 'Paquetes ocultos',
            'hidden_packages_hint' => 'Nunca se muestran ni se instalan como dependencia, como Namespace-Name.',
            'loader_section' => 'Cargador de mods',
            'loader_name' => 'Nombre',
            'loader_package' => 'Paquete',
            'loader_package_hint' => 'El cargador en la fuente, como Namespace-Name.',
            'loader_check' => 'Ruta de comprobación',
            'loader_check_hint' => 'Una ruta que existe una vez instalado el cargador.',
            'start_prefix' => 'Prefijo del comando de inicio',
            'start_prefix_hint' => 'Se antepone al comando de inicio del servidor al instalar el cargador, para que el juego arranque con él. El egg no se modifica.',
        ],
    ],
];
