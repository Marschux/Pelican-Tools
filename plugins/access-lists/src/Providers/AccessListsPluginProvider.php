<?php

namespace Marschux\AccessLists\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Marschux\AccessLists\Models\AccessList;
use Marschux\AccessLists\Policies\AccessListPolicy;

class AccessListsPluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(plugin_path('access-lists', 'database/migrations'));
        $this->loadTranslationsFrom(plugin_path('access-lists', 'lang'), 'access-lists');
        $this->loadViewsFrom(plugin_path('access-lists', 'resources/views'), 'access-lists');

        Gate::policy(AccessList::class, AccessListPolicy::class);
    }
}
