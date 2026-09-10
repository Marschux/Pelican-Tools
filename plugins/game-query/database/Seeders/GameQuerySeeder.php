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
     * Offsets are the difference between the game port and the A2S query port. Games that
     * query on the game port itself use no offset.
     */
    public const MAPPINGS = [
        ['tag' => 'minecraft', 'query_type' => 'minecraft_java', 'actions' => self::MINECRAFT_ACTIONS],
        ['tag' => 'source', 'query_type' => 'source', 'actions' => [self::KICK, self::BAN]],

        ['names' => ['Counter-Strike 1.6', 'Counter-Strike: Condition Zero', 'Half-Life Deathmatch'], 'query_type' => 'goldsrc', 'actions' => [self::KICK, self::BAN]],

        ['names' => 'Valheim', 'query_type' => 'source', 'offset' => 1],
        ['names' => ['The Forest', 'The Forrest'], 'query_type' => 'source', 'offset' => 1],
        ['names' => ['Sons Of The Forest', 'Sons of the Forest'], 'query_type' => 'source', 'offset' => 1],
        ['names' => ['Arma 3', 'Arma3'], 'query_type' => 'source', 'offset' => 1],
        ['names' => 'Conan Exiles', 'query_type' => 'source', 'offset' => 1],
        ['names' => 'Enshrouded', 'query_type' => 'source', 'offset' => 1],
        ['names' => ['LOTR: Return to Moria', 'Return to Moria'], 'query_type' => 'source', 'offset' => 1],
        ['names' => 'DayZ', 'query_type' => 'source', 'offset' => 27016],
        ['names' => 'Rust', 'query_type' => 'source', 'actions' => [self::KICK, self::BAN]],
    ];

    public function run(): void
    {
        foreach (Egg::all() as $egg) {
            foreach (self::MAPPINGS as $mapping) {
                if (!$this->matches($egg, $mapping)) {
                    continue;
                }

                try {
                    $query = GameQuery::firstOrCreate([
                        'query_type' => $mapping['query_type'],
                        'query_port_offset' => $mapping['offset'] ?? null,
                        'query_port_variable' => null,
                    ], [
                        'player_actions' => $mapping['actions'] ?? [],
                    ]);

                    EggGameQuery::firstOrCreate(['egg_id' => $egg->id], ['game_query_id' => $query->id]);
                } catch (Throwable) {
                    // an egg that already has a query is fine - unique key does its job
                }
            }
        }

        $this->command?->info('Created game queries for existing eggs');
    }

    /** @param array<string, mixed> $mapping */
    private function matches(Egg $egg, array $mapping): bool
    {
        if (isset($mapping['names'])) {
            return in_array($egg->name, (array) $mapping['names'], true);
        }

        return isset($mapping['tag']) && in_array($mapping['tag'], $egg->tags ?? [], true);
    }
}
