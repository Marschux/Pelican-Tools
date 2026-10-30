<?php

namespace Marschux\ModManager\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Marschux\ModManager\Livewire\AccessListEditor;
use Marschux\ModManager\Models\AccessList;
use Marschux\ModManager\Models\ModProfile;
use Marschux\ModManager\Policies\AccessListPolicy;
use Marschux\ModManager\Policies\ModProfilePolicy;

class ModManagerPluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(plugin_path('mod-manager', 'database/migrations'));
        $this->loadTranslationsFrom(plugin_path('mod-manager', 'lang'), 'mod-manager');
        $this->loadViewsFrom(plugin_path('mod-manager', 'resources/views'), 'mod-manager');

        Gate::policy(ModProfile::class, ModProfilePolicy::class);
        Gate::policy(AccessList::class, AccessListPolicy::class);

        Livewire::component('mod-manager.access-list-editor', AccessListEditor::class);
    }
}
