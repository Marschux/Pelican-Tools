<?php

namespace Marschux\ServerResources\Filament\Admin\Widgets;

use App\Enums\ServerResourceType;
use App\Filament\Admin\Resources\Nodes\NodeResource;
use App\Filament\Admin\Resources\Servers\ServerResource;
use App\Models\Node;
use App\Models\Server;
use Filament\Widgets\Widget;
use Marschux\GameQuery\Models\GameQuery;
use Marschux\ServerResources\Resources;
use Throwable;

/**
 * One card per node with its resources, and below it the servers running on that node.
 *
 * Everything is read through the panel's own cached helpers - Node::statistics() and
 * Server::retrieveResources() - so polling this costs no more daemon calls than the node and
 * server pages already make.
 */
class NodesOverviewWidget extends Widget
{
    protected static ?int $sort = -1;

    protected string $view = 'server-resources::widgets.nodes-overview';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) user()?->can('viewList node');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'nodes' => Node::query()
                ->with(['servers' => fn ($query) => $query->orderBy('name'), 'servers.egg', 'servers.allocation'])
                ->orderBy('name')
                ->get()
                ->map(fn (Node $node) => $this->node($node))
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function node(Node $node): array
    {
        $maintenance = $node->isUnderMaintenance();
        $reachable = !$maintenance && !isset($node->systemInformation()['exception']);
        $stats = $reachable ? $node->statistics() : null;
        // the cpu limit entered on the node, in percent - wings' own cpu count is what its process
        // may be scheduled on, which in an LXC can be every thread of the host, not the cores it got
        $cores = $node->cpu > 0 ? $node->cpu / 100 : null;

        return [
            'name' => $node->name,
            'fqdn' => $node->fqdn,
            'url' => NodeResource::getUrl('edit', ['record' => $node]),
            'state' => $maintenance ? 'maintenance' : ($reachable ? null : 'unreachable'),
            'bars' => $stats === null ? [] : [
                Resources::bar('cpu', (float) $stats['cpu_percent'], 100, format_number($stats['cpu_percent'], 1) . ' %'),
                Resources::bytesBar('memory', (int) $stats['memory_used'], (int) $stats['memory_total']),
                Resources::bytesBar('disk', (int) $stats['disk_used'], (int) $stats['disk_total']),
            ],
            'facts' => $stats === null ? [] : array_values(array_filter([
                $cores ? Resources::fact('tabler-cpu-2', trans('server-resources::resources.node.cpus', ['count' => format_number($cores, 0, 2), 'limit' => $node->cpu])) : null,
                Resources::fact('tabler-activity', trans('server-resources::resources.host.load') . ' ' . implode(' · ', array_map(fn ($value) => format_number((float) $value, 2), [$stats['load_average1'], $stats['load_average5'], $stats['load_average15']]))),
                Resources::fact('tabler-server', trans('server-resources::resources.node.servers', ['count' => $node->servers->count()])),
            ])),
            'servers' => $node->servers->map(fn (Server $server) => $this->server($server, $reachable))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function server(Server $server, bool $nodeReachable): array
    {
        $row = [
            'name' => $server->name,
            'url' => ServerResource::getUrl('edit', ['record' => $server]),
            'icon' => $server->icon ?: $server->egg?->icon,
            'egg' => $server->egg?->name,
            'status' => null,
            'color' => 'gray',
            'cpu' => null,
            'memory' => null,
            'disk' => null,
            'players' => null,
        ];

        // an unreachable node would cost a daemon timeout per server - its servers show no values
        if (!$nodeReachable) {
            return $row;
        }

        try {
            $condition = $server->condition;

            $row['status'] = $condition->getLabel();
            $row['color'] = $condition->getColor();
            $row['cpu'] = $server->formatResource(ServerResourceType::CPU, 0) . ' / ' . $server->formatResource(ServerResourceType::CPULimit, 0);
            $row['memory'] = $server->formatResource(ServerResourceType::Memory) . ' / ' . $server->formatResource(ServerResourceType::MemoryLimit);
            $row['disk'] = $server->formatResource(ServerResourceType::Disk) . ' / ' . $server->formatResource(ServerResourceType::DiskLimit);
            $row['players'] = $this->players($server);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $row;
    }

    /**
     * The player count from the game-query plugin, when it is installed - the same query its own
     * server list column runs, cached there, and skipped for a stopped server so it costs no
     * query timeout.
     */
    private function players(Server $server): ?string
    {
        if (!class_exists(GameQuery::class)) {
            return null;
        }

        $gameQuery = GameQuery::forServer($server);

        if (!$gameQuery || !GameQuery::canRunQuery($server->allocation) || $server->retrieveStatus()->isOffline()) {
            return null;
        }

        $result = $gameQuery->runQuery($server);

        if (!$result) {
            return null;
        }

        return $result->bots
            ? trans('game-query::query.players_with_bots', ['players' => $result->humanPlayers(), 'max' => $result->maxPlayers, 'bots' => $result->bots])
            : $result->players . ' / ' . $result->maxPlayers;
    }
}
