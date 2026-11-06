<?php

namespace Marschux\ClearCache;

use Filament\Contracts\Plugin;
use Filament\Panel;

/** Nothing to register on the panel: the button joins Pelican's plugin list, see PluginListButton. */
class ClearCachePlugin implements Plugin
{
    public function getId(): string
    {
        return 'clear-cache';
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}
}
