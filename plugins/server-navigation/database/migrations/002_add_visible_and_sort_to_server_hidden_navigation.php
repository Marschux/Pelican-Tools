<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_hidden_navigation', function (Blueprint $table) {
            // every row so far stood for a hidden entry, hence the default
            $table->boolean('visible')->default(false)->after('slug');
            $table->unsignedInteger('sort')->default(0)->after('visible');
        });
    }

    public function down(): void
    {
        Schema::table('server_hidden_navigation', function (Blueprint $table) {
            $table->dropColumn(['visible', 'sort']);
        });
    }
};
