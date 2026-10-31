<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mod_installs', function (Blueprint $table) {
            // a version picked by hand stays until it is updated by hand
            $table->boolean('pinned')->default(false);
            // switched off: moved out of the mods folder, so the loader does not see it
            $table->boolean('enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('mod_installs', function (Blueprint $table) {
            $table->dropColumn(['pinned', 'enabled']);
        });
    }
};
