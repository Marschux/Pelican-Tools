<?php

return [
    'button' => 'Clear cache',
    'confirm' => 'Clears the panel\'s caches and PHP\'s code cache and restarts the queue workers, like "php artisan optimize:clear" and a PHP-FPM restart. Useful after copying a plugin by hand or changing .env. The next page load takes a moment longer.',
    'done' => 'Cache cleared',
    'failed' => 'Clearing the cache failed',
    'result' => [
        'reset' => 'Panel caches and PHP\'s code cache are cleared, the queue workers restart.',
        'off' => 'Panel caches are cleared, the queue workers restart. OPcache is off, so PHP keeps no code to reset.',
        'blocked' => 'Panel caches are cleared, but PHP does not allow resetting its code cache here (opcache.restrict_api or disable_functions). Restart PHP-FPM yourself so changed PHP files are read.',
        'failed' => 'The details are in the panel\'s log.',
    ],
];
