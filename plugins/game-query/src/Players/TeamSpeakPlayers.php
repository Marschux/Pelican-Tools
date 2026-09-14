<?php

namespace Marschux\GameQuery\Players;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use Marschux\GameQuery\Query\TeamSpeakWebQuery;

/**
 * TeamSpeak has no list files to edit - everything lives in its own database and is reached
 * through the same WebQuery interface the server info already uses.
 */
class TeamSpeakPlayers extends PlayerManager
{
    /** ServerQuery's "database empty" answer, which is what an empty ban list looks like. */
    private const EMPTY_RESULT = 1281;

    /** Kick reason "kicked from server" rather than "kicked from channel". */
    private const KICK_FROM_SERVER = 5;

    public function tabs(): array
    {
        return ['online', 'bans'];
    }

    public function canRead(string $tab): bool
    {
        return $tab === 'online' || $this->can(SubuserPermission::ControlConsole);
    }

    /** Only unbanning; adding a ban by hand would need a uid/ip form and is not in this version. */
    public function canWrite(string $tab): bool
    {
        return $tab === 'bans' && $this->can(SubuserPermission::ControlConsole);
    }

    public function canAdd(string $tab): bool
    {
        return false;
    }

    public function canKick(): bool
    {
        return $this->can(SubuserPermission::ControlConsole);
    }

    public function canBan(): bool
    {
        return $this->canKick();
    }

    public function rows(string $tab): array
    {
        $rows = [];

        if ($tab === 'online') {
            foreach ($this->call('clientlist') as $client) {
                // client_type 1 is a query connection - our own polling is one of those
                if ((string) ($client['client_type'] ?? '1') !== '0') {
                    continue;
                }

                $clid = (string) ($client['clid'] ?? '');

                if ($clid !== '') {
                    $rows[$clid] = ['name' => (string) ($client['client_nickname'] ?? $clid), 'id' => $clid];
                }
            }

            return $rows;
        }

        foreach ($this->call('banlist') as $ban) {
            $banid = (string) ($ban['banid'] ?? '');

            if ($banid === '') {
                continue;
            }

            $rows[$banid] = [
                'name' => (string) ($ban['name'] ?? $ban['lastnickname'] ?? $ban['uid'] ?? $ban['ip'] ?? $banid),
                'id' => $banid,
                'reason' => $ban['reason'] ?? null,
            ];
        }

        return $rows;
    }

    public function kick(array $row): string
    {
        return $this->act('clientkick', ['clid' => $this->id($row), 'reasonid' => self::KICK_FROM_SERVER]);
    }

    public function ban(array $row): string
    {
        return $this->act('banclient', ['clid' => $this->id($row), 'time' => 0]);
    }

    public function remove(string $tab, array $row): string
    {
        if ($tab !== 'bans') {
            throw PlayerActionException::make('unsupported');
        }

        return $this->act('bandel', ['banid' => $this->id($row)]);
    }

    /** @param  array<string, mixed>  $row */
    private function id(array $row): int
    {
        $id = (string) ($row['id'] ?? '');

        if (!ctype_digit($id)) {
            throw PlayerActionException::make('failed');
        }

        return (int) $id;
    }

    /** @param  array<string, string|int>  $params */
    private function act(string $command, array $params): string
    {
        $this->call($command, $params);

        Activity::event('server:console.command')
            ->property('command', TeamSpeakWebQuery::url('', 0, $command, $params))
            ->log();

        return trans('game-query::query.players.messages.sent');
    }

    /**
     * @param  array<string, string|int>  $params
     * @return array<int, array<string, mixed>>
     */
    private function call(string $command, array $params = []): array
    {
        $endpoint = $this->gameQuery->endpoint($this->server);

        if ($endpoint === null) {
            throw PlayerActionException::make('failed');
        }

        [$ip, $port] = $endpoint;

        $envelope = TeamSpeakWebQuery::call($this->server, $ip, $port, $command, $params);

        if ($envelope === null) {
            throw PlayerActionException::make('server_offline');
        }

        $code = (int) ($envelope['status']['code'] ?? 1);

        // an empty ban list answers with an error code rather than an empty body
        if ($code === self::EMPTY_RESULT) {
            return [];
        }

        if ($code !== 0) {
            throw PlayerActionException::make('teamspeak_error', ['message' => (string) ($envelope['status']['message'] ?? $code)]);
        }

        $body = $envelope['body'] ?? [];

        return is_array($body) ? $body : [];
    }
}
