<?php

namespace Marschux\GameQuery\Filament\Server\Widgets;

use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Marschux\GameQuery\Models\GameQuery;
use Marschux\GameQuery\Models\GameQuerySample;

class PlayerHistoryChart extends ChartWidget
{
    protected ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return trans('game-query::query.history');
    }

    public static function canView(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        /** @var ?GameQuery $gameQuery */
        $gameQuery = $server->egg->gameQuery; // @phpstan-ignore property.notFound

        if (!$gameQuery?->track_history) {
            return false;
        }

        // an empty chart says nothing - wait until there is something to draw
        return GameQuerySample::query()->where('server_id', $server->id)->exists();
    }

    protected function getData(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $samples = GameQuerySample::query()
            ->where('server_id', $server->id)
            ->orderByDesc('created_at')
            ->limit(288)
            ->get()
            ->reverse();

        return [
            'datasets' => [
                [
                    'label' => trans('game-query::query.field.players'),
                    'data' => $samples->pluck('players')->all(),
                    'borderColor' => 'rgb(59, 130, 246)',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $samples->map(fn (GameQuerySample $sample) => $sample->created_at?->format('d.m. H:i'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                // player counts are whole numbers - no 2.5 players on the axis
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}
