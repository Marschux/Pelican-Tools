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
                /** @var ?GameQuery $gameQuery */
                $gameQuery = $record->egg->gameQuery; // @phpstan-ignore property.notFound

                if (!$gameQuery || !GameQuery::canRunQuery($record->allocation)) {
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
