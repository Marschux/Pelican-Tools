<?php

namespace Marschux\GameManager\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Marschux\GameManager\Livewire\AccessListEditor;
use Marschux\GameManager\Models\ModProfile;
use Marschux\GameManager\Policies\ModProfilePolicy;

class GameManagerPluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(plugin_path('game-manager', 'database/migrations'));
        $this->loadTranslationsFrom(plugin_path('game-manager', 'lang'), 'game-manager');
        $this->loadViewsFrom(plugin_path('game-manager', 'resources/views'), 'game-manager');

        Gate::policy(ModProfile::class, ModProfilePolicy::class);

        Livewire::component('game-manager.access-list-editor', AccessListEditor::class);
    }
}
