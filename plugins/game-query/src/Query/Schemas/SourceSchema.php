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
        return $this->run($ip, $port, SourceQuery::SOURCE);
    }

    public function supportedFields(): array
    {
        return ['hostname', 'players', 'map', 'bots', 'version', 'gameMode', 'secure'];
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
