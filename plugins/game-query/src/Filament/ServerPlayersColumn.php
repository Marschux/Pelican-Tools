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
            ->state(function (Server $server) {
                /** @var ?GameQuery $gameQuery */
                $gameQuery = $server->egg->gameQuery; // @phpstan-ignore property.notFound

                if (!$gameQuery || !GameQuery::canRunQuery($server->allocation)) {
                    return null;
                }

                $result = $gameQuery->runQuery($server);

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
