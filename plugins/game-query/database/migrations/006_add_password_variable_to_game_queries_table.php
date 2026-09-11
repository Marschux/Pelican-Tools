<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_queries', function (Blueprint $table) {
            // optional: which startup variable holds the plaintext connect password, if any
            $table->string('password_variable')->nullable()->after('query_port_variable');
        });
    }

    public function down(): void
    {
        Schema::table('game_queries', function (Blueprint $table) {
            $table->dropColumn('password_variable');
        });
    }
};
