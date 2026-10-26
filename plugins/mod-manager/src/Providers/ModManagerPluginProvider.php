<?php

namespace Marschux\ModManager\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Marschux\ModManager\Models\ModProfile;
use Marschux\ModManager\Policies\ModProfilePolicy;

class ModManagerPluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(plugin_path('mod-manager', 'database/migrations'));
        $this->loadTranslationsFrom(plugin_path('mod-manager', 'lang'), 'mod-manager');
        $this->loadViewsFrom(plugin_path('mod-manager', 'resources/views'), 'mod-manager');

        Gate::policy(ModProfile::class, ModProfilePolicy::class);
    }
}
