<?php

namespace Marschux\ModManager;

use Filament\Contracts\Plugin;
use Filament\Panel;

class ModManagerPlugin implements Plugin
{
    public function getId(): string
    {
        return 'mod-manager';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverResources(plugin_path($this->getId(), "src/Filament/$id/Resources"), "Marschux\\ModManager\\Filament\\$id\\Resources");
        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "Marschux\\ModManager\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}
}
