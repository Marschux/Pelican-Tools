<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_list_egg', function (Blueprint $table) {
            $table->increments('id');
            // unlike a query, an egg has several lists - minecraft alone has four
            $table->unsignedInteger('egg_id');
            $table->unsignedInteger('access_list_id');
            $table->timestamps();

            $table->unique(['egg_id', 'access_list_id']);
            $table->foreign('egg_id')->references('id')->on('eggs')->cascadeOnDelete();
            $table->foreign('access_list_id')->references('id')->on('access_lists')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_list_egg');
    }
};
