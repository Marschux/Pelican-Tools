<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reverses migrations 001 and 003 for installs that already ran them: the history graph and
 * server-rules features were dropped again, along with player actions (kick/ban), which lost
 * their only consumer when the players page went away. Guarded with hasColumn/hasTable checks
 * so this is a no-op on a fresh install, where 001 no longer creates any of this in the first
 * place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('game_query_samples');

        Schema::table('game_queries', function (Blueprint $table) {
            foreach (['player_actions', 'fetch_rules', 'track_history'] as $column) {
                if (Schema::hasColumn('game_queries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('game_queries', function (Blueprint $table) {
            $table->json('player_actions')->nullable();
            $table->boolean('fetch_rules')->default(false);
            $table->boolean('track_history')->default(false);
        });

        Schema::create('game_query_samples', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('players');
            $table->unsignedInteger('max_players');
            $table->unsignedInteger('bots')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['server_id', 'created_at']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }
};
