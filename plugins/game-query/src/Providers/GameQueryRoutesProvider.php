<?php

namespace Marschux\GameQuery\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class GameQueryRoutesProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['api', 'auth.session', 'server'])
            ->prefix('/api/client')
            ->group(plugin_path('game-query', 'routes/api-client.php'));
    }
}
