<?php

namespace Marschux\ServerResources\Host;

/**
 * Reads the panel host's own resources from /proc.
 *
 * The parsers take the file contents rather than paths, so they run without a linux box and
 * without laravel. In an LXC container lxcfs answers /proc/meminfo, /proc/uptime and
 * /proc/loadavg with the container's values; /proc/stat is the container's only when lxcfs
 * virtualises cpu as well, otherwise it is the host's.
 */
class HostStats
{
    /** @return array{total: int, used: int}|null bytes */
    public static function memory(string $meminfo): ?array
    {
        if (!preg_match('/^MemTotal:\s+(\d+) kB/m', $meminfo, $total) || !preg_match('/^MemAvailable:\s+(\d+) kB/m', $meminfo, $available)) {
            return null;
        }

        $total = (int) $total[1] * 1024;

        return ['total' => $total, 'used' => max(0, $total - (int) $available[1] * 1024)];
    }

    /**
     * The aggregate "cpu" line as [busy, total] jiffies. A percentage needs two of these: the
     * counters only ever grow, so it is the difference between two readings that tells.
     *
     * @return array{busy: int, total: int}|null
     */
    public static function cpuTimes(string $stat): ?array
    {
        if (!preg_match('/^cpu\s+(.+)$/m', $stat, $line)) {
            return null;
        }

        $values = array_map(intval(...), preg_split('/\s+/', trim($line[1])));

        if (count($values) < 4) {
            return null;
        }

        // user nice system idle iowait irq softirq steal - guest time is already inside user
        $values = array_slice($values, 0, 8);
        $idle = $values[3] + ($values[4] ?? 0);
        $total = array_sum($values);

        return ['busy' => $total - $idle, 'total' => $total];
    }

    /**
     * @param  array{busy: int, total: int}  $previous
     * @param  array{busy: int, total: int}  $current
     */
    public static function cpuPercent(array $previous, array $current): ?float
    {
        $total = $current['total'] - $previous['total'];

        if ($total <= 0) {
            return null;
        }

        return round(max(0, min(100, ($current['busy'] - $previous['busy']) / $total * 100)), 1);
    }

    /** Seconds since boot. */
    public static function uptime(string $uptime): ?int
    {
        return preg_match('/^(\d+)/', trim($uptime), $match) ? (int) $match[1] : null;
    }

    /** @return float[]|null the 1, 5 and 15 minute averages */
    public static function load(string $loadavg): ?array
    {
        $parts = preg_split('/\s+/', trim($loadavg));

        return count($parts) >= 3 && is_numeric($parts[0]) ? array_map(floatval(...), array_slice($parts, 0, 3)) : null;
    }

    /** Number of cpus, counted from the per-cpu lines of /proc/stat. */
    public static function cpuCount(string $stat): int
    {
        return max(1, preg_match_all('/^cpu\d+\s/m', $stat));
    }
}
