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
            $table->string('path');
            $table->string('format')->default('lines');
            // json lists only: which field identifies an entry, which one is shown, and the
            // shape a new entry gets
            $table->string('key_field')->nullable();
            $table->string('display_field')->nullable();
            $table->text('entry_template')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_lists');
    }
};
