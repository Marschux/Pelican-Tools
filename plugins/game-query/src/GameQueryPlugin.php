<?php

namespace Marschux\GameQuery;

use App\Enums\ConsoleWidgetPosition;
use App\Filament\Server\Pages\Console;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;
use Marschux\GameQuery\Filament\Server\Widgets\ServerInfoWidget;

/**
 * No settings form: the defaults in config/game-query.php fit, and the rare setup that needs
 * something else sets the GAME_QUERY_* variables in .env.
 */
class GameQueryPlugin implements Plugin
{
    public function getId(): string
    {
        return 'game-query';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverResources(plugin_path($this->getId(), "src/Filament/$id/Resources"), "Marschux\\GameQuery\\Filament\\$id\\Resources");
        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "Marschux\\GameQuery\\Filament\\$id\\Pages");
        $panel->discoverWidgets(plugin_path($this->getId(), "src/Filament/$id/Widgets"), "Marschux\\GameQuery\\Filament\\$id\\Widgets");
    }

    public function boot(Panel $panel): void
    {
        if ($panel->getId() !== 'server') {
            return;
        }

        // Discovering a widget only makes it known to the panel - the console page renders a
        // fixed list plus whatever was registered at one of its named positions, so without
        // this the widget exists but appears nowhere. AboveConsole puts it right after the
        // core overview blocks.
        Console::registerCustomWidgets(ConsoleWidgetPosition::AboveConsole, [ServerInfoWidget::class]);

        // The tiles take one of the page's three columns, left of the console. The console's own
        // span is fixed to "full" in the core widget, so it is narrowed here - and only when the
        // tiles actually render before it, an offline server keeps the full-width console.
        // Widgets are found by their livewire snapshot, which carries the component name.
        FilamentView::registerRenderHook(
            PanelsRenderHook::STYLES_AFTER,
            fn () => new HtmlString('<style>@media (min-width: 64rem) { .fi-console-page [wire\:snapshot*="server-info-widget"] ~ [wire\:snapshot*="server-console"] { grid-column: span 2 / span 2 !important; } }</style>'),
            scopes: Console::class,
        );
    }
}
