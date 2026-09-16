<?php

namespace Marschux\ServerNavigation;

use App\Enums\TabPosition;
use App\Filament\Admin\Resources\Servers\Pages\EditServer;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Marschux\ServerNavigation\Filament\Admin\NavigationTab;

class ServerNavigationPlugin implements Plugin
{
    public function getId(): string
    {
        return 'server-navigation';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'admin') {
            EditServer::registerCustomTabs(TabPosition::After, NavigationTab::make());
        }
    }

    public function boot(Panel $panel): void {}
}
