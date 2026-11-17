<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // what a user marked in the browse tabs to find again, per game (mod profile), on every server of it
        Schema::create('mod_stars', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('mod_profile_id');
            // the browse tile's id, it names its source, e.g. "hexium:Namespace-Name"
            $table->string('package');
            // the tile as it was starred, so the starred tab shows it without asking the source
            $table->json('item');
            $table->timestamps();

            $table->unique(['user_id', 'mod_profile_id', 'package']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('mod_profile_id')->references('id')->on('mod_profiles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_stars');
    }
};
