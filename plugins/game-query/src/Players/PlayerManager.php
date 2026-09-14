<?php

namespace Marschux\GameQuery\Players;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Marschux\GameQuery\Models\GameQuery;
use Marschux\GameQuery\Query\QueryPlayer;

/**
 * What a game lets the panel do with its players.
 *
 * One subclass per game rather than a generic engine: the three of them have almost nothing in
 * common beyond "list something, change something". Each decides its own tabs, its own
 * permissions and - most importantly - validates every name, id and address itself before it
 * ever reaches a console command, a file or a url.
 */
abstract class PlayerManager
{
    /** Far above any real list file, only there to refuse something absurd. */
    protected const MAX_FILE_BYTES = 1048576;

    public function __construct(protected Server $server, protected GameQuery $gameQuery) {}

    public static function for(Server $server): ?self
    {
        $gameQuery = GameQuery::forServer($server);

        if (!$gameQuery) {
            return null;
        }

        return match ($gameQuery->query_type) {
            'minecraft_java' => new MinecraftPlayers($server, $gameQuery),
            'valheim' => new ValheimPlayers($server, $gameQuery),
            'teamspeak' => new TeamSpeakPlayers($server, $gameQuery),
            default => null,
        };
    }

    /** @return string[] tab ids, in display order */
    abstract public function tabs(): array;

    /** @return array<string, array<string, mixed>> rows keyed by whatever identifies them */
    abstract public function rows(string $tab): array;

    /** The online tab only shows what the query already reports, so it needs no extra permission. */
    public function canRead(string $tab): bool
    {
        return $tab === 'online';
    }

    public function canWrite(string $tab): bool
    {
        return false;
    }

    public function canAdd(string $tab): bool
    {
        return $this->canWrite($tab);
    }

    public function canKick(): bool
    {
        return false;
    }

    public function canBan(): bool
    {
        return false;
    }

    /** Translation key under players.columns for the first column, and under players.form for the input. */
    public function nameLabel(string $tab): string
    {
        return 'name';
    }

    public function inputLabel(string $tab): string
    {
        return $this->nameLabel($tab);
    }

    /** @param  array<string, mixed>  $row */
    public function add(string $tab, string $input): string
    {
        throw PlayerActionException::make('unsupported');
    }

    /** @param  array<string, mixed>  $row */
    public function remove(string $tab, array $row): string
    {
        throw PlayerActionException::make('unsupported');
    }

    /** @param  array<string, mixed>  $row */
    public function kick(array $row): string
    {
        throw PlayerActionException::make('unsupported');
    }

    /** @param  array<string, mixed>  $row */
    public function ban(array $row): string
    {
        throw PlayerActionException::make('unsupported');
    }

    /** True only when the viewer holds every one of these permissions on this server. */
    protected function can(SubuserPermission ...$permissions): bool
    {
        $user = auth()->user();

        foreach ($permissions as $permission) {
            if (!$user?->can($permission, $this->server)) {
                return false;
            }
        }

        return true;
    }

    protected function isRunning(): bool
    {
        return $this->server->retrieveStatus()->isStartingOrRunning();
    }

    /**
     * Send one console command.
     *
     * Server::send() only throws when the node itself is unreachable - a server that is not
     * running comes back as a plain error response, so the status code has to be checked or a
     * command that went nowhere would be reported as success.
     */
    protected function command(string $command): string
    {
        try {
            $response = $this->server->send($command);
        } catch (ConnectionException) {
            throw PlayerActionException::make('server_offline');
        }

        if ($response->getStatusCode() >= 300) {
            throw PlayerActionException::make('server_offline');
        }

        Activity::event('server:console.command')->property('command', $command)->log();

        return trans('game-query::query.players.messages.sent');
    }

    /** Null when the file does not exist yet - which is normal for a list nobody has used. */
    protected function readFile(string $path): ?string
    {
        try {
            return (string) $this->files()->getContent($path, static::MAX_FILE_BYTES);
        } catch (FileNotFoundException) {
            return null;
        }
    }

    protected function writeFile(string $path, string $content, bool $existed): string
    {
        abort_unless($this->can(...($existed ? [SubuserPermission::FileUpdate] : [SubuserPermission::FileUpdate, SubuserPermission::FileCreate])), 403);

        $this->files()->putContent($path, $content);

        Activity::event('server:file.write')->property('file', $path)->log();

        return trans('game-query::query.players.messages.saved');
    }

    /** @return array<string, array<string, mixed>> */
    protected function queriedPlayers(): array
    {
        $players = $this->gameQuery->runQuery($this->server)?->playerList;

        if ($players === null) {
            throw PlayerActionException::make('no_list');
        }

        $rows = [];

        foreach ($players as $player) {
            /** @var QueryPlayer $player */
            $rows[$player->name] = ['name' => $player->name];
        }

        return $rows;
    }

    protected function files(): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($this->server);
    }
}
