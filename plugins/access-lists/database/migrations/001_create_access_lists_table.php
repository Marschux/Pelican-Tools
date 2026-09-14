<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_lists', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            // one entry per game, holding all of its files - minecraft alone has four, and
            // linking each of them to the egg separately is busywork
            $table->json('files')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_lists');
    }
};
