<?php

namespace Marschux\ServerResources\Filament\Admin\Widgets;

use Carbon\CarbonInterface;
use Filament\Widgets\Widget;
use Marschux\ServerResources\Host\HostStats;
use Marschux\ServerResources\Resources;

/**
 * The machine the panel itself runs on - not a node - as a card like the node cards. Read from
 * /proc, so it only shows on linux; in an LXC container those are the container's values.
 */
class PanelHostWidget extends Widget
{
    protected static ?int $sort = -2;

    protected string $view = 'server-resources::widgets.panel-host';

    protected int|string|array $columnSpan = 'full';

    /** The previous /proc/stat reading, shared by every viewer - see cpu(). */
    private const CPU_CACHE_KEY = 'server-resources.host.cpu_times';

    public static function canView(): bool
    {
        return is_readable('/proc/meminfo') && (bool) user()?->isRootAdmin();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $stat = self::read('/proc/stat');
        $memory = HostStats::memory(self::read('/proc/meminfo'));
        $load = HostStats::load(self::read('/proc/loadavg'));
        $uptime = HostStats::uptime(self::read('/proc/uptime'));

        $diskTotal = (int) (@disk_total_space(base_path()) ?: 0);
        $diskUsed = $diskTotal - (int) (@disk_free_space(base_path()) ?: 0);

        $cpu = $this->cpu($stat);

        $facts = [Resources::fact('tabler-cpu-2', trans('server-resources::resources.host.cores', ['count' => HostStats::cpuCount($stat)]))];

        if ($load) {
            $facts[] = Resources::fact('tabler-activity', trans('server-resources::resources.host.load') . ' ' . implode(' · ', array_map(fn (float $value) => format_number($value, 2), $load)));
        }

        if ($uptime !== null) {
            $facts[] = Resources::fact('tabler-clock', trans('server-resources::resources.host.uptime') . ' ' . now()->subSeconds($uptime)->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, parts: 2));
        }

        return [
            'bars' => [
                Resources::bar('cpu', $cpu ?? 0, 100, $cpu === null ? '-' : format_number($cpu, 1) . ' %'),
                Resources::bytesBar('memory', $memory['used'] ?? 0, $memory['total'] ?? 0),
                Resources::bytesBar('disk', $diskUsed, $diskTotal),
            ],
            'facts' => $facts,
        ];
    }

    /**
     * A cpu percentage needs two readings. The previous one sits in the cache, so the value is
     * the average since the last poll by anyone; the very first poll has nothing to compare to.
     */
    private function cpu(string $stat): ?float
    {
        $current = HostStats::cpuTimes($stat);

        if ($current === null) {
            return null;
        }

        $previous = cache()->get(self::CPU_CACHE_KEY);
        cache()->put(self::CPU_CACHE_KEY, $current, now()->addMinutes(5));

        return is_array($previous) ? HostStats::cpuPercent($previous, $current) : null;
    }

    private static function read(string $path): string
    {
        return is_readable($path) ? (string) @file_get_contents($path) : '';
    }
}
