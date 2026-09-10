<?php

namespace Marschux\GameQuery\Providers;

use App\Models\Egg;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
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
    }
}
