<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_query_samples', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('players');
            $table->unsignedInteger('max_players');
            $table->unsignedInteger('bots')->nullable();
            $table->timestamp('created_at')->nullable();

            // every read is "the samples of one server, newest first"
            $table->index(['server_id', 'created_at']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_query_samples');
    }
};
