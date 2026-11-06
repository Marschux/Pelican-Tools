<?php

namespace Marschux\ClearCache;

use App\Filament\Admin\Resources\Plugins\Pages\ListPlugins;
use Filament\Actions\Action;
use Livewire\Component;
use Marschux\ClearCache\Livewire\ClearCacheModal;

/**
 * Puts a "clear cache" icon beside the import icons of Pelican's plugin list. Pelican's plugin
 * table takes no outside actions and sets its header actions in one go, so the icon is pushed
 * onto the finished table. Called on Livewire's mount and hydrate events, which reach a listener
 * added this late after the page's own booted(), where the table is built. (A ComponentHook would
 * not do: Livewire wires those up once at its own boot, before plugins are loaded.)
 *
 * The icon only opens ClearCacheModal in the browser, the clearing happens there.
 */
class PluginListButton
{
    public static function add(Component $component): void
    {
        if (!$component instanceof ListPlugins || !ClearCacheModal::allowed()) {
            return;
        }

        $component->getTable()->pushHeaderActions([
            Action::make('clear_cache')
                ->label(trans('clear-cache::clear-cache.button'))
                ->hiddenLabel()
                ->tooltip(trans('clear-cache::clear-cache.button'))
                ->icon('tabler-refresh')
                ->alpineClickHandler("\$dispatch('open-modal', { id: '" . ClearCacheModal::ID . "' })"),
        ]);
    }
}
