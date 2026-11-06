<?php

namespace Marschux\ClearCache\Livewire;

use Filament\Notifications\Notification;
use Illuminate\View\View;
use Livewire\Component;
use Marschux\ClearCache\CacheClearer;

/**
 * The confirm modal of the plugin list's "clear cache" icon, a component of its own: a modal of
 * the table action would come back in a second request, where Filament looks the action up
 * before PluginListButton has pushed it onto the table. The icon only opens this modal.
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

        $result = CacheClearer::run();

        Notification::make()
            ->title(trans($result === 'failed' ? 'clear-cache::clear-cache.failed' : 'clear-cache::clear-cache.done'))
            ->body(trans("clear-cache::clear-cache.result.$result"))
            ->status($result === 'reset' || $result === 'off' ? 'success' : 'warning')
            ->persistent($result === 'blocked' || $result === 'failed')
            ->send();

        $this->dispatch('close-modal', id: self::ID);
    }

    public function render(): View
    {
        return view('clear-cache::modal');
    }
}
