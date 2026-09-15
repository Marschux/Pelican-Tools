<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 001 was rewritten from one file per row to a json "files" column after it had already run on an
 * installed panel, and a migration that ran is never run again. This brings such a table up to
 * date, keeping each old row as the single file of its entry. A fresh install has nothing to do.
 */
return new class extends Migration
{
    private const OLD = ['path', 'format', 'key_field', 'display_field', 'entry_template'];

    public function up(): void
    {
        if (Schema::hasColumn('access_lists', 'files')) {
            return;
        }

        Schema::table('access_lists', function (Blueprint $table) {
            $table->json('files')->nullable()->after('name');
        });

        foreach (DB::table('access_lists')->get() as $row) {
            DB::table('access_lists')->where('id', $row->id)->update(['files' => json_encode([[
                'label' => $row->name,
                'path' => $row->path,
                'format' => $row->format,
                'key_field' => $row->key_field,
                'display_field' => $row->display_field,
                'entry_template' => $row->entry_template,
            ]])]);
        }

        Schema::table('access_lists', function (Blueprint $table) {
            $table->dropColumn(array_values(array_filter(self::OLD, fn ($column) => Schema::hasColumn('access_lists', $column))));
        });
    }

    public function down(): void
    {
        // the one-file-per-row shape is gone for good; 001 describes the current table
    }
};
