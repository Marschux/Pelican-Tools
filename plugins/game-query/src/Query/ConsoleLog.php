<?php

namespace Marschux\GameQuery\Query;

use App\Models\Server;
use App\Repositories\Daemon\DaemonRepository;
use Throwable;

/**
 * Reads the tail of a server's console output through the daemon.
 *
 * DaemonServerRepository has no method for this - it only wraps the install logs - so the
 * daemon endpoint is called directly. Wings caps the response at 100 lines, which is the hard
 * limit on how far back anything here can look.
 */
class ConsoleLog extends DaemonRepository
{
    public const MAX_LINES = 100;

    /** @return string[] newest last, empty when the log could not be read */
    public static function tail(Server $server): array
    {
        try {
            $repository = (new static())->setServer($server);

            $response = $repository->getHttpClient()
                ->timeout(QueryTypeService::timeout())
                ->get("/api/servers/{$server->uuid}/logs", ['size' => self::MAX_LINES]);

            $data = $response->json('data');

            return is_array($data) ? array_map(strval(...), $data) : [];
        } catch (Throwable $exception) {
            QueryTypeService::reportFailure($exception);

            return [];
        }
    }

    /**
     * Last line matching the pattern, searched from the newest backwards.
     *
     * @param  string[]  $lines
     * @return ?string[] the match groups, or null
     */
    public static function lastMatch(array $lines, string $pattern): ?array
    {
        foreach (array_reverse($lines) as $line) {
            if (preg_match($pattern, $line, $matches) === 1) {
                return $matches;
            }
        }

        return null;
    }
}
