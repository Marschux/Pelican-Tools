<?php

namespace Marschux\GameQuery\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Marschux\GameQuery\Models\GameQuery;
use Marschux\GameQuery\Models\GameQuerySample;
use Throwable;

/**
 * Records one player count per tracked server, and prunes what aged out.
 *
 * ponytail: queries the servers one after another. Each unreachable one costs the full
 * timeout, so with many tracked servers this run gets long - fan out into per-server queued
 * jobs if that ever becomes a problem.
 */
class SampleGameQueriesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
        $queries = GameQuery::query()->where('track_history', true)->with('eggs')->get();

        if ($queries->isEmpty()) {
            return;
        }

        $eggIds = $queries->flatMap(fn (GameQuery $query) => $query->eggs->pluck('id'))->unique();

        foreach (Server::query()->whereIn('egg_id', $eggIds)->cursor() as $server) {
            $this->sample($server);
        }

        $this->prune();
    }

    private function sample(Server $server): void
    {
        try {
            // a stopped server would just cost a timeout and record nothing useful
            if ($server->isInConflictState() || $server->retrieveStatus()->isOffline()) {
                return;
            }

            /** @var ?GameQuery $gameQuery */
            $gameQuery = $server->egg->gameQuery; // @phpstan-ignore property.notFound

            $result = $gameQuery?->runQuery($server);

            if (!$result) {
                return;
            }

            GameQuerySample::create([
                'server_id' => $server->id,
                'players' => $result->humanPlayers(),
                'max_players' => $result->maxPlayers,
                'bots' => $result->bots,
            ]);
        } catch (Throwable $exception) {
            // one broken server must not stop the rest of the run
            report($exception);
        }
    }

    private function prune(): void
    {
        $days = max(1, (int) config('game-query.history_retention_days'));

        GameQuerySample::query()->where('created_at', '<', Carbon::now()->subDays($days))->delete();
    }
}
