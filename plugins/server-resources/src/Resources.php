<?php

namespace Marschux\ServerResources;

use Carbon\CarbonInterface;

/**
 * Builds the bar and fact arrays the shared card partial renders - see
 * resources/views/partials/bars.blade.php.
 */
class Resources
{
    public const ICONS = [
        'cpu' => 'tabler-cpu',
        'memory' => 'tabler-device-desktop-analytics',
        'disk' => 'tabler-device-sd-card',
    ];

    /**
     * Sort key for a server row: external id first, numerically, so "9" comes before "11".
     * Non-numeric ids follow the numeric ones, servers without an id come last.
     *
     * @return array<int, mixed>
     */
    public static function sortKey(?string $externalId, string $name): array
    {
        $numeric = is_numeric($externalId);

        return [
            $externalId === null || $externalId === '' ? 1 : 0,
            $numeric ? 0 : 1,
            $numeric ? (float) $externalId : 0.0,
            mb_strtolower((string) $externalId),
            mb_strtolower($name),
        ];
    }

    /** @return array{icon: string, label: string, text: string, percent: float} */
    public static function bar(string $key, float|int $used, float|int $total, string $text): array
    {
        return [
            'icon' => self::ICONS[$key],
            'label' => trans("server-resources::resources.$key"),
            'text' => $text,
            'percent' => $total > 0 ? round(min(100, $used / $total * 100), 1) : 0,
        ];
    }

    /** @return array{icon: string, label: string, text: string, percent: float} */
    public static function bytesBar(string $key, int $used, int $total): array
    {
        return self::bar($key, $used, $total, $total > 0 ? convert_bytes_to_readable($used) . ' / ' . convert_bytes_to_readable($total) : '-');
    }

    /**
     * The newer version to update to, or null. Asks the panel's own version service (GitHub,
     * cached an hour); null on any failure, the card must not break over it.
     */
    public static function update(callable $latest): ?string
    {
        try {
            return app()->call($latest);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{icon: string, text: string} */
    public static function fact(string $icon, string $text): array
    {
        return ['icon' => $icon, 'text' => $text];
    }

    /** @return array{icon: string, text: string} */
    public static function uptimeFact(int $seconds): array
    {
        return self::fact('tabler-clock', trans('server-resources::resources.host.uptime') . ' ' . now()->subSeconds($seconds)->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, parts: 2));
    }

    /**
     * Wings has no uptime of its own, but on every start it calls the panel to reset its servers'
     * states. That moment is kept per node (a file, not the cache: optimize:clear empties that).
     */
    public static function recordNodeStart(int $nodeId): void
    {
        @mkdir(dirname(self::nodeStartFile($nodeId)), 0775, true);
        @file_put_contents(self::nodeStartFile($nodeId), (string) time());
    }

    /** Seconds since the node's Wings last started, null until it has started once with the plugin installed. */
    public static function nodeUptime(int $nodeId): ?int
    {
        $started = (int) @file_get_contents(self::nodeStartFile($nodeId));

        return $started > 0 ? max(0, time() - $started) : null;
    }

    private static function nodeStartFile(int $nodeId): string
    {
        return storage_path("app/server-resources/node-$nodeId-started");
    }
}
