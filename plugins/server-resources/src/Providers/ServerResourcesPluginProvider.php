<?php

namespace Marschux\ServerResources\Providers;

use Illuminate\Support\ServiceProvider;

class ServerResourcesPluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(plugin_path('server-resources', 'lang'), 'server-resources');
        $this->loadViewsFrom(plugin_path('server-resources', 'resources/views'), 'server-resources');
    }
}
