<?php

namespace Marschux\GameQuery\Query;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * TeamSpeak's WebQuery interface, plus the api key it needs.
 *
 * WebQuery wants a key on every call, and TeamSpeak hands one out only to someone already
 * authenticated as serveradmin. The Teamspeak 6 egg sets that password to the server's own uuid
 * ("--query-admin-password {{P_SERVER_UUID}}"), so this logs in over the SSH ServerQuery
 * interface once, mints a key and caches it - nobody has to run apikeyadd by hand.
 */
class TeamSpeakWebQuery
{
    /**
     * The bootstrap gets a far more generous timeout than a query: it runs once per server and is
     * then cached, and a pure-php key exchange needs seconds where a udp game query - what the
     * configurable timeout was sized for - is done in milliseconds.
     */
    private const SSH_TIMEOUT = 15;

    /**
     * The decoded answer envelope ({body: [...], status: {code, message}}), or null when the
     * server could not be reached or no key could be minted at all.
     *
     * @param  array<string, string|int>  $params
     * @return ?array<string, mixed>
     */
    public static function call(Server $server, string $ip, int $port, string $command, array $params = []): ?array
    {
        $apiKey = Cache::rememberForever(self::cacheKey($server), fn () => self::bootstrapApiKey($server, $ip));

        if ($apiKey === null) {
            return null;
        }

        try {
            $response = Http::withHeaders(['x-api-key' => $apiKey])
                ->timeout(QueryTypeService::timeout())
                ->get(self::url($ip, $port, $command, $params));
        } catch (Throwable $exception) {
            // a connection failure means the server is offline or unreachable, not that the key is
            // bad - it stays cached for the next call
            QueryTypeService::reportFailure($exception);

            return null;
        }

        $envelope = $response->json();

        return is_array($envelope) ? $envelope : null;
    }

    /** @param  array<string, string|int>  $params */
    public static function url(string $ip, int $port, string $command, array $params = []): string
    {
        // "1" is the virtual server id, not an api version. Commands are literals from our own
        // code; only the parameters can carry user input, hence http_build_query
        return "http://$ip:$port/1/$command" . ($params === [] ? '' : '?' . http_build_query($params));
    }

    /** Called when the server rejected the key, so the next call mints a fresh one. */
    public static function forgetApiKey(Server $server): void
    {
        Cache::forget(self::cacheKey($server));
    }

    private static function cacheKey(Server $server): string
    {
        return "game-query.teamspeak-apikey.$server->uuid";
    }

    private static function bootstrapApiKey(Server $server, string $ip): ?string
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

            // "manage" and not the far narrower "read": TeamSpeak does not count serverinfo as a
            // read command, and both read and write keys get "out of scope" back from it
            $ssh->write("apikeyadd scope=manage lifetime=0\n");
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
