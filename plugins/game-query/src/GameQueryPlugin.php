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

        // Every tile widget - the core overview and ours - stacks in the first of the page's three
        // columns, the console takes the other two beside them, over the two rows the tile widgets
        // fill. The core widgets fix their own spans, so this is css: the console is found by its
        // #terminal, the tiles by filament's stats widget class. Below lg it all stacks as before.
        FilamentView::registerRenderHook(
            PanelsRenderHook::STYLES_AFTER,
            fn () => new HtmlString('<style>@media (min-width: 64rem) {'
                . '.fi-console-page .fi-wi > .fi-wi-stats-overview { grid-column: 1 / 2 !important; }'
                . '.fi-console-page .fi-wi > .fi-wi-stats-overview .fi-grid { grid-template-columns: minmax(0, 1fr) !important; }'
                . '.fi-console-page .fi-wi > :has(#terminal) { grid-column: 2 / -1 !important; grid-row: 1 / span 2 !important; }'
                . '}</style>'),
            scopes: Console::class,
        );
    }
}
