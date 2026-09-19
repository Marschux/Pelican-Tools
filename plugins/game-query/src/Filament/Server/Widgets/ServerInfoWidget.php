<?php

namespace Marschux\GameQuery\Filament\Server\Widgets;

use App\Filament\Server\Components\SmallStatBlock;
use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Illuminate\Support\Facades\Lang;
use Marschux\GameQuery\Filament\Server\Components\MaskedStatBlock;
use Marschux\GameQuery\Models\GameQuery;
use Marschux\GameQuery\Query\QueryResult;

class ServerInfoWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    private const NONE = '–';

    // fixed, so every game gets the same grid - filament's default switches between 3 and 4
    // columns depending on how many fields a game shows, and one row for all of them squeezes
    // a long hostname into two lines
    protected function getColumns(): int
    {
        return 4;
    }

    public static function canView(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        if ($server->isInConflictState() || !GameQuery::canRunQuery($server->allocation)) {
            return false;
        }

        // shown for a stopped server too: its tiles stay in place with a dash, so the layout
        // does not jump around when the server starts or stops
        return GameQuery::forServer($server) !== null;
    }

    protected function getStats(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        $gameQuery = GameQuery::forServer($server);

        if (!$gameQuery) {
            // canView already ruled this out, so it can only mean the query was deleted
            // between the two calls
            return [SmallStatBlock::make(trans('game-query::query.query_label'), trans('game-query::query.no_query'))];
        }

        // a stopped server is not queried at all - that would only cost a full timeout
        $result = $server->retrieveStatus()->isOffline() ? null : $gameQuery->runQuery($server);

        $stats = [];

        // every ticked field keeps its tile; offline, a failed query or a value the server did
        // not report all show as a dash instead of making the tile disappear
        foreach ($gameQuery->visible_fields as $field) {
            if ($field === 'password') {
                // the password comes from a startup variable, so it is known without any query
                if ($stat = $this->passwordStat($server, $result?->password ?? $gameQuery->plainPassword($server))) {
                    $stats[] = $stat;
                }

                continue;
            }

            $value = $result ? $this->format($field, $result) : null;
            $stats[] = SmallStatBlock::make(trans("game-query::query.field.$field"), $value ?? self::NONE);
        }

        return $stats;
    }

    /**
     * The password is read from a startup variable rather than the protocol, so it needs its
     * own permission gate: GameQuery::plainPassword() already refused to attach a value that
     * is not flagged user-viewable, but that check is not specific to whoever is looking right
     * now. "startup.read" is the same permission the core Startup page itself requires before
     * showing any variable at all - a viewer without it must not see this either, even though
     * the value technically already made it into the (shared, cached) query result.
     */
    private function passwordStat(Server $server, ?string $password): SmallStatBlock|MaskedStatBlock|null
    {
        if (!auth()->user()?->can('startup.read', $server)) {
            return null;
        }

        return $password === null
            ? SmallStatBlock::make(trans('game-query::query.field.password'), self::NONE)
            : MaskedStatBlock::make(trans('game-query::query.field.password'), $password);
    }

    /**
     * "3 / 32" humans only - the bots have their own tile here. Widened by the slots a server
     * keeps reserved when the protocol reports them: on their own they are a number without a
     * reference point.
     */
    private function playerCount(QueryResult $result): string
    {
        $count = $result->humanPlayers() . ' / ' . $result->maxPlayers;

        return $result->reservedSlots === null ? $count : $count . ' (' . $result->reservedSlots . ')';
    }

    /**
     * Translated here and not in the schema: the query result is cached and shared by every
     * viewer, so a translated value would come out in whichever language happened to trigger
     * the query. Anything without a translation - a Source server's own mode name - passes through.
     */
    private function gameMode(?string $mode): ?string
    {
        if ($mode === null || $mode === '') {
            return null;
        }

        $key = 'game-query::query.gamemodes.' . strtolower($mode);

        return Lang::has($key) ? trans($key) : $mode;
    }

    private function format(string $field, QueryResult $result): ?string
    {
        return match ($field) {
            'players' => $this->playerCount($result),
            'bots' => $result->bots === null ? null : (string) $result->bots,
            'gameMode' => $this->gameMode($result->gameMode),
            'passworded', 'secure', 'whitelist' => match ($result->get($field)) {
                true => trans('game-query::query.yes'),
                false => trans('game-query::query.no'),
                default => null,
            },
            default => ($v = $result->get($field)) === null || $v === '' ? null : (string) $v,
        };
    }
}
