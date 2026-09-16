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
        if ($panel->getId() === 'admin') {
            // the field list in the game query form: filament's compact table rows are still as
            // tall as their move buttons, so cells and buttons are tightened here
            FilamentView::registerRenderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn () => new HtmlString('<style>'
                    . '.game-query-compact td { padding-block: 0 !important; }'
                    // only the height is tightened: squeezing the width made the icons overlap
                    . '.game-query-compact .fi-fo-table-repeater-actions { display: flex !important; align-items: center; gap: .75rem !important; }'
                    . '.game-query-compact .fi-fo-table-repeater-actions .fi-icon-btn { height: 1.5rem !important; }'
                    . '</style>'),
            );
        }

        if ($panel->getId() !== 'server') {
            return;
        }

        // Discovering a widget only makes it known to the panel - the console page renders a
        // fixed list plus whatever was registered at one of its named positions, so without
        // this the widget exists but appears nowhere. AboveConsole puts it right after the
        // core overview blocks.
        Console::registerCustomWidgets(ConsoleWidgetPosition::AboveConsole, [ServerInfoWidget::class]);
    }
}
