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

                return $result->bots
                    ? trans('game-query::query.players_with_bots', [
                        'players' => $result->humanPlayers(),
                        'max' => $result->maxPlayers,
                        'bots' => $result->bots,
                    ])
                    : $result->players . ' / ' . $result->maxPlayers;
            });
    }
}
