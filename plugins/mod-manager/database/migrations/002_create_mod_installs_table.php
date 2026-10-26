<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // what the plugin put on a server, so it can be shown, updated and removed again
        Schema::create('mod_installs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('server_id');
            // as the source names it, e.g. "ValheimModding-Jotunn"
            $table->string('package');
            $table->string('name');
            $table->string('version');
            $table->string('icon')->nullable();
            // pulled in by another mod rather than picked by hand
            $table->boolean('dependency')->default(false);
            // the packages this one needs, to find dependencies nothing uses any more
            $table->json('requires');
            // every path the install wrote
            $table->json('files');
            $table->timestamps();

            $table->unique(['server_id', 'package']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_installs');
    }
};
