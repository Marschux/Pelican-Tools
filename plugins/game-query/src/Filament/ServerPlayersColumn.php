<?php

namespace Marschux\GameQuery\Filament;

use App\Models\Server;
use Filament\Tables\Columns\TextColumn;
use Marschux\GameQuery\Models\GameQuery;

/**
 * Player count column for the admin and client server lists.
 *
 * Reads straight from the query cache. Without that cache this would open one socket per row
 * on every page load, so a short cache_ttl is what makes this column affordable at all.
 */
class ServerPlayersColumn
{
    public static function make(string $name = 'players'): TextColumn
    {
        return TextColumn::make($name)
            ->label(trans('game-query::query.field.players'))
            ->placeholder('-')
            ->badge()
            // an array state renders one badge per entry: players in blue, the bots next to it in gray
            ->color(fn (string $state) => str_contains($state, '/') ? 'info' : 'gray')
            // the parameter has to be named $record - that is the name filament injects the
            // row under; any other name falls through to container resolution
            ->state(function (Server $record) {
                $gameQuery = GameQuery::forServer($record);

                // the offline check matters here more than anywhere else: the dashboard polls every
                // 15s, and a stopped server would otherwise cost a full query timeout per row
                if (!$gameQuery || !GameQuery::canRunQuery($record->allocation) || $record->retrieveStatus()->isOffline()) {
                    return null;
                }

                $result = $gameQuery->runQuery($record);

                if (!$result) {
                    return null;
                }

                $players = $result->humanPlayers() . ' / ' . $result->maxPlayers;

                return $result->bots
                    ? [$players, trans('game-query::query.bots_count', ['count' => $result->bots])]
                    : $players;
            });
    }
}
