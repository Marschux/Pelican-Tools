<?php

namespace Marschux\ClearCache\Providers;

use App\Filament\Admin\Resources\Plugins\Pages\ListPlugins;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Marschux\ClearCache\Livewire\ClearCacheModal;
use Marschux\ClearCache\PluginListButton;

class ClearCachePluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(plugin_path('clear-cache', 'lang'), 'clear-cache');
        $this->loadViewsFrom(plugin_path('clear-cache', 'resources/views'), 'clear-cache');
        Livewire::component('clear-cache-modal', ClearCacheModal::class);

        \Livewire\on('mount', fn ($component) => PluginListButton::add($component));
        \Livewire\on('hydrate', fn ($component) => PluginListButton::add($component));

        // the modal the icon opens, on the plugin list only
        FilamentView::registerRenderHook(
            TablesRenderHook::HEADER_BEFORE,
            fn () => ClearCacheModal::allowed() ? Blade::render("@livewire('clear-cache-modal')") : '',
            scopes: ListPlugins::class,
        );
    }
}
