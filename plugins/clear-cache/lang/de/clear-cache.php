<?php

return [
    'button' => 'Cache leeren',
    'confirm' => 'Leert die Caches des Panels und den Code-Cache von PHP und startet die Queue-Worker neu, wie "php artisan optimize:clear" und ein Neustart von PHP-FPM. Hilfreich, nachdem ein Plugin von Hand kopiert oder die .env geändert wurde. Der nächste Seitenaufruf dauert etwas länger.',
    'done' => 'Cache geleert',
    'failed' => 'Cache leeren fehlgeschlagen',
    'result' => [
        'reset' => 'Die Caches des Panels und der Code-Cache von PHP sind geleert, die Queue-Worker starten neu.',
        'off' => 'Die Caches des Panels sind geleert, die Queue-Worker starten neu. OPcache ist aus, PHP hat also keinen Code-Cache zum Zurücksetzen.',
        'blocked' => 'Die Caches des Panels sind geleert, aber PHP erlaubt hier nicht, den Code-Cache zurückzusetzen (opcache.restrict_api oder disable_functions). Starte PHP-FPM selbst neu, damit geänderte PHP-Dateien gelesen werden.',
        'failed' => 'Die Details stehen im Log des Panels.',
    ],
];
