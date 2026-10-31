<?php

namespace Marschux\GameManager;

use Filament\Contracts\Plugin;
use Filament\Panel;

class GameManagerPlugin implements Plugin
{
    public function getId(): string
    {
        return 'game-manager';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverResources(plugin_path($this->getId(), "src/Filament/$id/Resources"), "Marschux\\GameManager\\Filament\\$id\\Resources");
        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "Marschux\\GameManager\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}
}
