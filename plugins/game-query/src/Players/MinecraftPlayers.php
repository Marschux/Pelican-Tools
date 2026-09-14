<?php

namespace Marschux\GameQuery\Players;

use App\Enums\SubuserPermission;
use Illuminate\Support\Facades\Http;
use Marschux\GameQuery\Query\QueryTypeService;
use Marschux\GameQuery\Query\Schemas\MinecraftJavaSchema;
use Throwable;

/**
 * Minecraft keeps its four lists as json files next to the world, and rewrites them itself while
 * it runs. So: a running server is changed through console commands and left to write the files,
 * a stopped one is changed by editing the files directly.
 */
class MinecraftPlayers extends PlayerManager
{
    private const FILES = [
        'admins' => 'ops.json',
        'bans' => 'banned-players.json',
        'ip_bans' => 'banned-ips.json',
        'whitelist' => 'whitelist.json',
    ];

    /** [add, remove] console command per list. */
    private const COMMANDS = [
        'admins' => ['op', 'deop'],
        'bans' => ['ban', 'pardon'],
        'ip_bans' => ['ban-ip', 'pardon-ip'],
        'whitelist' => ['whitelist add', 'whitelist remove'],
    ];

    /** Mojang's own rule. Everything that reaches a command or a url is checked against it. */
    public const NAME = '/^[A-Za-z0-9_]{3,16}$/';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const REASON = 'Banned by an operator.';

    public function tabs(): array
    {
        return ['online', 'admins', 'bans', 'ip_bans', 'whitelist'];
    }

    public function canRead(string $tab): bool
    {
        return $tab === 'online' || $this->can(SubuserPermission::FileReadContent);
    }

    /**
     * While the server is starting or stopping neither path is safe: a command may not arrive,
     * and a file written now can be overwritten a second later. Read-only until it settles.
     */
    public function canWrite(string $tab): bool
    {
        if (!isset(self::FILES[$tab])) {
            return false;
        }

        if ($this->isRunning()) {
            return $this->can(SubuserPermission::ControlConsole);
        }

        return $this->server->retrieveStatus()->isOffline() && $this->can(SubuserPermission::FileUpdate);
    }

    public function canKick(): bool
    {
        return $this->isRunning() && $this->can(SubuserPermission::ControlConsole);
    }

    public function canBan(): bool
    {
        return $this->canKick();
    }

    /** The ip ban list is keyed by address, so its input asks for one. */
    public function inputLabel(string $tab): string
    {
        return $tab === 'ip_bans' ? 'ip' : 'name';
    }

    public function rows(string $tab): array
    {
        if ($tab === 'online') {
            return $this->queriedPlayers();
        }

        $key = $this->keyField($tab);
        $rows = [];

        foreach ($this->list($tab) as $entry) {
            $id = (string) ($entry[$key] ?? '');

            if ($id === '') {
                continue;
            }

            $rows[$id] = [
                'name' => (string) ($entry['name'] ?? $entry['ip'] ?? $id),
                'uuid' => $entry['uuid'] ?? null,
                'reason' => $entry['reason'] ?? null,
            ];
        }

        return $rows;
    }

    public function add(string $tab, string $input): string
    {
        $input = trim($input);
        $isIp = $tab === 'ip_bans';

        if (!isset(self::FILES[$tab]) || !($isIp ? self::validIp($input) : self::validName($input))) {
            throw PlayerActionException::make($isIp ? 'invalid_ip' : 'invalid_name');
        }

        if ($this->isRunning()) {
            return $this->command(self::COMMANDS[$tab][0] . ' ' . $input);
        }

        $list = $this->list($tab);
        $entry = $isIp ? $this->ipEntry($input) : $this->playerEntry($tab, $input);
        $added = self::withEntry($list, $entry, $this->keyField($tab));

        if ($added === $list) {
            throw PlayerActionException::make('already_listed');
        }

        return $this->save($tab, $added);
    }

    public function remove(string $tab, array $row): string
    {
        $key = $this->keyField($tab);
        $value = (string) ($tab === 'ip_bans' ? ($row['name'] ?? '') : ($row['uuid'] ?? ''));
        $name = (string) ($row['name'] ?? '');

        if (!isset(self::FILES[$tab])) {
            throw PlayerActionException::make('unsupported');
        }

        if ($this->isRunning()) {
            $argument = $tab === 'ip_bans' ? $name : $name;

            if (!($tab === 'ip_bans' ? self::validIp($argument) : self::validName($argument))) {
                throw PlayerActionException::make($tab === 'ip_bans' ? 'invalid_ip' : 'invalid_name');
            }

            return $this->command(self::COMMANDS[$tab][1] . ' ' . $argument);
        }

        if ($tab !== 'ip_bans' && preg_match(self::UUID, $value) !== 1) {
            throw PlayerActionException::make('invalid_name');
        }

        return $this->save($tab, self::withoutEntry($this->list($tab), $key, $value));
    }

    public function kick(array $row): string
    {
        return $this->command('kick ' . $this->onlineName($row));
    }

    public function ban(array $row): string
    {
        return $this->command('ban ' . $this->onlineName($row));
    }

    private function onlineName(array $row): string
    {
        $name = (string) ($row['name'] ?? '');

        if (!self::validName($name)) {
            throw PlayerActionException::make('invalid_name');
        }

        return $name;
    }

    private function keyField(string $tab): string
    {
        return $tab === 'ip_bans' ? 'ip' : 'uuid';
    }

    /**
     * A list file that cannot be parsed is never written back - overwriting it would throw away
     * whatever is actually in there.
     *
     * @return array<int, array<string, mixed>>
     */
    private function list(string $tab): array
    {
        $content = $this->readFile(self::FILES[$tab]);

        if ($content === null || trim($content) === '') {
            return [];
        }

        $list = json_decode($content, true);

        if (!is_array($list)) {
            throw PlayerActionException::make('unreadable');
        }

        return $list;
    }

    /** @param  array<int, array<string, mixed>>  $list */
    private function save(string $tab, array $list): string
    {
        $path = self::FILES[$tab];

        return $this->writeFile(
            $path,
            json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $this->readFile($path) !== null,
        );
    }

    /** @return array<string, mixed> */
    private function playerEntry(string $tab, string $name): array
    {
        [$uuid, $name] = $this->profile($name);

        return match ($tab) {
            'admins' => ['uuid' => $uuid, 'name' => $name, 'level' => 4, 'bypassesPlayerLimit' => false],
            'bans' => [
                'uuid' => $uuid,
                'name' => $name,
                'created' => date('Y-m-d H:i:s O'),
                'source' => 'Server',
                'expires' => 'forever',
                'reason' => self::REASON,
            ],
            default => ['uuid' => $uuid, 'name' => $name],
        };
    }

    /** @return array<string, mixed> */
    private function ipEntry(string $ip): array
    {
        return [
            'ip' => $ip,
            'created' => date('Y-m-d H:i:s O'),
            'source' => 'Server',
            'expires' => 'forever',
            'reason' => self::REASON,
        ];
    }

    /**
     * Uuid and canonical spelling of a name.
     *
     * An offline-mode server derives uuids itself, so Mojang must not be asked - it would hand
     * out the uuid of the real account with that name, which this server would never use.
     *
     * @return array{0: string, 1: string}
     */
    private function profile(string $name): array
    {
        $properties = MinecraftJavaSchema::parseProperties((string) $this->readFile('server.properties'));

        if (($properties['online-mode'] ?? 'true') === 'false') {
            return [self::offlineUuid($name), $name];
        }

        try {
            $response = Http::timeout(QueryTypeService::timeout())
                ->get("https://api.mojang.com/users/profiles/minecraft/$name");
        } catch (Throwable $exception) {
            QueryTypeService::reportFailure($exception);

            throw PlayerActionException::make('mojang_unreachable');
        }

        $id = $response->json('id');

        if (in_array($response->status(), [204, 404], true) || !is_string($id)) {
            throw PlayerActionException::make('unknown_player');
        }

        return [self::dashed($id), (string) ($response->json('name') ?? $name)];
    }

    public static function validName(string $name): bool
    {
        return preg_match(self::NAME, $name) === 1;
    }

    public static function validIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * What an offline-mode server computes for a name: a version 3 uuid over
     * "OfflinePlayer:<name>".
     */
    public static function offlineUuid(string $name): string
    {
        $hash = md5('OfflinePlayer:' . $name, true);
        $hash[6] = chr(ord($hash[6]) & 0x0f | 0x30);
        $hash[8] = chr(ord($hash[8]) & 0x3f | 0x80);

        return self::dashed(bin2hex($hash));
    }

    /** Mojang returns uuids without dashes; the files store them with. */
    public static function dashed(string $hex): string
    {
        if (preg_match('/^[0-9a-f]{32}$/i', $hex) !== 1) {
            throw PlayerActionException::make('failed');
        }

        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        ]);
    }

    /**
     * Appends, unless something with the same key is already there. Existing entries keep every
     * field they have, including ones no version of this code knows about.
     *
     * @param  array<int, array<string, mixed>>  $list
     * @param  array<string, mixed>  $entry
     * @return array<int, array<string, mixed>>
     */
    public static function withEntry(array $list, array $entry, string $key): array
    {
        foreach ($list as $existing) {
            if (isset($existing[$key]) && strcasecmp((string) $existing[$key], (string) $entry[$key]) === 0) {
                return $list;
            }
        }

        $list[] = $entry;

        return array_values($list);
    }

    /**
     * @param  array<int, array<string, mixed>>  $list
     * @return array<int, array<string, mixed>>
     */
    public static function withoutEntry(array $list, string $key, string $value): array
    {
        // array_values, or a list with a hole in it encodes as a json object and minecraft
        // refuses to read it
        return array_values(array_filter(
            $list,
            fn ($entry) => !isset($entry[$key]) || strcasecmp((string) $entry[$key], $value) !== 0,
        ));
    }
}
