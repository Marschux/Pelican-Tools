<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // a profile can have several sources now: "source" => "x" becomes "sources" => ["x"]
    public function up(): void
    {
        foreach (DB::table('mod_profiles')->get(['id', 'settings']) as $profile) {
            $settings = json_decode((string) $profile->settings, true) ?: [];

            if (!array_key_exists('source', $settings)) {
                continue;
            }

            $settings['sources'] = array_values(array_filter([$settings['source']]));
            unset($settings['source']);

            DB::table('mod_profiles')->where('id', $profile->id)->update(['settings' => json_encode($settings)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('mod_profiles')->get(['id', 'settings']) as $profile) {
            $settings = json_decode((string) $profile->settings, true) ?: [];

            if (!array_key_exists('sources', $settings)) {
                continue;
            }

            $settings['source'] = $settings['sources'][0] ?? null;
            unset($settings['sources']);

            DB::table('mod_profiles')->where('id', $profile->id)->update(['settings' => json_encode($settings)]);
        }
    }
};
