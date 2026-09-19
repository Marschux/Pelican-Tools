<?php

namespace Marschux\GameQuery\Query\Schemas;

use App\Models\Server;
use Marschux\GameQuery\Query\QueryPlayer;
use Marschux\GameQuery\Query\QueryResult;
use Marschux\GameQuery\Query\QueryTypeSchemaInterface;
use Marschux\GameQuery\Query\QueryTypeService;
use Throwable;
use xPaw\SourceQuery\SourceQuery;

class SourceSchema implements QueryTypeSchemaInterface
{
    public function getId(): string
    {
        return 'source';
    }

    public function getName(): string
    {
        return 'Source Engine (A2S) - CS2, CS:S, TF2, GMod, L4D';
    }

    public function process(Server $server, string $ip, int $port): ?QueryResult
    {
        $result = $this->run($ip, $port, SourceQuery::SOURCE);

        // CS2's A2S reports one bot too few, so players minus bots shows a phantom human, and
        // its player list has no names to tell them apart. RCON status counts correctly - used
        // whenever the egg has an RCON password, A2S alone otherwise.
        $password = $server->variables()->where('env_variable', 'RCON_PASSWORD')->first()?->server_value;

        if ($result && $password && ($counts = $this->rconCounts($ip, $port, $password))) {
            [$humans, $bots] = $counts;
            $result = $result->withCounts($humans + $bots, $bots);
        }

        // CS2 puts only "secure" into its A2S keywords, the mode itself comes from the egg
        $variables = $server->variables()->whereIn('env_variable', ['GAME_TYPE', 'GAME_MODE'])->get()->pluck('server_value', 'env_variable');

        if ($result && ($mode = self::cs2Mode($variables['GAME_TYPE'] ?? null, $variables['GAME_MODE'] ?? null))) {
            $result = $result->withGameMode($mode);
        }

        return $result;
    }

    public static function cs2Mode(?string $type, ?string $mode): ?string
    {
        return match ("$type/$mode") {
            '0/0' => 'Casual',
            '0/1' => 'Competitive',
            '0/2' => 'Wingman',
            '1/0' => 'Arms Race',
            '1/2' => 'Deathmatch',
            '3/0' => 'Custom',
            default => null,
        };
    }

    /** @return ?array{int, int} humans and bots, null when RCON is off, refused or unreadable */
    private function rconCounts(string $ip, int $port, string $password): ?array
    {
        $query = new SourceQuery();

        try {
            $query->Connect($ip, $port, QueryTypeService::timeout(), SourceQuery::SOURCE);
            $query->SetRconPassword($password);

            return self::parseStatusCounts((string) $query->Rcon('status'));
        } catch (Throwable $exception) {
            QueryTypeService::reportFailure($exception);

            return null;
        } finally {
            $query->Disconnect();
        }
    }

    /** "players  : 1 humans, 9 bots (0 max) ..." from CS2's status. @return ?array{int, int} */
    public static function parseStatusCounts(string $status): ?array
    {
        return preg_match('/players\s*:\s*(\d+) humans?, (\d+) bots?/', $status, $match)
            ? [(int) $match[1], (int) $match[2]]
            : null;
    }

    public function supportedFields(): array
    {
        return ['hostname', 'players', 'map', 'bots', 'version', 'gameMode', 'secure', 'password'];
    }

    protected function run(string $ip, int $port, int $engine): ?QueryResult
    {
        $query = new SourceQuery();

        try {
            $query->Connect($ip, $port, QueryTypeService::timeout(), $engine);

            $info = $query->GetInfo();

            return new QueryResult(
                hostname: (string) ($info['HostName'] ?? ''),
                players: (int) ($info['Players'] ?? 0),
                maxPlayers: (int) ($info['MaxPlayers'] ?? 0),
                map: $info['Map'] ?? null,
                bots: isset($info['Bots']) ? (int) $info['Bots'] : null,
                version: $info['Version'] ?? null,
                gameMode: $this->gameMode($info),
                passworded: isset($info['Password']) ? (bool) $info['Password'] : null,
                secure: isset($info['Secure']) ? (bool) $info['Secure'] : null,
                playerList: $this->players($query),
            );
        } catch (Throwable $exception) {
            QueryTypeService::reportFailure($exception);
        } finally {
            $query->Disconnect();
        }

        return null;
    }

    /** @param array<string, mixed> $info */
    protected function gameMode(array $info): ?string
    {
        // ModDesc is the readable one ("Counter-Strike 2"); Keywords carries the gamemode tags
        // servers advertise to the master server and is the more useful value when present
        foreach (['Keywords', 'GameTags', 'ModDesc'] as $key) {
            if (!empty($info[$key])) {
                return (string) $info[$key];
            }
        }

        return null;
    }

    /** @return ?QueryPlayer[] */
    protected function players(SourceQuery $query): ?array
    {
        try {
            $players = $query->GetPlayers();
        } catch (Throwable $exception) {
            // some servers answer A2S_INFO but refuse A2S_PLAYER - info is still worth showing
            QueryTypeService::reportFailure($exception);

            return null;
        }

        return array_map(fn ($player) => new QueryPlayer(
            name: (string) ($player['Name'] ?? ''),
            score: isset($player['Frags']) ? (int) $player['Frags'] : null,
            playtime: isset($player['Time']) ? (int) $player['Time'] : null,
        ), $players);
    }
}
