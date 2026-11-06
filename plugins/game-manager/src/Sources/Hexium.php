<?php

namespace Marschux\GameManager\Sources;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Hexium, a second site of Thunderstore packages (Valheim). It speaks Thunderstore's older API
 * but has no search: its listing holds every package of the community at once, so browsing is
 * done on a cached copy of it. A package is "hexium:Namespace-Name" - many are on Thunderstore
 * too under the same name, and both unpack into the same "Namespace-Name" folder.
 */
class Hexium
{
    public const PREFIX = 'hexium:';

    private const API = 'https://hexium.gg/api/experimental';

    private const AGENT = 'Marschux/pelican-game-manager';

    /** mods per page in the browser: four rows of three */
    private const PER_PAGE = 12;

    /** @var array<string, array<string, array<string, mixed>>> the listing, once read in this request */
    private static array $listings = [];

    /** @param  string[]  $hidden  packages never shown, as "Namespace-Name" */
    public function __construct(private string $community, private array $hidden = []) {}

    /** "hexium:Namespace-Name" without its prefix - the package's folder and its Thunderstore name. */
    public static function bare(string $id): string
    {
        return str_starts_with($id, self::PREFIX) ? substr($id, strlen(self::PREFIX)) : $id;
    }

    /** @return array<string, string> category => name, Hexium's category ids are their names */
    public function categories(): array
    {
        return Cache::remember("game-manager:hexium:$this->community:categories", now()->addDay(), fn () => collect($this->http()
            ->get(self::API . "/community/$this->community/category/")
            ->json('results', []))
            ->mapWithKeys(fn (array $category) => [$category['slug'] => $category['name']])
            ->all());
    }

    /** @return array{items: array<int, array<string, mixed>>, pages: int} */
    public function search(string $query = '', ?string $category = null, string $ordering = 'most-downloaded', int $page = 1, bool $showClientSide = true, bool $showDeprecated = false): array
    {
        $found = self::filter($this->listing(), $this->hidden, $query, $category, $ordering, $showClientSide, $showDeprecated);

        return [
            'items' => array_map(fn (array $package) => $this->item($package), array_slice($found, (max(1, $page) - 1) * self::PER_PAGE, self::PER_PAGE)),
            'pages' => max(1, (int) ceil(count($found) / self::PER_PAGE)),
        ];
    }

    /**
     * What the browse tab shows of the listing, in order.
     *
     * @param  array<string, array<string, mixed>>  $listing  from listing()
     * @param  string[]  $hidden
     * @return array<int, array<string, mixed>>
     */
    public static function filter(array $listing, array $hidden, string $query, ?string $category, string $ordering, bool $showClientSide, bool $showDeprecated): array
    {
        $found = array_filter($listing, fn (array $package) => !in_array($package['id'], $hidden, true)
            && ($showDeprecated || !$package['deprecated'])
            // client-only mods do nothing on a server
            && ($showClientSide || !in_array('Client-only', $package['categories'], true))
            && ($category === null || in_array($category, $package['categories'], true))
            && ($query === '' || str_contains(mb_strtolower("{$package['name']} {$package['author']} {$package['summary']}"), mb_strtolower($query))));

        $key = match ($ordering) {
            'top-rated' => 'rating',
            'last-updated' => 'updated',
            'newest' => 'created',
            default => 'downloads',
        };
        usort($found, fn (array $a, array $b) => $b[$key] <=> $a[$key]);

        return $found;
    }

    /** @return array<string, mixed> everything the detail view shows */
    public function details(string $id): array
    {
        $package = $this->package($id);
        [$namespace, $name] = Thunderstore::split($package['id']);

        return Cache::remember("game-manager:hexium:$this->community:{$package['id']}:{$package['version']}", now()->addHour(), function () use ($package, $namespace, $name) {
            $base = self::API . "/package/$namespace/$name/{$package['version']}";
            $responses = Http::pool(fn (Pool $pool) => [
                $pool->as('readme')->timeout(15)->withUserAgent(self::AGENT)->get("$base/readme/"),
                $pool->as('changelog')->timeout(15)->withUserAgent(self::AGENT)->get("$base/changelog/"),
            ]);

            if (!$responses['readme'] instanceof Response) {
                throw $responses['readme'];
            }

            $readme = $responses['readme']->throw()->json('markdown', '');
            // a changelog is optional, one that does not come is left out
            $changelog = $responses['changelog'] instanceof Response && $responses['changelog']->successful() ? $responses['changelog']->json('markdown', '') : '';
            $listing = $this->listing();

            return $this->item($package) + [
                'version' => $package['version'],
                'website' => $package['website'],
                'size' => $package['size'],
                'dependants' => count(array_filter($listing, fn (array $other) => in_array($package['id'], $other['requires'], true))),
                'dependencies' => array_map(fn (string $dependency) => [
                    'id' => self::PREFIX . $dependency,
                    'name' => str_replace('_', ' ', $listing[$dependency]['name'] ?? substr($dependency, strpos($dependency, '-') + 1)),
                    'version' => $listing[$dependency]['version'] ?? '',
                    'icon' => $listing[$dependency]['icon'] ?? null,
                    'hidden' => in_array($dependency, $this->hidden, true),
                ], $package['requires']),
                'versions' => $package['versions'],
                'readme' => Thunderstore::markdown($readme),
                'changelog' => filled($changelog) ? Thunderstore::markdown($changelog) : null,
            ];
        });
    }

    /** @return array{id: string, name: string, version: string, icon: ?string, download: string, requires: string[]} */
    public function latest(string $id): array
    {
        return $this->release($id);
    }

    /**
     * One version of a package - the newest without $version. Its dependencies come from Hexium
     * too. Hexium has no game versions, $gameVersion is only there to match the other sources.
     *
     * @return array{id: string, name: string, version: string, icon: ?string, download: string, requires: string[]}
     */
    public function release(string $id, ?string $version = null, ?string $gameVersion = null): array
    {
        [$namespace, $name] = Thunderstore::split(self::bare($id));

        if ($version !== null && !preg_match('/^[\d.]+$/', $version)) {
            throw new RuntimeException("not a version: $version");
        }

        $release = $version === null
            ? $this->http()->get(self::API . "/package/$namespace/$name/")->json('latest')
            : $this->http()->get(self::API . "/package/$namespace/$name/$version/")->json();

        return [
            'id' => self::PREFIX . "$namespace-$name",
            'name' => str_replace('_', ' ', $name),
            'version' => $release['version_number'],
            'icon' => $release['icon'] ?? null,
            'download' => $release['download_url'],
            'requires' => array_map(fn (string $dependency) => self::PREFIX . $dependency, self::withoutVersions($release['dependencies'] ?? [])),
            'optional' => [],
        ];
    }

    /** The newest version number, straight from the cached listing. */
    public function latestVersion(string $id, ?string $gameVersion = null): string
    {
        return $this->package($id)['version'];
    }

    /** @return array<string, mixed> one package of the listing */
    private function package(string $id): array
    {
        return $this->listing()[self::bare($id)] ?? throw new RuntimeException("not on Hexium: $id");
    }

    /**
     * Every package of the community, by "Namespace-Name", newest version first. Hexium sends
     * them all in one gzipped file of about 16 MB; what is kept of it is a tenth of that.
     *
     * @return array<string, array<string, mixed>>
     */
    private function listing(): array
    {
        return self::$listings[$this->community] ??= Cache::remember("game-manager:hexium:$this->community:listing", now()->addHour(), function () {
            $index = json_decode((string) gzdecode($this->http()->get("https://$this->community.hexium.gg/api/v1/package-listing-index/")->body()), true);
            $listing = [];

            // ponytail: the whole listing is decoded at once (~150 MB peak), stream it if it grows much past 2000 packages
            foreach ((array) $index as $chunk) {
                foreach ((array) json_decode((string) gzdecode($this->http()->timeout(60)->get($chunk)->body()), true) as $package) {
                    $latest = $package['versions'][0] ?? null;

                    if ($latest === null) {
                        continue;
                    }

                    $listing[$package['full_name']] = [
                        'id' => $package['full_name'],
                        'name' => $package['name'],
                        'author' => $package['owner'],
                        'summary' => (string) $latest['description'],
                        'icon' => $latest['icon'] ?? null,
                        'downloads' => array_sum(array_column($package['versions'], 'downloads')),
                        'rating' => (int) $package['rating_score'],
                        'updated' => $package['date_updated'],
                        'created' => $package['date_created'],
                        'categories' => $package['categories'] ?? [],
                        'deprecated' => (bool) $package['is_deprecated'],
                        'version' => $latest['version_number'],
                        'website' => $latest['website_url'] ?: null,
                        'size' => (int) ($latest['file_size'] ?? 0),
                        'requires' => self::withoutVersions($latest['dependencies'] ?? []),
                        'versions' => array_map(fn (array $version) => [
                            'number' => $version['version_number'],
                            'date' => $version['date_created'],
                            'downloads' => $version['downloads'],
                        ], $package['versions']),
                    ];
                }
            }

            return $listing;
        });
    }

    /**
     * "Namespace-Name-1.2.3" without the version - the newest is installed anyway.
     *
     * @param  string[]  $dependencies
     * @return string[]
     */
    private static function withoutVersions(array $dependencies): array
    {
        return array_values(array_map(fn (string $dependency) => preg_replace('/-[^-]+$/', '', $dependency), $dependencies));
    }

    /** @return array<string, mixed> */
    private function item(array $package): array
    {
        [$namespace, $name] = Thunderstore::split($package['id']);

        return [
            'id' => self::PREFIX . $package['id'],
            'name' => str_replace('_', ' ', $package['name']),
            'author' => $package['author'],
            'summary' => $package['summary'],
            'icon' => $package['icon'],
            'downloads' => $package['downloads'],
            'rating' => $package['rating'],
            'updated' => $package['updated'],
            'categories' => $package['categories'],
            'side' => match (true) {
                in_array('Server-only', $package['categories'], true) => 'server',
                in_array('Client-only', $package['categories'], true) => 'client',
                default => null,
            },
            'deprecated' => $package['deprecated'],
            'url' => "https://$this->community.hexium.gg/mods/$namespace/$name",
        ];
    }

    private function http(): PendingRequest
    {
        return Http::timeout(15)->withUserAgent(self::AGENT)->throw();
    }
}
