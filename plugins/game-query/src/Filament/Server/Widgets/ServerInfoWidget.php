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

    public static function canView(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        if ($server->isInConflictState() || !GameQuery::canRunQuery($server->allocation)) {
            return false;
        }

        if (!GameQuery::forServer($server)) {
            return false;
        }

        return !$server->retrieveStatus()->isOffline();
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

        $result = $gameQuery->runQuery($server);

        if (!$result) {
            // silently rendering nothing here is what makes a misconfigured port or an
            // unreachable address look like "the plugin is not installed"
            return [SmallStatBlock::make(trans('game-query::query.query_label'), trans('game-query::query.query_failed'))];
        }

        $stats = [];

        foreach ($gameQuery->visible_fields as $field) {
            if ($field === 'password') {
                if ($stat = $this->passwordStat($server, $result)) {
                    $stats[] = $stat;
                }

                continue;
            }

            $value = $this->format($field, $result);

            // null means the protocol cannot report this field - show nothing rather than
            // a row of "unknown" for every field the game does not support
            if ($value !== null) {
                $stats[] = SmallStatBlock::make(trans("game-query::query.field.$field"), $value);
            }
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
    private function passwordStat(Server $server, QueryResult $result): ?MaskedStatBlock
    {
        if ($result->password === null || !auth()->user()?->can('startup.read', $server)) {
            return null;
        }

        return MaskedStatBlock::make(trans('game-query::query.field.password'), $result->password);
    }

    /**
     * "3 / 32", widened by the bots a game counts towards that number and by the slots a server
     * keeps reserved - each only when the protocol reports it. Reserved slots share this tile
     * instead of getting their own: on their own they are a number without a reference point.
     */
    private function playerCount(QueryResult $result): string
    {
        $count = $result->bots
            ? trans('game-query::query.players_with_bots', [
                'players' => $result->humanPlayers(),
                'max' => $result->maxPlayers,
                'bots' => $result->bots,
            ])
            : $result->players . ' / ' . $result->maxPlayers;

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
            'passworded', 'secure' => match ($result->get($field)) {
                true => trans('game-query::query.yes'),
                false => trans('game-query::query.no'),
                default => null,
            },
            default => ($v = $result->get($field)) === null || $v === '' ? null : (string) $v,
        };
    }
}
