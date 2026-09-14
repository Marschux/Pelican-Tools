<?php

namespace Marschux\GameQuery\Query\Schemas;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Marschux\GameQuery\Query\NbtReader;
use Marschux\GameQuery\Query\QueryPlayer;
use Marschux\GameQuery\Query\QueryResult;
use Marschux\GameQuery\Query\QueryTypeSchemaInterface;
use Marschux\GameQuery\Query\QueryTypeService;
use Throwable;
use xPaw\MinecraftPing;
use xPaw\MinecraftQuery;

class MinecraftJavaSchema implements QueryTypeSchemaInterface
{
    /**
     * Where the world seed lives, relative to the world folder, newest layout first. 26.1 moved
     * it out of level.dat into its own file, and Paper keeps that file per dimension instead of
     * at the world root where vanilla puts it.
     */
    private const SEED_LOCATIONS = [
        ['dimensions/minecraft/overworld/data/minecraft/world_gen_settings.dat', ['data', 'seed']],
        ['data/minecraft/world_gen_settings.dat', ['data', 'seed']],
        ['level.dat', ['Data', 'WorldGenSettings', 'seed']],
    ];

    /** Far above any real server.properties or seed file, only there to refuse something absurd. */
    private const MAX_FILE_BYTES = 1048576;

    public function getId(): string
    {
        return 'minecraft_java';
    }

    public function getName(): string
    {
        return 'Minecraft: Java Edition';
    }

    /**
     * Query first, ping second - the query protocol needs enable-query=true in server.properties
     * and is the only one of the two with the full player list; the ping always works but only
     * samples up to 12 players.
     *
     * Neither protocol knows the seed, and the query's "game type" is always the literal "SMP",
     * so both of those come from the server's own files instead.
     */
    public function process(Server $server, string $ip, int $port): ?QueryResult
    {
        $result = $this->tryQuery($ip, $port) ?? $this->tryPing($ip, $port);

        if ($result === null) {
            return null;
        }

        $repository = app(DaemonFileRepository::class)->setServer($server);
        $properties = $this->properties($repository);

        return $result
            ->withGameMode($properties['gamemode'] ?? null)
            ->withSeed($this->seed($repository, $properties['level-name'] ?? 'world'));
    }

    public function supportedFields(): array
    {
        return ['hostname', 'version', 'gameMode', 'seed', 'players'];
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
                version: $info['Version'] ?? null,
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

    /**
     * Read line by line rather than with parse_ini_string(): a motd happily contains characters
     * ini syntax treats specially, and ini parsing would turn "true" into "1" on top.
     *
     * @return array<string, string>
     */
    private function properties(DaemonFileRepository $repository): array
    {
        try {
            $content = (string) $repository->getContent('server.properties', self::MAX_FILE_BYTES);
        } catch (Throwable $exception) {
            QueryTypeService::reportFailure($exception);

            return [];
        }

        $properties = [];

        foreach (preg_split('/\R/', $content) as $line) {
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $properties[trim($key)] = trim($value);
        }

        return $properties;
    }

    private function seed(DaemonFileRepository $repository, string $world): ?string
    {
        foreach (self::SEED_LOCATIONS as [$file, $path]) {
            try {
                $seed = NbtReader::find((string) $repository->getContent("$world/$file", self::MAX_FILE_BYTES), $path);
            } catch (Throwable) {
                // missing is the normal case for every layout but the one this server uses
                continue;
            }

            if (is_int($seed)) {
                return (string) $seed;
            }
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
