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

        // Our tiles stack in the first of the page's three columns, the console takes the other two
        // beside them; the core overview above stays as it is. The console fixes its own span to
        // "full", so this is css, and only when our tiles render before it - an offline server
        // keeps the full-width console. Tiles by their own class (our view), console by #terminal.
        // Below lg it all stacks as before.
        FilamentView::registerRenderHook(
            PanelsRenderHook::STYLES_AFTER,
            fn () => new HtmlString('<style>@media (min-width: 64rem) {'
                . '.fi-console-page .fi-wi > .game-query-tiles { grid-column: 1 / 2 !important; }'
                . '.fi-console-page .fi-wi > .game-query-tiles ~ :has(#terminal) { grid-column: 2 / -1 !important; display: flex; flex-direction: column; contain: size; }'
                . '.fi-console-page .game-query-tiles ~ :has(#terminal) #terminal { flex: 1 1 0; min-height: 0; }'
                . '.fi-console-page .game-query-tiles ~ :has(#terminal) #terminal .xterm { height: 100%; }'
                . '}</style>'),
            scopes: Console::class,
        );

        // The console is as tall as the tile column: "contain: size" above keeps its own row count
        // from stretching the grid, so the tiles set the height and the terminal fills it. xterm's
        // fit addon only recalculates rows on a window resize, so one is sent whenever the
        // terminal's box changes - tiles appearing, a poll adding a field, the window itself.
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn () => new HtmlString(<<<'HTML'
                <script>
                    (() => {
                        const watch = () => {
                            const terminal = document.getElementById('terminal');

                            if (!terminal || terminal.dataset.gameQueryObserved) {
                                return;
                            }

                            terminal.dataset.gameQueryObserved = '1';

                            let width = 0, height = 0;

                            new ResizeObserver(([entry]) => {
                                const box = entry.contentRect;

                                // only a real change - the resize event itself must not loop back here
                                if (Math.round(box.width) === width && Math.round(box.height) === height) {
                                    return;
                                }

                                width = Math.round(box.width);
                                height = Math.round(box.height);
                                window.dispatchEvent(new Event('resize'));
                            }).observe(terminal);
                        };

                        watch();
                        document.addEventListener('livewire:navigated', watch);
                    })();
                </script>
                HTML),
            scopes: Console::class,
        );
    }
}
