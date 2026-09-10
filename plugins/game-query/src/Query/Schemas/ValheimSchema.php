<?php

namespace Marschux\GameQuery\Query\Schemas;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Marschux\GameQuery\Query\ConsoleLog;
use Marschux\GameQuery\Query\FwlSeedParser;
use Marschux\GameQuery\Query\QueryResult;
use Marschux\GameQuery\Query\QueryTypeService;
use Marschux\GameQuery\Query\ValheimLogParser;
use Throwable;
use xPaw\SourceQuery\SourceQuery;

/**
 * Valheim: a normal A2S query plus the world seed.
 *
 * The seed is not part of any network protocol - Valheim has no way to report it and no way to
 * set it on the command line. It only exists inside the world metadata file, so it is read
 * through the daemon file api rather than queried.
 */
class ValheimSchema extends SourceSchema
{
    private const SAVE_DIR = 'savegames/worlds_local';

    public function getId(): string
    {
        return 'valheim';
    }

    public function getName(): string
    {
        return 'Valheim';
    }

    public function process(Server $server, string $ip, int $port, bool $withRules = false): ?QueryResult
    {
        $result = $this->run($ip, $port, SourceQuery::SOURCE, $withRules)
            ?? $this->fromConsole($server);

        return $result?->withSeed($this->seed($server));
    }

    /**
     * With crossplay on, Valheim routes through the PlayFab relay and never opens the udp
     * ports locally, so no A2S query can reach it. It does log its session state though, and
     * that is the only place a player count exists in that mode.
     */
    private function fromConsole(Server $server): ?QueryResult
    {
        $lines = ConsoleLog::tail($server);

        $players = ValheimLogParser::players($lines);

        if ($players === null) {
            return null;
        }

        return new QueryResult(
            hostname: ValheimLogParser::hostname($lines) ?? '',
            players: $players,
            // valheim's own hard limit; the log does not carry it
            maxPlayers: 10,
            joinCode: ValheimLogParser::joinCode($lines),
        );
    }

    private function seed(Server $server): ?string
    {
        $world = $server->variables()->where('env_variable', 'WORLD')->first()?->server_value;

        if (!$world) {
            return null;
        }

        try {
            $repository = app(DaemonFileRepository::class)->setServer($server);

            $path = $this->metadataPath($repository, $world);

            if (!$path) {
                return null;
            }

            return FwlSeedParser::parse($repository->getContent($path, 4096));
        } catch (Throwable $exception) {
            QueryTypeService::reportFailure($exception);

            return null;
        }
    }

    /**
     * Pre-1.0 kept one "<world>.fwl" next to the save; 1.0 moved to a "<world>" folder holding
     * "_main.N.fwl2". Both are checked, newest metadata file first.
     */
    private function metadataPath(DaemonFileRepository $repository, string $world): ?string
    {
        $entries = collect($repository->getDirectory(self::SAVE_DIR));

        $legacy = $entries->first(fn ($entry) => ($entry['name'] ?? '') === "$world.fwl");

        if ($legacy) {
            return self::SAVE_DIR . "/$world.fwl";
        }

        $folder = $entries->first(fn ($entry) => ($entry['name'] ?? '') === $world && ($entry['directory'] ?? false));

        if (!$folder) {
            return null;
        }

        $file = collect($repository->getDirectory(self::SAVE_DIR . "/$world"))
            ->filter(fn ($entry) => str_ends_with($entry['name'] ?? '', '.fwl2'))
            ->sortByDesc('name')
            ->first();

        return $file ? self::SAVE_DIR . "/$world/" . $file['name'] : null;
    }
}
