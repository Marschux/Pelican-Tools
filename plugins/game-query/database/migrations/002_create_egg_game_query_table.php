<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('egg_game_query', function (Blueprint $table) {
            $table->increments('id');
            // an egg has at most one query - the unique key is what makes ->gameQuery a single relation
            $table->unsignedInteger('egg_id')->unique();
            $table->unsignedInteger('game_query_id');
            $table->timestamps();

            $table->foreign('egg_id')->references('id')->on('eggs')->cascadeOnDelete();
            $table->foreign('game_query_id')->references('id')->on('game_queries')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('egg_game_query');
    }
};
