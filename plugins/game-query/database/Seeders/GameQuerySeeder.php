<?php

namespace Database\Seeders;

use App\Models\Egg;
use Illuminate\Database\Seeder;
use Marschux\GameQuery\Models\EggGameQuery;
use Marschux\GameQuery\Models\GameQuery;
use Throwable;

class GameQuerySeeder extends Seeder
{
    private const KICK = ['label' => 'Kick', 'command' => 'kick "{player}"', 'icon' => 'tabler-user-x', 'confirm' => true];

    private const BAN = ['label' => 'Ban', 'command' => 'ban "{player}"', 'icon' => 'tabler-ban', 'confirm' => true];

    private const MINECRAFT_ACTIONS = [
        ['label' => 'Kick', 'command' => 'kick {player}', 'icon' => 'tabler-user-x', 'confirm' => true],
        ['label' => 'Ban', 'command' => 'ban {player}', 'icon' => 'tabler-ban', 'confirm' => true],
        ['label' => 'Whitelist', 'command' => 'whitelist add {player}', 'icon' => 'tabler-list-check', 'confirm' => false],
        ['label' => 'OP', 'command' => 'op {player}', 'icon' => 'tabler-shield', 'confirm' => true],
    ];

    /**
     * Matched by egg name, because none of the eggs in this repo carry tags.
     *
     * "offset" is the difference between the allocation port and the A2S query port; games
     * that answer on the game port itself use none. Where an egg already exposes the query
     * port as a startup variable, that variable wins - it survives the user changing it.
     */
    public const MAPPINGS = [
        [
            'names' => ['Vanilla Minecraft', 'Minecraft Paper', 'Minecraft Forge', 'Minecraft Fabric', 'Hexxit', 'Tekkit'],
            'query_type' => 'minecraft_java',
            'actions' => self::MINECRAFT_ACTIONS,
        ],
        [
            'names' => ['Counter-Strike 1.6', 'Counter-Strike: Condition Zero', 'Half-Life Deathmatch'],
            'query_type' => 'goldsrc',
            'actions' => [self::KICK, self::BAN],
        ],
        [
            // these answer A2S on the allocation port itself
            'names' => ['Counter-Strike 2', 'Counter-Strike: Global Offensive', 'Counter-Strike: Source', 'Team Fortress 2', "Garry's Mod", 'Left 4 Dead', 'Left 4 Dead 2', 'Quake Live'],
            'query_type' => 'source',
            'actions' => [self::KICK, self::BAN],
        ],
        [
            'names' => ['Rust', 'Conan Exiles'],
            'query_type' => 'source',
            'variable' => 'QUERY_PORT',
            'actions' => [self::KICK, self::BAN],
        ],
        [
            // survival games that traditionally put the query port right after the game port
            'names' => ['The Forest', 'Sons of the Forest', 'Arma 3', 'Enshrouded', 'The Lord of the Rings: Return to Moria'],
            'query_type' => 'source',
            'offset' => 1,
        ],
        [
            // same A2S query, plus the world seed read from the save file
            'names' => ['Valheim Server', 'Valheim Plus Mod'],
            'query_type' => 'valheim',
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
                        'player_actions' => $mapping['actions'] ?? [],
                    ]);

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
