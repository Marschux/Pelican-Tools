<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_queries', function (Blueprint $table) {
            // optional: the list falls back to the query type when this is empty
            $table->string('name')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('game_queries', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
