<?php

namespace Marschux\ServerNavigation;

use App\Filament\Admin\Resources\Servers\ServerResource;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Marschux\ServerNavigation\Filament\Admin\RelationManagers\NavigationRelationManager;

class ServerNavigationPlugin implements Plugin
{
    public function getId(): string
    {
        return 'server-navigation';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'admin') {
            ServerResource::registerCustomRelations(NavigationRelationManager::class);
        }
    }

    public function boot(Panel $panel): void {}
}
