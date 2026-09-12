<?php

namespace Database\Seeders;

use App\Models\Egg;
use Illuminate\Database\Seeder;
use Marschux\GameQuery\Models\EggGameQuery;
use Marschux\GameQuery\Models\GameQuery;
use Throwable;

class GameQuerySeeder extends Seeder
{
    /**
     * Matched by egg name, because none of the eggs in this repo carry tags.
     *
     * Only Valheim is listed - it's the only game actually run on this panel. A broader default
     * set for every A2S/GoldSrc/Minecraft egg used to live here, but firstOrCreate() recreates a
     * deleted row on every re-run as long as the egg exists, so a mapping for a game nobody
     * runs just keeps coming back. Add an egg's name here when you actually need its query.
     */
    public const MAPPINGS = [
        [
            // same A2S query as any source game, plus the world seed read from the save file
            'names' => ['Valheim Server', 'Valheim Plus Mod'],
            'query_type' => 'valheim',
            'name' => 'Valheim',
            'offset' => 1,
        ],
    ];

    public function run(): void
    {
        $created = 0;

        foreach (Egg::all() as $egg) {
            foreach (self::MAPPINGS as $mapping) {
                if (!in_array($egg->name, $mapping['names'], true)) {
                    continue;
                }

                try {
                    $query = GameQuery::firstOrCreate([
                        'query_type' => $mapping['query_type'],
                        'query_port_offset' => $mapping['offset'] ?? null,
                        'query_port_variable' => $mapping['variable'] ?? null,
                    ], [
                        'name' => $mapping['name'] ?? null,
                    ]);

                    // firstOrCreate leaves an existing row untouched, so a query created
                    // before labels existed would stay unnamed on a re-run
                    if (!$query->name && isset($mapping['name'])) {
                        $query->update(['name' => $mapping['name']]);
                    }

                    EggGameQuery::firstOrCreate(['egg_id' => $egg->id], ['game_query_id' => $query->id]);
                    $created++;
                } catch (Throwable) {
                    // egg already has a query - the unique key doing its job
                }
            }
        }

        $this->command?->info("Linked $created eggs to a game query");
    }
}
