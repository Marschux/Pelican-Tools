<?php

namespace Marschux\GameQuery\Query\Schemas;

use App\Models\Server;
use Marschux\GameQuery\Query\QueryPlayer;
use Marschux\GameQuery\Query\QueryResult;
use Marschux\GameQuery\Query\QueryTypeSchemaInterface;
use Marschux\GameQuery\Query\QueryTypeService;
use Throwable;
use xPaw\MinecraftPing;
use xPaw\MinecraftQuery;

class MinecraftJavaSchema implements QueryTypeSchemaInterface
{
    public function getId(): string
    {
        return 'minecraft_java';
    }

    public function getName(): string
    {
        return 'Minecraft (Java)';
    }

    /**
     * Query first, ping second. The query protocol needs enable-query=true in server.properties
     * and is the only one of the two that reports the world name and the full player list;
     * the ping always works but only samples up to 12 players.
     */
    public function process(Server $server, string $ip, int $port): ?QueryResult
    {
        return $this->tryQuery($ip, $port) ?? $this->tryPing($ip, $port);
    }

    public function supportedFields(): array
    {
        // map and gameMode only come from the query variant, not the ping fallback - still
        // offered here since "supported" means the protocol as a whole, not every path
        return ['hostname', 'players', 'map', 'version', 'gameMode'];
    }

    protected function tryQuery(string $ip, int $port): ?QueryResult
    {
        $query = new MinecraftQuery();

        try {
            $query->Connect($ip, $port, QueryTypeService::timeout(), true);

            $info = $query->GetInfo();

            if (!is_array($info)) {
                return null;
            }

            $players = $query->GetPlayers();

            return new QueryResult(
                hostname: (string) ($info['HostName'] ?? ''),
                players: (int) ($info['Players'] ?? 0),
                maxPlayers: (int) ($info['MaxPlayers'] ?? 0),
                map: $info['Map'] ?? null,
                version: $info['Version'] ?? null,
                gameMode: $info['GameMode'] ?? $info['GameType'] ?? null,
                playerList: is_array($players)
                    ? array_map(fn ($name) => new QueryPlayer(name: (string) $name), $players)
                    : null,
            );
        } catch (Throwable $exception) {
            QueryTypeService::reportFailure($exception);
        }

        return null;
    }

    protected function tryPing(string $ip, int $port): ?QueryResult
    {
        $ping = null;

        try {
            $ping = new MinecraftPing($ip, $port, QueryTypeService::timeout(), true);

            $data = $ping->Query();

            if (!is_array($data)) {
                return null;
            }

            return new QueryResult(
                hostname: $this->description($data['description'] ?? ''),
                players: (int) ($data['players']['online'] ?? 0),
                maxPlayers: (int) ($data['players']['max'] ?? 0),
                version: $data['version']['name'] ?? null,
                // the ping returns a data: uri already, usable straight from an <img src>
                icon: $data['favicon'] ?? null,
                playerList: $this->sample($data['players']['sample'] ?? null),
            );
        } catch (Throwable $exception) {
            QueryTypeService::reportFailure($exception);
        } finally {
            $ping?->Close();
        }

        return null;
    }

    /** The MOTD is either a plain string or a chat component with nested "extra" parts. */
    protected function description(mixed $description): string
    {
        if (is_string($description)) {
            return $description;
        }

        if (!is_array($description)) {
            return '';
        }

        $text = (string) ($description['text'] ?? '');

        foreach ($description['extra'] ?? [] as $part) {
            $text .= is_string($part) ? $part : ($part['text'] ?? '');
        }

        return $text;
    }

    /** @return ?QueryPlayer[] */
    protected function sample(mixed $sample): ?array
    {
        if (!is_array($sample)) {
            return null;
        }

        return array_map(fn ($player) => new QueryPlayer(
            name: (string) ($player['name'] ?? ''),
            id: $player['id'] ?? null,
        ), $sample);
    }
}
