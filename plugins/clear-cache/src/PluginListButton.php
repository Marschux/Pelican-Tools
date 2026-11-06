<?php

namespace Marschux\ClearCache;

use App\Filament\Admin\Resources\Plugins\Pages\ListPlugins;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Livewire\Component;

/**
 * Puts a "clear cache" icon beside the import icons of Pelican's plugin list. Pelican's plugin
 * table takes no outside actions and sets its header actions in one go, so they are pushed
 * onto the finished table. Called on Livewire's mount and hydrate events, which reach a listener
 * added this late after the page's own booted(), where the table is built - on the first request
 * and on every one after. (A ComponentHook would not do: Livewire wires those up once at its own
 * boot, before plugins are loaded.)
 */
class PluginListButton
{
    public static function add(Component $component): void
    {
        if (!$component instanceof ListPlugins) {
            return;
        }

        $component->getTable()->pushHeaderActions([
            Action::make('clear_cache')
                ->label(trans('clear-cache::clear-cache.button'))
                ->hiddenLabel()
                ->tooltip(trans('clear-cache::clear-cache.button'))
                ->icon('tabler-refresh')
                ->authorize(fn () => user()?->can('update plugin') ?? false)
                // the browser asks, not a Filament modal: a modal's confirm comes back in a second
                // request, where Filament looks the action up in booted() - before it is pushed here
                ->extraAttributes(['wire:confirm' => trans('clear-cache::clear-cache.confirm')])
                ->action(function () {
                    $result = CacheClearer::run();

                    Notification::make()
                        ->title(trans($result === 'failed' ? 'clear-cache::clear-cache.failed' : 'clear-cache::clear-cache.done'))
                        ->body(trans("clear-cache::clear-cache.result.$result"))
                        ->status($result === 'reset' || $result === 'off' ? 'success' : 'warning')
                        ->persistent($result === 'blocked' || $result === 'failed')
                        ->send();
                }),
        ]);
    }
}
