<?php

use Illuminate\Support\Facades\Route;
use Marschux\GameQuery\Http\Controllers\Api\Client\Servers\GameQueryController;

Route::prefix('/servers/{server}')->group(function () {
    Route::get('/query', [GameQueryController::class, 'query'])->name('api.client.servers.query');
    Route::get('/players', [GameQueryController::class, 'players'])->name('api.client.servers.players');
});
