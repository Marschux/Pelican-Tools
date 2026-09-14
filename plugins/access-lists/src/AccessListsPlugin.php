<?php

namespace Marschux\AccessLists;

use Filament\Contracts\Plugin;
use Filament\Panel;

class AccessListsPlugin implements Plugin
{
    public function getId(): string
    {
        return 'access-lists';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverResources(plugin_path($this->getId(), "src/Filament/$id/Resources"), "Marschux\\AccessLists\\Filament\\$id\\Resources");
        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "Marschux\\AccessLists\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}
}
