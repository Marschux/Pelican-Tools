<?php

namespace Marschux\ServerResources\Filament\Admin\Widgets;

use Carbon\CarbonInterface;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Marschux\ServerResources\Host\HostStats;

/**
 * The machine the panel itself runs on - not a node. Read from /proc, so it only shows on linux.
 */
class PanelHostWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -2;

    protected ?string $pollingInterval = '10s';

    /** The previous /proc/stat reading, shared by every viewer - see cpu(). */
    private const CPU_CACHE_KEY = 'server-resources.host.cpu_times';

    public static function canView(): bool
    {
        return is_readable('/proc/meminfo') && (bool) user()?->isRootAdmin();
    }

    protected function getHeading(): ?string
    {
        return trans('server-resources::resources.host.heading');
    }

    protected function getStats(): array
    {
        $stat = self::read('/proc/stat');
        $memory = HostStats::memory(self::read('/proc/meminfo'));
        $load = HostStats::load(self::read('/proc/loadavg'));
        $uptime = HostStats::uptime(self::read('/proc/uptime'));

        $diskTotal = @disk_total_space(base_path()) ?: 0;
        $diskUsed = $diskTotal - (@disk_free_space(base_path()) ?: 0);

        $cpu = $this->cpu($stat);

        return [
            Stat::make(trans('server-resources::resources.cpu'), $cpu === null ? '-' : format_number($cpu, 1) . ' %')
                ->description(trans('server-resources::resources.host.cores', ['count' => HostStats::cpuCount($stat)])),
            Stat::make(trans('server-resources::resources.memory'), $memory ? convert_bytes_to_readable($memory['used']) . ' / ' . convert_bytes_to_readable($memory['total']) : '-'),
            Stat::make(trans('server-resources::resources.disk'), $diskTotal ? convert_bytes_to_readable((int) $diskUsed) . ' / ' . convert_bytes_to_readable((int) $diskTotal) : '-'),
            Stat::make(trans('server-resources::resources.host.load'), $load ? implode(' · ', array_map(fn (float $value) => format_number($value, 2), $load)) : '-')
                ->description(trans('server-resources::resources.host.load_hint')),
            Stat::make(trans('server-resources::resources.host.uptime'), $uptime === null ? '-' : now()->subSeconds($uptime)->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, parts: 2)),
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
