<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The access lists (bans, whitelists, server.cfg) came from the access-lists plugin. Uninstalling
 * a plugin rolls its migrations back and drops its tables, so mod-manager keeps tables of its own
 * and copies what that plugin had - before it is uninstalled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_access_lists', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            // all files of one game: label, path, format (lines, json, pairs) and how an entry looks
            $table->json('files')->nullable();
            $table->timestamps();
        });

        Schema::create('mod_access_list_egg', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('egg_id');
            $table->unsignedInteger('mod_access_list_id');
            $table->timestamps();

            $table->unique(['egg_id', 'mod_access_list_id']);
            $table->foreign('egg_id')->references('id')->on('eggs')->cascadeOnDelete();
            $table->foreign('mod_access_list_id')->references('id')->on('mod_access_lists')->cascadeOnDelete();
        });

        if (!Schema::hasTable('access_lists') || !Schema::hasTable('access_list_egg') || !Schema::hasColumn('access_lists', 'files')) {
            return;
        }

        foreach (DB::table('access_lists')->get(['id', 'name', 'files', 'created_at', 'updated_at']) as $row) {
            DB::table('mod_access_lists')->insert((array) $row);
        }

        foreach (DB::table('access_list_egg')->get(['egg_id', 'access_list_id', 'created_at', 'updated_at']) as $row) {
            DB::table('mod_access_list_egg')->insert(['egg_id' => $row->egg_id, 'mod_access_list_id' => $row->access_list_id, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_access_list_egg');
        Schema::dropIfExists('mod_access_lists');
    }
};
