<?php

namespace Marschux\GameQuery\Query\Schemas;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Marschux\GameQuery\Query\QueryResult;
use Marschux\GameQuery\Query\QueryTypeSchemaInterface;
use Marschux\GameQuery\Query\QueryTypeService;
use phpseclib3\Net\SSH2;
use Throwable;

class TeamSpeakSchema implements QueryTypeSchemaInterface
{
    /**
     * The bootstrap gets a far more generous timeout than the query itself: it runs once per
     * server and is then cached, and a pure-php key exchange needs seconds where the udp game
     * queries that the configurable timeout was sized for are done in milliseconds.
     */
    private const SSH_TIMEOUT = 15;

    public function getId(): string
    {
        return 'teamspeak';
    }

    public function getName(): string
    {
        return 'TeamSpeak (WebQuery)';
    }

    /**
     * WebQuery needs an api key for every call, and TeamSpeak has no way to hand out a
     * read-only one without first authenticating as serveradmin. The Teamspeak 6 egg sets the
     * serveradmin password to the server's own uuid ("--query-admin-password {{P_SERVER_UUID}}"),
     * so this can log in via the SSH ServerQuery interface on its own, mint a key once and
     * cache it forever - no manual apikeyadd/copy-paste step for whoever sets this up.
     */
    public function process(Server $server, string $ip, int $port): ?QueryResult
    {
        $cacheKey = "game-query.teamspeak-apikey.$server->uuid";
        $apiKey = Cache::rememberForever($cacheKey, fn () => $this->bootstrapApiKey($server, $ip));

        if ($apiKey === null) {
            return null;
        }

        try {
            $response = Http::withHeaders(['x-api-key' => $apiKey])
                ->timeout(QueryTypeService::timeout())
                ->get("http://$ip:$port/1/serverinfo");
        } catch (Throwable $exception) {
            // a connection failure means the server is offline/unreachable, not that the key is
            // bad - the cached key is still worth keeping for the next poll
            QueryTypeService::reportFailure($exception);

            return null;
        }

        $info = $response->json('body.0');
        $ok = (int) ($response->json('status.code') ?? 1) === 0;

        if (!$ok) {
            // the server did answer, just rejected the key (reinstalled, key deleted by hand,
            // ...) - drop it so the next poll bootstraps a fresh one instead of failing forever
            Cache::forget($cacheKey);
        }

        if (!is_array($info) || !$ok) {
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
    }

    public function supportedFields(): array
    {
        return ['hostname', 'players', 'version', 'reservedSlots'];
    }

    private function bootstrapApiKey(Server $server, string $ip): ?string
    {
        $sshPort = $server->variables()->where('env_variable', 'TSSERVER_QUERY_SSH_PORT')->first()?->server_value;

        if (!$sshPort || !is_numeric($sshPort)) {
            return null;
        }

        $ssh = new SSH2($ip, (int) $sshPort, self::SSH_TIMEOUT);
        $ssh->setTimeout(self::SSH_TIMEOUT);

        try {
            if (!$ssh->login('serveradmin', $server->uuid)) {
                return null;
            }

            $ssh->write("apikeyadd scope=read lifetime=0\n");
            // reading up to the digits rather than to "error id=" is what makes the check below
            // possible at all - stopping at the latter cuts the response off before its code
            $response = $ssh->read('/error id=\d+/', SSH2::READ_REGEX);

            if (!preg_match('/error id=0\b/', $response) || !preg_match('/apikey=(\S+)/', $response, $match)) {
                return null;
            }

            return $match[1];
        } catch (Throwable $exception) {
            QueryTypeService::reportFailure($exception);

            return null;
        } finally {
            $ssh->disconnect();
        }
    }
}
