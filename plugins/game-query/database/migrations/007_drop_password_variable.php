<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The password is auto-detected now (GameQuery::PASSWORD_VARIABLES) rather than configured per
 * game query, so the column from migration 006 is gone again a version later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_queries', function (Blueprint $table) {
            if (Schema::hasColumn('game_queries', 'password_variable')) {
                $table->dropColumn('password_variable');
            }
        });
    }

    public function down(): void
    {
        Schema::table('game_queries', function (Blueprint $table) {
            $table->string('password_variable')->nullable()->after('query_port_variable');
        });
    }
};
