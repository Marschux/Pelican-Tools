<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Access lists become part of the mod profile (settings.lists), so an egg is set up in one form.
 * A list goes to the profile of each of its eggs; an egg without a profile (TeamSpeak) gets one
 * holding only the lists. Files with the same path are not added twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mod_access_lists')) {
            return;
        }

        $links = DB::table('mod_access_list_egg')->get(['egg_id', 'mod_access_list_id']);

        foreach (DB::table('mod_access_lists')->get(['id', 'name', 'files']) as $list) {
            $files = json_decode((string) $list->files, true) ?: [];
            $eggs = $links->where('mod_access_list_id', $list->id)->pluck('egg_id');
            $leftover = [];

            foreach ($eggs as $egg) {
                $profileId = DB::table('mod_profile_egg')->where('egg_id', $egg)->value('mod_profile_id');

                if ($profileId === null) {
                    $leftover[] = $egg;

                    continue;
                }

                $this->addLists($profileId, $files);
            }

            // eggs without a profile share one that only holds the lists
            if ($leftover !== [] || $eggs->isEmpty()) {
                $profileId = DB::table('mod_profiles')->insertGetId(['name' => $list->name, 'settings' => json_encode(['lists' => array_values($files)]), 'created_at' => now(), 'updated_at' => now()]);

                foreach ($leftover as $egg) {
                    DB::table('mod_profile_egg')->insert(['mod_profile_id' => $profileId, 'egg_id' => $egg, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }

        Schema::dropIfExists('mod_access_list_egg');
        Schema::dropIfExists('mod_access_lists');
    }

    /** @param  array<int, array<string, mixed>>  $files */
    private function addLists(int $profileId, array $files): void
    {
        $settings = json_decode((string) DB::table('mod_profiles')->where('id', $profileId)->value('settings'), true) ?: [];
        $lists = array_values($settings['lists'] ?? []);
        $paths = array_column($lists, 'path');

        foreach ($files as $file) {
            if (!in_array($file['path'] ?? null, $paths, true)) {
                $lists[] = $file;
            }
        }

        $settings['lists'] = $lists;
        DB::table('mod_profiles')->where('id', $profileId)->update(['settings' => json_encode($settings)]);
    }

    public function down(): void
    {
        // the lists stay in the profiles; 006 describes the tables they came from
    }
};
