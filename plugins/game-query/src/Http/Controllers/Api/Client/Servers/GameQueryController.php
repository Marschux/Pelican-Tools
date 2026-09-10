<?php

namespace Marschux\GameQuery\Http\Controllers\Api\Client\Servers;

use App\Http\Controllers\Api\Client\ClientApiController;
use App\Models\Server;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Marschux\GameQuery\Models\GameQuery;
use Marschux\GameQuery\Query\QueryPlayer;
use Marschux\GameQuery\Query\QueryResult;
use Symfony\Component\HttpKernel\Exception\HttpException;

#[Group('Server - Game Query')]
class GameQueryController extends ClientApiController
{
    /**
     * Get query
     *
     * Returns hostname, map, player counts and whatever else the protocol reports.
     *
     * @throws HttpException
     */
    public function query(Server $server): JsonResponse
    {
        $data = $this->runQuery($server)->toArray();

        unset($data['players_list']);

        return response()->json($data);
    }

    /**
     * Get players
     *
     * Returns the current players.
     *
     * @throws HttpException
     */
    public function players(Server $server): JsonResponse
    {
        $players = $this->runQuery($server)->playerList;

        if ($players === null) {
            abort(Response::HTTP_NOT_ACCEPTABLE, 'Server query has no player list');
        }

        return response()->json(array_map(fn (QueryPlayer $player) => [
            'name' => $player->name,
            'score' => $player->score,
            'playtime' => $player->playtime,
        ], $players));
    }

    /** @throws HttpException */
    private function runQuery(Server $server): QueryResult
    {
        if (!GameQuery::canRunQuery($server->allocation)) {
            abort(Response::HTTP_NOT_ACCEPTABLE, 'Server has invalid allocation');
        }

        /** @var ?GameQuery $gameQuery */
        $gameQuery = $server->egg->gameQuery; // @phpstan-ignore property.notFound

        if (!$gameQuery) {
            abort(Response::HTTP_NOT_ACCEPTABLE, 'Server has no query');
        }

        if ($server->retrieveStatus()->isOffline()) {
            abort(Response::HTTP_NOT_ACCEPTABLE, 'Server is offline');
        }

        return $gameQuery->runQuery($server) ?? abort(Response::HTTP_BAD_GATEWAY, 'Server did not answer the query');
    }
}
