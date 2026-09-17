<?php

namespace Marschux\ServerResources\Filament\Admin\Widgets;

use App\Enums\ServerResourceType;
use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Filament\Admin\Resources\Eggs\EggResource;
use App\Filament\Admin\Resources\Nodes\NodeResource;
use App\Filament\Admin\Resources\Servers\ServerResource;
use App\Filament\Server\Pages\Console;
use App\Models\Node;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Filament\Notifications\Notification;
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

    /** The power buttons per server row, with the permission the console page requires for each. */
    private const POWER = [
        'start' => ['icon' => 'tabler-player-play-filled', 'color' => 'success', 'permission' => SubuserPermission::ControlStart],
        'restart' => ['icon' => 'tabler-reload', 'color' => 'gray', 'permission' => SubuserPermission::ControlRestart],
        'stop' => ['icon' => 'tabler-player-stop-filled', 'color' => 'danger', 'permission' => SubuserPermission::ControlStop],
    ];

    public static function canView(): bool
    {
        return (bool) user()?->can('viewList node');
    }

    /**
     * Sends a power action from a row's button. Called straight from the browser, so the server,
     * the action and the permission are all checked again here rather than trusted from the view.
     */
    public function power(int $serverId, string $action): void
    {
        $server = Server::query()->find($serverId);

        if (!$server || !isset(self::POWER[$action]) || !user()?->can(self::POWER[$action]['permission'], $server)) {
            return;
        }

        if ($server->isInConflictState() || !$this->allows($server, $action)) {
            Notification::make()->title(trans('server-resources::resources.power.not_now'))->warning()->send();

            return;
        }

        try {
            app(DaemonServerRepository::class)->setServer($server)->power($action);
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title(trans('server-resources::resources.power.failed'))->body($exception->getMessage())->danger()->send();

            return;
        }

        Activity::event("server:power.$action")->subject($server)->log();

        // the cached status would still show the old state until it expires
        cache()->forget("servers.$server->uuid.status");

        Notification::make()->title(trans("server-resources::resources.power.sent.$action", ['server' => $server->name]))->success()->send();
    }

    /** The same state checks the console page's own power buttons use. */
    private function allows(Server $server, string $action): bool
    {
        if ($server->isInConflictState()) {
            return false;
        }

        $status = $server->retrieveStatus();

        return match ($action) {
            'start' => $status->isStartable(),
            'restart' => $status->isRestartable(),
            'stop' => $status->isStoppable(),
            default => false,
        };
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
            'egg_url' => $server->egg ? EggResource::getUrl('edit', ['record' => $server->egg]) : null,
            'console_url' => Console::getUrl(panel: 'server', tenant: $server),
            'status' => null,
            'color' => 'gray',
            'cpu' => null,
            'memory' => null,
            'disk' => null,
            'players' => null,
            'id' => $server->id,
            // which power buttons are offered, and which of them the current state allows
            'power' => [],
        ];

        // an unreachable node would cost a daemon timeout per server - its servers show no values
        if (!$nodeReachable) {
            return $row;
        }

        try {
            $condition = $server->condition;

            $row['status'] = $condition->getLabel();
            $row['color'] = $condition->getColor();

            foreach (self::POWER as $action => $button) {
                if (user()?->can($button['permission'], $server)) {
                    $row['power'][$action] = $button + ['enabled' => $this->allows($server, $action)];
                }
            }

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
