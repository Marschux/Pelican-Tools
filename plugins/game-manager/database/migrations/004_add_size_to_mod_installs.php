<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mod_installs', function (Blueprint $table) {
            // bytes on the server's disk, measured after unpacking
            $table->unsignedBigInteger('size')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mod_installs', function (Blueprint $table) {
            $table->dropColumn('size');
        });
    }
};
