<?php

return [
    'button' => 'Vaciar caché',
    'confirm' => 'Vacía las cachés del panel y la caché de código de PHP y reinicia los workers de la cola, como "php artisan optimize:clear" y reiniciar PHP-FPM. Útil tras copiar un plugin a mano o cambiar el .env. La siguiente carga de página tarda un poco más.',
    'done' => 'Caché vaciada',
    'failed' => 'No se pudo vaciar la caché',
    'result' => [
        'reset' => 'Las cachés del panel y la caché de código de PHP están vacías, los workers de la cola se reinician.',
        'off' => 'Las cachés del panel están vacías, los workers de la cola se reinician. OPcache está desactivado, así que PHP no tiene caché de código que reiniciar.',
        'blocked' => 'Las cachés del panel están vacías, pero PHP no permite reiniciar aquí su caché de código (opcache.restrict_api o disable_functions). Reinicia PHP-FPM tú mismo para que se lean los archivos PHP cambiados.',
        'failed' => 'Los detalles están en el log del panel.',
    ],
];
