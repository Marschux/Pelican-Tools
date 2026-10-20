<?php

namespace Marschux\GameQuery\Providers;

use App\Filament\Admin\Resources\Servers\Pages\ListServers as AdminListServers;
use App\Filament\App\Resources\Servers\Pages\ListServers as AppListServers;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Marschux\GameQuery\Filament\ServerPlayersColumn;
use Marschux\GameQuery\Models\GameQuery;
use Marschux\GameQuery\Policies\GameQueryPolicy;
use Marschux\GameQuery\Query\QueryTypeService;
use Marschux\GameQuery\Query\Schemas\GoldSourceSchema;
use Marschux\GameQuery\Query\Schemas\MinecraftJavaSchema;
use Marschux\GameQuery\Query\Schemas\SourceSchema;
use Marschux\GameQuery\Query\Schemas\TeamSpeakSchema;
use Marschux\GameQuery\Query\Schemas\ValheimSchema;

use function Livewire\on;

class GameQueryPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        // the xPaw query libraries ship inside the plugin instead of as composer_packages: every
        // "composer require" the panel runs on install dumps the autoloader with autoload-dev,
        // which points at tests/ - missing on a release install, so the whole panel 500s.
        // Appended after composer's loader, so a composer-installed copy still wins
        spl_autoload_register(function (string $class) {
            if (str_starts_with($class, 'xPaw\\')) {
                $file = plugin_path('game-query', 'lib/' . str_replace('\\', '/', substr($class, 5)) . '.php');

                if (is_file($file)) {
                    require $file;
                }
            }
        });

        $this->mergeConfigFrom(plugin_path('game-query', 'config/game-query.php'), 'game-query');

        $this->app->singleton(QueryTypeService::class, function () {
            $service = new QueryTypeService();

            $service->register(new SourceSchema());
            $service->register(new GoldSourceSchema());
            $service->register(new MinecraftJavaSchema());
            $service->register(new ValheimSchema());
            $service->register(new TeamSpeakSchema());

            return $service;
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(plugin_path('game-query', 'database/migrations'));
        $this->loadTranslationsFrom(plugin_path('game-query', 'lang'), 'game-query');
        $this->loadViewsFrom(plugin_path('game-query', 'resources/views'), 'game-query');

        Gate::policy(GameQuery::class, GameQueryPolicy::class);

        $this->addServerListColumn();
    }

    /**
     * Appends the player count to the admin server list and the client dashboard.
     *
     * Not Table::configureUsing(): that runs while the table object is being created, and both
     * list pages then call columns([...]), which throws away every column pushed before it - the
     * column never showed up anywhere. Livewire's render event fires after a page has built its
     * table but before the view renders it, so a column pushed there is one that survives.
     */
    private function addServerListColumn(): void
    {
        on('render', function ($component) {
            if (!$component instanceof AdminListServers && !$component instanceof AppListServers) {
                return;
            }

            $table = $component->getTable();

            // the dashboard's grid layout is one stacked card per server, a loose column has no
            // place in it
            if ($table->hasColumnsLayout() || $table->getColumn('players')) {
                return;
            }

            // pushColumns would park it at the far right, past the resource bars; the count
            // belongs next to the server it counts
            $columns = [];
            $inserted = false;
            $growNext = false;

            foreach ($table->getColumns() as $key => $column) {
                // the name column eats all free width, which is the gap the player count would
                // otherwise sit behind; the slack goes to the column after the count instead
                if ($growNext) {
                    $growNext = false;
                    $this->setGrow($column, true);
                }

                $columns[] = $column;

                if (!$inserted && ($key === 'name' || str_ends_with((string) $key, '.name'))) {
                    $inserted = true;
                    $growNext = true;
                    $this->setGrow($column, false);
                    $columns[] = ServerPlayersColumn::make()->grow(false);
                }
            }

            if (!$inserted) {
                $columns[] = ServerPlayersColumn::make();
            }

            $table->columns($columns);
        });
    }

    private function setGrow(mixed $column, bool $grow): void
    {
        if (method_exists($column, 'grow')) {
            $column->grow($grow);
        }
    }
}
