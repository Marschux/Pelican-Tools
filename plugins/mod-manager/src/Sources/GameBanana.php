<?php

namespace Marschux\ModManager\Sources;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * GameBanana, through its v11 API: one category of one game, e.g. the maps of Counter-Strike 1.6.
 * A submission is "gamebanana:<id>"; it can have several files, each one is a "version".
 */
class GameBanana
{
    private const API = 'https://gamebanana.com/apiv11';

    private const AGENT = 'Marschux/pelican-mod-manager';

    public const PREFIX = 'gamebanana:';

    private const PER_PAGE = 12;

    /** the browser's orderings => gamebanana's; it lists no download counts, views come closest */
    private const SORTS = ['most-downloaded' => 'Generic_MostViewed', 'top-rated' => 'Generic_MostLiked', 'last-updated' => 'Generic_LatestUpdated', 'newest' => 'Generic_Newest'];

    /** a typed search goes through the site search, which cannot be limited to a category: this many pages of 15 are filtered */
    private const SEARCH_PAGES = 7;

    public function __construct(private int $game, private int $category) {}

    /** @return array<string, string> subcategory id => name */
    public function categories(): array
    {
        return Cache::remember("mod-manager:gamebanana:$this->category:categories", now()->addDay(), fn () => collect($this->http()->get(self::API . '/Mod/Categories', [
            '_idCategoryRow' => $this->category,
            '_sSort' => 'a_to_z',
            '_bShowEmpty' => 'false',
        ])->json())->mapWithKeys(fn (array $category) => [(string) $category['_idRow'] => (string) $category['_sName']])->all());
    }

    /**
     * One page of 12. Without a query straight from the category's index; with one from the site
     * search, filtered to the category here.
     *
     * @return array{items: array<int, array<string, mixed>>, pages: int}
     */
    public function search(string $query = '', ?string $category = null, string $ordering = 'most-downloaded', int $page = 1, bool $showClientSide = true, bool $showDeprecated = false, ?string $gameVersion = null): array
    {
        $page = max(1, $page);

        if ($query === '') {
            $params = [
                '_nPage' => $page,
                '_nPerpage' => self::PER_PAGE,
                '_aFilters[Generic_Category]' => $category ?: $this->category,
                '_sSort' => self::SORTS[$ordering] ?? self::SORTS['most-downloaded'],
            ];
            $result = Cache::remember('mod-manager:gamebanana:index:' . md5(json_encode($params)), now()->addMinutes(5), fn () => $this->http()->get(self::API . '/Mod/Index', $params)->json());

            return [
                'items' => array_map(fn (array $record) => $this->item($record), $result['_aRecords'] ?? []),
                'pages' => max(1, (int) ceil(((int) ($result['_aMetadata']['_nRecordCount'] ?? 0)) / self::PER_PAGE)),
            ];
        }

        $found = array_values(array_filter(
            $this->searchRecords($query),
            fn (array $record) => self::inCategory($record['_aRootCategory'] ?? [], $this->category)
                && (!$category || self::inCategory($record['_aSubCategory'] ?? [], (int) $category)),
        ));

        return [
            'items' => array_map(fn (array $record) => $this->item($record), array_slice($found, ($page - 1) * self::PER_PAGE, self::PER_PAGE)),
            'pages' => max(1, (int) ceil(count($found) / self::PER_PAGE)),
        ];
    }

    /**
     * Everything the detail view shows; the files of a submission are its versions.
     *
     * @return array<string, mixed>
     */
    public function details(string $id, ?string $gameVersion = null): array
    {
        $profile = $this->profile(self::split($id));
        $files = self::files($profile);
        $images = collect($profile['_aPreviewMedia']['_aImages'] ?? [])
            ->take(6)
            ->map(fn (array $image) => '<img src="' . e($image['_sBaseUrl'] . '/' . ($image['_sFile530'] ?? $image['_sFile'])) . '" alt="">')
            ->implode('');

        return [
            'id' => self::PREFIX . $profile['_idRow'],
            'name' => (string) $profile['_sName'],
            'author' => (string) ($profile['_aSubmitter']['_sName'] ?? ''),
            'summary' => '',
            'icon' => self::icon($profile),
            'downloads' => (int) ($profile['_nDownloadCount'] ?? 0),
            'rating' => (int) ($profile['_nLikeCount'] ?? 0),
            'url' => (string) $profile['_sProfileUrl'],
            'version' => $files[0]['number'] ?? '',
            'website' => null,
            'size' => $files[0]['size'] ?? 0,
            'dependants' => null,
            'dependencies' => [],
            'versions' => collect($files)->unique('number')->map(fn (array $file) => ['number' => $file['number'], 'date' => $file['date'], 'downloads' => $file['downloads']])->values()->all(),
            // the description is html written by whoever uploaded it
            'readme' => self::sanitize((string) ($profile['_sText'] ?? '')) . ($images !== '' ? "<p>$images</p>" : ''),
            'changelog' => null,
        ];
    }

    /**
     * One file of a submission, as much as installing it needs: $version (the day it was added,
     * or its name - the version before 0.31), else the newest.
     *
     * @return array{id: string, name: string, version: string, icon: ?string, download: string, file: string, size: int, requires: string[], optional: array<string, string>}
     */
    public function release(string $id, ?string $version = null, ?string $gameVersion = null): array
    {
        $profile = $this->profile(self::split($id));
        $files = self::files($profile);
        $file = $version === null ? ($files[0] ?? null) : collect($files)->first(fn (array $file) => $file['number'] === $version || pathinfo($file['file'], PATHINFO_FILENAME) === $version);

        if ($file === null) {
            throw new RuntimeException(trans('mod-manager::mods.messages.no_version', ['name' => $profile['_sName'], 'version' => $version ?? '-']));
        }

        return [
            'id' => self::PREFIX . $profile['_idRow'],
            'name' => (string) $profile['_sName'],
            'version' => $file['number'],
            'icon' => self::icon($profile),
            'download' => $file['download'],
            'file' => $file['file'],
            'size' => $file['size'],
            'requires' => [],
            'optional' => [],
        ];
    }

    public function latestVersion(string $id, ?string $gameVersion = null): string
    {
        return $this->release($id)['version'];
    }

    /** @return string the submission's number in "gamebanana:<id>" */
    public static function split(string $id): string
    {
        if (!preg_match('/^gamebanana:(\d+)$/', $id, $match)) {
            throw new RuntimeException("not a GameBanana submission: $id");
        }

        return $match[1];
    }

    /**
     * A submission's files, newest first - of files added together (a map and its spawns) the
     * biggest first, that is the map. GameBanana has no version numbers: the day a file was added
     * is its "version" (files added together share it).
     *
     * @param  array<string, mixed>  $profile
     * @return array<int, array{number: string, file: string, download: string, size: int, date: string, downloads: int}>
     */
    public static function files(array $profile): array
    {
        return collect($profile['_aFiles'] ?? [])
            ->sortBy([['_tsDateAdded', 'desc'], ['_nFilesize', 'desc']])
            ->map(fn (array $file) => [
                'number' => Carbon::createFromTimestamp((int) ($file['_tsDateAdded'] ?? 0))->format('Y-m-d'),
                'file' => (string) $file['_sFile'],
                'download' => (string) $file['_sDownloadUrl'],
                'size' => (int) ($file['_nFilesize'] ?? 0),
                'date' => Carbon::createFromTimestamp((int) ($file['_tsDateAdded'] ?? 0))->toIso8601String(),
                'downloads' => (int) ($file['_nDownloadCount'] ?? 0),
            ])
            ->values()
            ->all();
    }

    /** Only the parts of html that show text and images; scripts, styles and handlers go. */
    public static function sanitize(string $html): string
    {
        return (new HtmlSanitizer((new HtmlSanitizerConfig())
            ->allowSafeElements()
            ->allowLinkSchemes(['https', 'http'])
            ->allowMediaSchemes(['https'])
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)))->sanitize($html);
    }

    /** @param  array<string, mixed>  $category  _aRootCategory or _aSubCategory of a record */
    private static function inCategory(array $category, int $id): bool
    {
        return str_ends_with((string) ($category['_sProfileUrl'] ?? ''), "/cats/$id");
    }

    /** @return array<int, array<string, mixed>> the first pages of the site search, at once */
    private function searchRecords(string $query): array
    {
        return Cache::remember("mod-manager:gamebanana:$this->game:search:" . md5($query), now()->addMinutes(5), function () use ($query) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (int $page) => $pool->timeout(15)->withUserAgent(self::AGENT)->get(self::API . '/Util/Search/Results', [
                    '_sModelName' => 'Mod',
                    '_sSearchString' => $query,
                    '_idGameRow' => $this->game,
                    '_nPage' => $page,
                ]),
                range(1, self::SEARCH_PAGES),
            ));

            $records = [];

            foreach ($responses as $response) {
                // a page past the end answers with an error or nothing, that is fine
                if ($response instanceof Response && $response->successful()) {
                    $records = [...$records, ...($response->json('_aRecords') ?? [])];
                }
            }

            return collect($records)->unique('_idRow')->values()->all();
        });
    }

    /** @return array<string, mixed> */
    private function profile(string $id): array
    {
        return Cache::remember("mod-manager:gamebanana:profile:$id", now()->addHour(), fn () => $this->http()->get(self::API . "/Mod/$id/ProfilePage")->json());
    }

    /** @param  array<string, mixed>  $record */
    private static function icon(array $record): ?string
    {
        $image = $record['_aPreviewMedia']['_aImages'][0] ?? null;

        return $image ? $image['_sBaseUrl'] . '/' . ($image['_sFile220'] ?? $image['_sFile100'] ?? $image['_sFile']) : null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function item(array $record): array
    {
        // "Development State: Final/Stable" is on almost every map and says nothing
        $tags = collect($record['_aTags'] ?? [])->filter(fn ($tag) => is_string($tag) && !str_starts_with($tag, 'Development State'))->take(4)->implode(' · ');

        return [
            'id' => self::PREFIX . $record['_idRow'],
            'name' => (string) $record['_sName'],
            'author' => (string) ($record['_aSubmitter']['_sName'] ?? ''),
            'summary' => $tags,
            'icon' => self::icon($record),
            'downloads' => 0,
            // gamebanana's lists have no download counts
            'views' => (int) ($record['_nViewCount'] ?? 0),
            'rating' => (int) ($record['_nLikeCount'] ?? 0),
            'updated' => isset($record['_tsDateModified']) ? Carbon::createFromTimestamp((int) $record['_tsDateModified'])->toIso8601String() : null,
            'categories' => array_values(array_filter([$record['_aSubCategory']['_sName'] ?? null])),
            'side' => null,
            'deprecated' => (bool) ($record['_bIsObsolete'] ?? false),
            'url' => (string) $record['_sProfileUrl'],
        ];
    }

    private function http(): PendingRequest
    {
        return Http::timeout(15)->withUserAgent(self::AGENT)->throw();
    }
}
