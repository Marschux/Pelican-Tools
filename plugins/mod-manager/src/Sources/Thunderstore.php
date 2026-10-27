<?php

namespace Marschux\ModManager\Sources;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Thunderstore, read through the same JSON API its website uses. A package is addressed as
 * "Namespace-Name", the way Thunderstore itself writes dependencies.
 */
class Thunderstore
{
    private const SITE = 'https://thunderstore.io';

    private const AGENT = 'Marschux/pelican-mod-manager';

    /** mods per page in the browser: four rows of three */
    private const PER_PAGE = 12;

    /** what thunderstore puts on one of its pages */
    private const SOURCE_PAGE = 20;

    public const ORDERINGS = ['most-downloaded', 'top-rated', 'last-updated', 'newest'];

    /** @param  string[]  $hidden  packages never shown, as "Namespace-Name" */
    public function __construct(private string $community, private array $hidden = []) {}

    /** @return array<string, string> category id => name */
    public function categories(): array
    {
        return array_column($this->categoryList(), 'name', 'id');
    }

    /** Category ids differ per community, the slug ("client-side") does not. */
    public function categoryId(string $slug): ?string
    {
        return array_column($this->categoryList(), 'id', 'slug')[$slug] ?? null;
    }

    /**
     * One page of 20 packages.
     *
     * @return array{items: array<int, array<string, mixed>>, pages: int}
     */
    public function search(string $query = '', ?string $category = null, string $ordering = 'most-downloaded', int $page = 1, bool $showClientSide = true, bool $showDeprecated = false): array
    {
        $query = array_filter([
            'q' => $query,
            'included_categories' => $category,
            // client-only mods do nothing on a server
            'excluded_categories' => !$showClientSide ? $this->categoryId('client-side') : null,
            // thunderstore leaves deprecated packages out unless asked
            'deprecated' => $showDeprecated ? 'true' : null,
            'ordering' => in_array($ordering, self::ORDERINGS, true) ? $ordering : 'most-downloaded',
        ]);

        $isHidden = fn (array $package) => in_array("{$package['namespace']}-{$package['name']}", $this->hidden, true);

        // hidden packages (the loader, the desktop client) are popular, so they sit on
        // thunderstore's first page; where they are there shifts every page after it
        // ponytail: hidden ones further back only make their page one shorter
        $firstPage = $this->listing($query, 1);
        $hiddenAt = array_keys(array_filter($firstPage['results'] ?? [], $isHidden));

        // thunderstore pages hold 20 and ignore a page size; pages of 12 fill the three columns,
        // so one page here is cut out of one or two of theirs
        $start = self::sourceStart($page, $hiddenAt);
        $end = $start + self::PER_PAGE + count($this->hidden);
        $results = [];
        $count = (int) ($firstPage['count'] ?? 0);

        foreach (range(intdiv($start, self::SOURCE_PAGE) + 1, intdiv($end - 1, self::SOURCE_PAGE) + 1) as $sourcePage) {
            $listing = $sourcePage === 1 ? $firstPage : $this->listing($query, $sourcePage);
            $results = [...$results, ...($listing['results'] ?? [])];

            if (($listing['next'] ?? null) === null) {
                break;
            }
        }

        $items = collect(array_slice($results, $start % self::SOURCE_PAGE))
            ->reject($isHidden)
            ->take(self::PER_PAGE)
            ->map(fn (array $package) => $this->item($package))
            ->values()
            ->all();

        return ['items' => $items, 'pages' => max(1, (int) ceil(($count - count($hiddenAt)) / self::PER_PAGE))];
    }

    /**
     * Where page $page starts in thunderstore's list, with the hidden packages at $hiddenAt
     * (positions in that list) skipped before it.
     *
     * @param  int[]  $hiddenAt
     */
    public static function sourceStart(int $page, array $hiddenAt): int
    {
        $start = (max(1, $page) - 1) * self::PER_PAGE;
        sort($hiddenAt);

        foreach ($hiddenAt as $position) {
            if ($position <= $start) {
                $start++;
            }
        }

        return $start;
    }

    /** One of thunderstore's pages, kept a few minutes so paging back and forth does not ask again. */
    private function listing(array $query, int $page): array
    {
        return Cache::remember(
            "mod-manager:thunderstore:$this->community:listing:" . md5(json_encode($query + ['page' => $page])),
            now()->addMinutes(5),
            fn () => $this->http()->get(self::SITE . "/api/cyberstorm/listing/$this->community/", $query + ['page' => $page])->json(),
        );
    }

    /**
     * Everything the detail view shows: the package, its versions and the readme as safe html.
     *
     * @return array<string, mixed>
     */
    public function details(string $id): array
    {
        [$namespace, $name] = self::split($id);

        return Cache::remember("mod-manager:thunderstore:$this->community:$id", now()->addMinutes(10), function () use ($namespace, $name) {
            $package = $this->http()->get(self::SITE . "/api/cyberstorm/listing/$this->community/$namespace/$name/")->json();
            $base = self::SITE . "/api/experimental/package/$namespace/$name/{$package['latest_version_number']}";
            // the rest at once - one after the other they made opening the details slow
            $responses = Http::pool(fn (Pool $pool) => array_filter([
                $pool->as('versions')->timeout(15)->withUserAgent(self::AGENT)->get(self::SITE . "/api/cyberstorm/package/$namespace/$name/versions/"),
                $pool->as('readme')->timeout(15)->withUserAgent(self::AGENT)->get("$base/readme/"),
                ($package['has_changelog'] ?? false) ? $pool->as('changelog')->timeout(15)->withUserAgent(self::AGENT)->get("$base/changelog/") : null,
            ]));

            foreach ($responses as $response) {
                if (!$response instanceof Response) {
                    throw $response;
                }

                $response->throw();
            }

            $versions = $responses['versions']->json();
            $readme = $responses['readme']->json('markdown', '');
            $changelog = isset($responses['changelog']) ? $responses['changelog']->json('markdown', '') : '';

            return $this->item($package) + [
                'version' => $package['latest_version_number'],
                'website' => $package['website_url'] ?: null,
                'size' => (int) ($package['size'] ?? 0),
                'dependants' => (int) ($package['dependant_count'] ?? 0),
                'dependencies' => collect($package['dependencies'] ?? [])
                    ->map(fn (array $dependency) => [
                        'id' => "{$dependency['namespace']}-{$dependency['name']}",
                        'name' => str_replace('_', ' ', $dependency['name']),
                        'version' => $dependency['version_number'],
                        'icon' => $dependency['icon_url'] ?? null,
                        'hidden' => in_array("{$dependency['namespace']}-{$dependency['name']}", $this->hidden, true),
                    ])
                    ->all(),
                'versions' => collect($versions)
                    ->sortByDesc('datetime_created')
                    ->map(fn (array $version) => [
                        'number' => $version['version_number'],
                        'date' => $version['datetime_created'],
                        'downloads' => $version['download_count'],
                    ])
                    ->values()
                    ->all(),
                'readme' => self::markdown($readme),
                'changelog' => filled($changelog) ? self::markdown($changelog) : null,
            ];
        });
    }

    /**
     * The newest version of a package, as much as installing it needs.
     *
     * @return array{id: string, name: string, version: string, icon: ?string, download: string, requires: string[]}
     */
    public function latest(string $id): array
    {
        return $this->release($id);
    }

    /**
     * One version of a package - the newest without $version. Thunderstore has no game
     * versions, $gameVersion is only there to match the other sources.
     *
     * @return array{id: string, name: string, version: string, icon: ?string, download: string, requires: string[]}
     */
    public function release(string $id, ?string $version = null, ?string $gameVersion = null): array
    {
        [$namespace, $name] = self::split($id);

        if ($version !== null && !preg_match('/^[\d.]+$/', $version)) {
            throw new RuntimeException("not a version: $version");
        }

        $latest = $version === null
            ? $this->http()->get(self::SITE . "/api/experimental/package/$namespace/$name/")->json('latest')
            : $this->http()->get(self::SITE . "/api/experimental/package/$namespace/$name/$version/")->json();

        return [
            'id' => $id,
            'name' => str_replace('_', ' ', $name),
            'version' => $latest['version_number'],
            'icon' => $latest['icon'] ?? null,
            'download' => $latest['download_url'],
            // "Namespace-Name-1.2.3" - the version is dropped, the newest is installed anyway
            'requires' => array_values(array_map(fn (string $dependency) => preg_replace('/-[^-]+$/', '', $dependency), $latest['dependencies'] ?? [])),
            // thunderstore knows no optional dependencies
            'optional' => [],
        ];
    }

    /** The newest version number, for the update hints - asked at most every ten minutes. */
    public function latestVersion(string $id, ?string $gameVersion = null): string
    {
        return Cache::remember("mod-manager:thunderstore:$this->community:$id:latest", now()->addMinutes(10), fn () => $this->latest($id)['version']);
    }

    /** Readmes and changelogs are written by anyone: raw html is stripped, unsafe links dropped. */
    public static function markdown(mixed $markdown): string
    {
        return (string) Str::markdown((string) $markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    /** @return array<int, array{id: string, name: string, slug: string}> */
    private function categoryList(): array
    {
        return Cache::remember("mod-manager:thunderstore:$this->community:categories", now()->addDay(), fn () => $this->http()
            ->get(self::SITE . "/api/cyberstorm/community/$this->community/filters/")
            ->json('package_categories', []));
    }

    /** @return array{0: string, 1: string} namespace and name */
    public static function split(string $id): array
    {
        if (!preg_match('/^([\w]+)-([\w.]+)$/', $id, $match)) {
            throw new RuntimeException("not a package id: $id");
        }

        return [$match[1], $match[2]];
    }

    /** @return array<string, mixed> */
    private function item(array $package): array
    {
        return [
            'id' => "{$package['namespace']}-{$package['name']}",
            'name' => str_replace('_', ' ', $package['name']),
            'author' => $package['namespace'],
            'summary' => (string) $package['description'],
            'icon' => $package['icon_url'] ?? null,
            'downloads' => (int) $package['download_count'],
            'rating' => (int) ($package['rating_count'] ?? 0),
            'updated' => $package['last_updated'] ?? $package['version_created'] ?? null,
            'categories' => array_column($package['categories'] ?? [], 'name'),
            // server or client only, as far as the author tagged it
            'side' => match (true) {
                in_array('server-side', $slugs = array_column($package['categories'] ?? [], 'slug'), true) && !in_array('client-side', $slugs, true) => 'server',
                in_array('client-side', $slugs, true) && !in_array('server-side', $slugs, true) => 'client',
                default => null,
            },
            'deprecated' => (bool) ($package['is_deprecated'] ?? false),
            'url' => self::SITE . "/c/$this->community/p/{$package['namespace']}/{$package['name']}/",
        ];
    }

    private function http(): PendingRequest
    {
        return Http::timeout(15)->withUserAgent(self::AGENT)->throw();
    }
}
