<?php

namespace Marschux\ServerResources;

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

    /** @return array{icon: string, text: string} */
    public static function fact(string $icon, string $text): array
    {
        return ['icon' => $icon, 'text' => $text];
    }
}
