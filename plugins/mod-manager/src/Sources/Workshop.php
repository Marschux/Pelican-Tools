<?php

namespace Marschux\ModManager\Sources;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Counter-Strike 2 maps from the Steam Workshop. Nothing is downloaded by the panel: the game
 * fetches a workshop map itself, by its id. A package is "workshop:<id>".
 */
class Workshop
{
    public const PREFIX = 'workshop:';

    public const APP = 730;

    private const DETAILS = 'https://api.steampowered.com/ISteamRemoteStorage/GetPublishedFileDetails/v1/';

    /** The id from a workshop link (…?id=123) or the id itself, null when it is neither. */
    public static function id(string $input): ?string
    {
        $input = trim($input);

        if (preg_match('/^\d{6,12}$/', $input)) {
            return $input;
        }

        return preg_match('#steamcommunity\.com/(?:sharedfiles|workshop)/filedetails/?\?(?:[^\#]*&)?id=(\d{6,12})#i', $input, $match) ? $match[1] : null;
    }

    /** What maplist.txt and the console call a map: its title, lower case, nothing but a-z, 0-9 and _. */
    public static function mapName(string $title): string
    {
        return trim((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($title)), '_') ?: 'workshop_map';
    }

    /**
     * Title, picture and size of a Counter-Strike 2 workshop item - no key needed.
     *
     * @return array{id: string, name: string, title: string, icon: ?string, size: int, updated: int}
     */
    public function details(string $id): array
    {
        $item = Cache::remember("mod-manager:workshop:$id", now()->addHour(), fn () => $this->http()
            ->asForm()
            ->post(self::DETAILS, ['itemcount' => 1, 'publishedfileids[0]' => $id])
            ->json('response.publishedfiledetails.0'));

        if (!is_array($item) || ($item['result'] ?? 0) !== 1 || (int) ($item['consumer_app_id'] ?? 0) !== self::APP) {
            throw new RuntimeException(trans('mod-manager::mods.workshop.not_found', ['id' => $id]));
        }

        return [
            'id' => $id,
            'name' => self::mapName((string) $item['title']),
            'title' => (string) $item['title'],
            'icon' => $item['preview_url'] ?? null,
            'size' => (int) ($item['file_size'] ?? 0),
            'updated' => (int) ($item['time_updated'] ?? 0),
        ];
    }

    private function http(): PendingRequest
    {
        return Http::timeout(15)->withUserAgent('Marschux/pelican-mod-manager')->throw();
    }
}
