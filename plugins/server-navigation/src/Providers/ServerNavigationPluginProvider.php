<?php

namespace Marschux\ServerNavigation\Providers;

use App\Models\Server;
use Filament\Navigation\NavigationManager;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Marschux\ServerNavigation\Livewire\SidebarEditor;
use Marschux\ServerNavigation\Models\NavigationEntry;
use Marschux\ServerNavigation\Navigation\FilteredNavigationManager;

class ServerNavigationPluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(plugin_path('server-navigation', 'database/migrations'));
        $this->loadTranslationsFrom(plugin_path('server-navigation', 'lang'), 'server-navigation');
        $this->loadViewsFrom(plugin_path('server-navigation', 'resources/views'), 'server-navigation');
        Livewire::component('server-navigation-editor', SidebarEditor::class);

        // bound in boot, not register: Filament binds its own manager while registering, and the
        // later binding wins - done too early, Filament's would replace this one again
        $this->app->scoped(NavigationManager::class, fn () => new FilteredNavigationManager());

        Server::resolveRelationUsing('navigationEntries', fn (Server $server) => $server->hasMany(NavigationEntry::class));
    }
}
