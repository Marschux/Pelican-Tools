<?php

namespace Marschux\ClearCache\Providers;

use Illuminate\Support\ServiceProvider;
use Marschux\ClearCache\PluginListButton;

class ClearCachePluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(plugin_path('clear-cache', 'lang'), 'clear-cache');
        \Livewire\on('mount', fn ($component) => PluginListButton::add($component));
        \Livewire\on('hydrate', fn ($component) => PluginListButton::add($component));
    }
}
