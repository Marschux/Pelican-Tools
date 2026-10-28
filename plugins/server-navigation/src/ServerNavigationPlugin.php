<?php

namespace Marschux\ServerNavigation;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;

class ServerNavigationPlugin implements Plugin
{
    public function getId(): string
    {
        return 'server-navigation';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'server') {
            // the pencil above the first entry; who may use it decides the component
            $panel->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn () => Blade::render("@livewire('server-navigation-editor')"));
        }
    }

    public function boot(Panel $panel): void {}
}
