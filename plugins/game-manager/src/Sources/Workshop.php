<?php

namespace Marschux\GameManager\Sources;

use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Counter-Strike 2 maps from the Steam Workshop. Nothing is downloaded by the panel: the game
 * fetches a workshop map itself, by its id. A package is "workshop:<id>". Details work without a
 * key; browsing needs a Steam Web API key (the profile's steam_api_key). A collection
 * ("workshop-collection:<id>") is browsed too; adding one adds its Counter-Strike 2 maps.
 */
class Workshop
{
    public const PREFIX = 'workshop:';

    public const COLLECTION = 'workshop-collection:';

    public const APP = 730;

    private const DETAILS = 'https://api.steampowered.com/ISteamRemoteStorage/GetPublishedFileDetails/v1/';

    private const QUERY = 'https://api.steampowered.com/IPublishedFileService/QueryFiles/v1/';

    private const COLLECTION_DETAILS = 'https://api.steampowered.com/ISteamRemoteStorage/GetCollectionDetails/v1/';

    private const PER_PAGE = 12;

    /** the page's sort orders as Steam's query types */
    private const SORTS = ['most-downloaded' => 9, 'top-rated' => 0, 'last-updated' => 21, 'newest' => 1];

    /** the game mode tags maps carry, tag => name */
    private const MODES = ['Classic' => 'Classic', 'Deathmatch' => 'Deathmatch', 'Wingman' => 'Wingman', 'Armsrace' => 'Arms Race', 'Custom' => 'Custom'];

    /** @param  string  $kind  what search() finds: "map" or "collection" */
    public function __construct(private string $key = '', private string $kind = 'map') {}

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

    /** @return array<string, string> */
    public function categories(): array
    {
        return self::MODES;
    }

    /** @return array{items: array<int, array<string, mixed>>, pages: int} */
    public function search(string $query = '', ?string $category = null, string $ordering = 'most-downloaded', int $page = 1, bool $showClientSide = true, bool $showDeprecated = false, ?string $gameVersion = null): array
    {
        if ($this->key === '') {
            throw new RuntimeException(trans('game-manager::mods.workshop.no_key'));
        }

        $params = [
            'key' => $this->key,
            'appid' => self::APP,
            'page' => max(1, $page),
            'numperpage' => self::PER_PAGE,
            // a text search ranks by the text, the sort only applies to browsing
            'query_type' => $query !== '' ? 12 : (self::SORTS[$ordering] ?? self::SORTS['most-downloaded']),
            'search_text' => $query,
            'return_tags' => 1,
            'return_previews' => 1,
            'return_short_description' => 1,
            'return_vote_data' => 1,
            'requiredtags' => array_values(array_filter([
                // collections are rarely tagged; maps always are
                ...($this->kind === 'collection' ? [] : ['Map', 'Cs2']),
                isset(self::MODES[(string) $category]) ? $category : null,
            ])),
        ];

        if ($this->kind === 'collection') {
            $params += ['filetype' => 1, 'return_children' => 1];
        }

        $result = Cache::remember('game-manager:workshop:query:' . md5(json_encode([...$params, 'key' => null])), now()->addMinutes(5), fn () => $this->http()->get(self::QUERY, $params)->json('response'));

        return [
            'items' => array_map(fn (array $file) => $this->item($file), $result['publishedfiledetails'] ?? []),
            'pages' => max(1, min(100, (int) ceil(((int) ($result['total'] ?? 0)) / self::PER_PAGE))),
        ];
    }

    /** What the detail modal shows. One version: the game always fetches the newest itself. */
    public function details(string $id, ?string $gameVersion = null): array
    {
        $collection = str_starts_with($id, self::COLLECTION);
        $file = $this->file(substr($id, strlen($collection ? self::COLLECTION : self::PREFIX)));
        $version = $collection ? trans('game-manager::mods.workshop.collection_size', ['count' => count($this->children($file['publishedfileid']) ?? [])]) : self::mapName((string) $file['title']);

        return [
            'id' => ($collection ? self::COLLECTION : self::PREFIX) . $file['publishedfileid'],
            'name' => (string) $file['title'],
            'author' => '',
            'summary' => '',
            'icon' => $file['preview_url'] ?? null,
            'downloads' => (int) ($file['subscriptions'] ?? 0),
            'rating' => 0,
            'url' => 'https://steamcommunity.com/sharedfiles/filedetails/?id=' . $file['publishedfileid'],
            'version' => $version,
            'website' => null,
            'size' => (int) ($file['file_size'] ?? 0),
            'dependants' => null,
            'dependencies' => [],
            'versions' => [['number' => $version, 'date' => Carbon::createFromTimestamp((int) ($file['time_updated'] ?? 0))->toIso8601String(), 'downloads' => (int) ($file['subscriptions'] ?? 0)]],
            // Steam's description is BBCode; shown as plain text
            'readme' => '<p>' . nl2br(e(trim((string) preg_replace('/\[\/?[^\]]+\]/', '', (string) ($file['description'] ?? ''))))) . '</p>'
                . (isset($file['preview_url']) ? '<p><img src="' . e($file['preview_url']) . '" alt=""></p>' : ''),
            'changelog' => null,
        ];
    }

    /**
     * Title, picture and size of a Counter-Strike 2 workshop item - no key needed.
     *
     * @return array{id: string, name: string, title: string, icon: ?string, size: int, updated: int}
     */
    public function summary(string $id): array
    {
        return self::summarize($this->file($id));
    }

    /**
     * The items of a collection by id, null when $id is no collection (a map). No key needed.
     *
     * @return string[]|null
     */
    public function children(string $id): ?array
    {
        $collection = Cache::remember("game-manager:workshop:collection:$id", now()->addHour(), fn () => $this->http()
            ->asForm()
            ->post(self::COLLECTION_DETAILS, ['collectioncount' => 1, 'publishedfileids[0]' => $id])
            ->json('response.collectiondetails.0'));

        if (!is_array($collection) || ($collection['result'] ?? 0) !== 1) {
            return null;
        }

        // filetype 0 is an item; a collection inside a collection is left out
        return array_values(array_map(
            fn (array $child) => (string) $child['publishedfileid'],
            array_filter($collection['children'] ?? [], fn (array $child) => (int) ($child['filetype'] ?? 0) === 0),
        ));
    }

    /**
     * Of these items the public Counter-Strike 2 maps - tagged Map and Cs2; older CS:GO maps in a
     * collection do not run on Counter-Strike 2.
     *
     * @param  string[]  $ids
     * @return array<int, array{id: string, name: string, title: string, icon: ?string, size: int, updated: int}>
     */
    public function maps(array $ids): array
    {
        $maps = [];

        foreach (array_chunk($ids, 100) as $chunk) {
            $form = ['itemcount' => count($chunk)];

            foreach ($chunk as $i => $id) {
                $form["publishedfileids[$i]"] = $id;
            }

            foreach ($this->http()->asForm()->post(self::DETAILS, $form)->json('response.publishedfiledetails') ?? [] as $file) {
                $tags = array_map('strtolower', array_column($file['tags'] ?? [], 'tag'));

                if (($file['result'] ?? 0) === 1 && (int) ($file['consumer_app_id'] ?? 0) === self::APP && in_array('map', $tags, true) && in_array('cs2', $tags, true)) {
                    $maps[] = self::summarize($file);
                }
            }
        }

        return $maps;
    }

    /** @return array{id: string, name: string, title: string, icon: ?string, size: int, updated: int} */
    private static function summarize(array $file): array
    {
        return [
            'id' => (string) $file['publishedfileid'],
            'name' => self::mapName((string) $file['title']),
            'title' => (string) $file['title'],
            'icon' => $file['preview_url'] ?? null,
            'size' => (int) ($file['file_size'] ?? 0),
            'updated' => (int) ($file['time_updated'] ?? 0),
        ];
    }

    private function file(string $id): array
    {
        $file = Cache::remember("game-manager:workshop:$id", now()->addHour(), fn () => $this->http()
            ->asForm()
            ->post(self::DETAILS, ['itemcount' => 1, 'publishedfileids[0]' => $id])
            ->json('response.publishedfiledetails.0'));

        if (!is_array($file) || ($file['result'] ?? 0) !== 1 || (int) ($file['consumer_app_id'] ?? 0) !== self::APP) {
            throw new RuntimeException(trans('game-manager::mods.workshop.not_found', ['id' => $id]));
        }

        return $file;
    }

    private function item(array $file): array
    {
        $collection = $this->kind === 'collection';

        return [
            'id' => ($collection ? self::COLLECTION : self::PREFIX) . $file['publishedfileid'],
            'name' => (string) $file['title'],
            'author' => '',
            'summary' => ($collection ? trans('game-manager::mods.workshop.collection_size', ['count' => count($file['children'] ?? [])]) . ' · ' : '') . (string) ($file['short_description'] ?? ''),
            'icon' => $file['preview_url'] ?? null,
            'downloads' => (int) ($file['subscriptions'] ?? 0),
            'rating' => (int) ($file['vote_data']['votes_up'] ?? 0),
            'updated' => isset($file['time_updated']) ? Carbon::createFromTimestamp((int) $file['time_updated'])->toIso8601String() : null,
            'categories' => collect($file['tags'] ?? [])->pluck('tag')->filter(fn ($tag) => isset(self::MODES[$tag]))->map(fn ($tag) => self::MODES[$tag])->values()->all(),
            'side' => null,
            'deprecated' => false,
            'url' => 'https://steamcommunity.com/sharedfiles/filedetails/?id=' . $file['publishedfileid'],
        ];
    }

    private function http(): PendingRequest
    {
        return Http::timeout(15)->withUserAgent('Marschux/pelican-game-manager')->throw();
    }
}
