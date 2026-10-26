<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // one profile per game: where its mods come from and where they go
        Schema::create('mod_profiles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('mod_profile_egg', function (Blueprint $table) {
            $table->increments('id');
            // an egg has one profile at most, a profile can serve several eggs
            $table->unsignedInteger('egg_id')->unique();
            $table->unsignedInteger('mod_profile_id');
            $table->timestamps();

            $table->foreign('egg_id')->references('id')->on('eggs')->cascadeOnDelete();
            $table->foreign('mod_profile_id')->references('id')->on('mod_profiles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_profile_egg');
        Schema::dropIfExists('mod_profiles');
    }
};
