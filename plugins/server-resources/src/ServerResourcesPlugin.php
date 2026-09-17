<?php

namespace Marschux\ServerResources;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Only widgets: the admin dashboard is filament's own, which shows every widget of the panel -
 * discovering ours is all it takes to put them there.
 */
class ServerResourcesPlugin implements Plugin
{
    public function getId(): string
    {
        return 'server-resources';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverWidgets(plugin_path($this->getId(), 'src/Filament/Admin/Widgets'), 'Marschux\\ServerResources\\Filament\\Admin\\Widgets');
    }

    public function boot(Panel $panel): void {}
}
