<?php

namespace Marschux\GameQuery\Providers;

use App\Models\Egg;
use App\Models\Server;
use Filament\Tables\Table;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Marschux\GameQuery\Filament\ServerPlayersColumn;
use Marschux\GameQuery\Jobs\SampleGameQueriesJob;
use Marschux\GameQuery\Models\EggGameQuery;
use Marschux\GameQuery\Models\GameQuery;
use Marschux\GameQuery\Policies\GameQueryPolicy;
use Marschux\GameQuery\Query\QueryTypeService;
use Marschux\GameQuery\Query\Schemas\GoldSourceSchema;
use Marschux\GameQuery\Query\Schemas\MinecraftJavaSchema;
use Marschux\GameQuery\Query\Schemas\SourceSchema;

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

            return $service;
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(plugin_path('game-query', 'database/migrations'));
        $this->loadTranslationsFrom(plugin_path('game-query', 'lang'), 'game-query');

        Gate::policy(GameQuery::class, GameQueryPolicy::class);

        // Adds $egg->gameQuery without touching the core Egg model. hasOneThrough rather than
        // belongsToMany because the pivot's egg_id is unique - an egg has at most one query,
        // and a single model is what every caller here wants.
        Egg::resolveRelationUsing('gameQuery', fn (Egg $egg) => $egg->hasOneThrough(
            GameQuery::class,
            EggGameQuery::class,
            'egg_id',
            'id',
            'id',
            'game_query_id',
        ));

        $this->addServerListColumn();
        $this->scheduleSampling();
    }

    /**
     * The interval is a setting, so it is read here rather than hardcoded in the schedule.
     * everyMinute() with a modulo filter would be the alternative; cron is simpler and exact.
     */
    private function scheduleSampling(): void
    {
        $minutes = min(60, max(1, (int) config('game-query.history_interval')));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($minutes) {
            $schedule->job(new SampleGameQueriesJob())
                ->cron("*/$minutes * * * *")
                ->withoutOverlapping();
        });
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
