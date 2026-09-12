<?php

namespace Marschux\GameQuery\Providers;

use App\Models\Server;
use Filament\Tables\Table;
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

class GameQueryPluginProvider extends ServiceProvider
{
    public function register(): void
    {
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
     * Appends the player count to every server table - the admin list and the client
     * dashboard - without touching or overriding the core resources.
     */
    private function addServerListColumn(): void
    {
        Table::configureUsing(function (Table $table) {
            // non-eloquent tables (our own players page for one) simply report no model
            if ($table->getModel() === Server::class) {
                $table->pushColumns([ServerPlayersColumn::make()]);
            }
        });
    }
}
