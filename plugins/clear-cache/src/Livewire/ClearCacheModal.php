<?php

namespace Marschux\ClearCache\Livewire;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

/**
 * The confirm modal of the plugin list's "clear cache" icon, a component of its own: a modal of
 * the table action would come back in a second request, where Filament looks the action up
 * before PluginListButton has pushed it onto the table. The icon only opens this modal.
 *
 * What "php artisan optimize:clear && systemctl restart php8.4-fpm" does, from inside the panel:
 * Laravel's caches are cleared through Artisan, and the restart - only there so PHP forgets the
 * old code - becomes opcache_reset(). No path, no PHP version, no root needed.
 */
class ClearCacheModal extends Component
{
    public const ID = 'clear-cache';

    public static function allowed(): bool
    {
        return user()?->can('update plugin') ?? false;
    }

    public function clear(): void
    {
        abort_unless(self::allowed(), 403);

        $result = self::run();

        Notification::make()
            ->title(trans($result === 'failed' ? 'clear-cache::clear-cache.failed' : 'clear-cache::clear-cache.done'))
            ->body(trans("clear-cache::clear-cache.result.$result"))
            ->status($result === 'reset' || $result === 'off' ? 'success' : 'warning')
            ->persistent($result === 'blocked' || $result === 'failed')
            ->send();

        $this->dispatch('close-modal', id: self::ID);
    }

    /** @return string reset, off (no OPcache to reset), blocked (PHP-FPM needs a restart by hand) or failed */
    private static function run(): string
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

    public function render(): View
    {
        return view('clear-cache::modal');
    }
}
