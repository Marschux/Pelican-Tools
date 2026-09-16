<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_hidden_navigation', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('server_id');
            // the first url segment of the page after the server, e.g. "backups"
            $table->string('slug');
            $table->timestamps();

            $table->unique(['server_id', 'slug']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_hidden_navigation');
    }
};
