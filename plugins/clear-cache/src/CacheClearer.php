<?php

namespace Marschux\ClearCache;

use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * What "php artisan optimize:clear && systemctl restart php8.4-fpm" does, from inside the panel:
 * Laravel's caches are cleared through Artisan, and the restart - only there so PHP forgets the
 * old code - becomes opcache_reset(). No path, no PHP version, no root needed.
 */
class CacheClearer
{
    /** @return string reset, off (no OPcache to reset), blocked (PHP-FPM needs a restart by hand) or failed */
    public static function run(): string
    {
        try {
            Artisan::call('optimize:clear');
            // workers keep the old code until they start again; they finish their job first
            Artisan::call('queue:restart');
        } catch (Throwable $exception) {
            report($exception);

            return 'failed';
        }

        if (!extension_loaded('Zend OPcache') || !ini_get('opcache.enable')) {
            return 'off';
        }

        // false (with a warning) when opcache.restrict_api leaves this script out
        return function_exists('opcache_reset') && @opcache_reset() ? 'reset' : 'blocked';
    }
}
