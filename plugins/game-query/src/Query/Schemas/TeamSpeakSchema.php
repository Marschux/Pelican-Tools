<?php

namespace Marschux\GameQuery\Query\Schemas;

use App\Models\Server;
use Illuminate\Support\Facades\Http;
use Marschux\GameQuery\Query\QueryResult;
use Marschux\GameQuery\Query\QueryTypeSchemaInterface;
use Marschux\GameQuery\Query\QueryTypeService;
use Throwable;

class TeamSpeakSchema implements QueryTypeSchemaInterface
{
    /**
     * TeamSpeak has no anonymous status query - WebQuery needs an API key, generated once via
     * ServerQuery ("apikeyadd scope=read") and pasted into this startup variable on the egg.
     * Read from the server the same way GameQuery::plainPassword() reads the connect password,
     * so this needs no secret storage of its own on the plugin side.
     */
    private const API_KEY_VARIABLE = 'QUERY_API_KEY';

    public function getId(): string
    {
        return 'teamspeak';
    }

    public function getName(): string
    {
        return 'TeamSpeak (WebQuery)';
    }

    public function process(Server $server, string $ip, int $port): ?QueryResult
    {
        $apiKey = $this->apiKey($server);

        if ($apiKey === null) {
            return null;
        }

        try {
            $response = Http::withHeaders(['x-api-key' => $apiKey])
                ->timeout(QueryTypeService::timeout())
                ->get("http://$ip:$port/1/serverinfo");

            $info = $response->json('body.0');

            if (!is_array($info) || (int) ($response->json('status.code') ?? 1) !== 0) {
                return null;
            }

            return new QueryResult(
                hostname: (string) ($info['virtualserver_name'] ?? ''),
                players: (int) ($info['virtualserver_clientsonline'] ?? 0),
                maxPlayers: (int) ($info['virtualserver_maxclients'] ?? 0),
                version: $info['virtualserver_version'] ?? null,
                passworded: isset($info['virtualserver_flag_password']) ? (bool) $info['virtualserver_flag_password'] : null,
                reservedSlots: isset($info['virtualserver_reserved_slots']) ? (int) $info['virtualserver_reserved_slots'] : null,
            );
        } catch (Throwable $exception) {
            QueryTypeService::reportFailure($exception);
        }

        return null;
    }

    public function supportedFields(): array
    {
        return ['hostname', 'players', 'version', 'reservedSlots'];
    }

    private function apiKey(Server $server): ?string
    {
        $variable = $server->variables()->where('env_variable', self::API_KEY_VARIABLE)->first();

        return $variable && $variable->user_viewable && $variable->server_value ? $variable->server_value : null;
    }
}
