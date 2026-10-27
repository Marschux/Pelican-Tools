<?php

namespace Marschux\ModManager\Sources;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Modrinth, through its public v2 API. A project is addressed as "modrinth:<project id>" - the
 * prefix keeps it apart from packages of other sources on the same server.
 */
class Modrinth
{
    private const API = 'https://api.modrinth.com/v2';

    private const SITE = 'https://modrinth.com';

    private const AGENT = 'Marschux/pelican-mod-manager';

    public const PREFIX = 'modrinth:';

    /** mods per page in the browser: four rows of three */
    private const PER_PAGE = 12;

    /** the browser's orderings => modrinth's index */
    private const INDEXES = ['most-downloaded' => 'downloads', 'top-rated' => 'follows', 'last-updated' => 'updated', 'newest' => 'newest'];

    /** @param  string[]  $loaders  e.g. paper, spigot, bukkit - a project needs one of them */
    public function __construct(private array $loaders) {}

    /** @return array<string, string> category slug => name */
    public function categories(): array
    {
        return Cache::remember('mod-manager:modrinth:categories', now()->addDay(), fn () => collect($this->http()->get(self::API . '/tag/category')->json())
            // plugins share the categories of mods, the loaders come as their own tags
            ->filter(fn (array $category) => $category['project_type'] === 'mod' && $category['header'] === 'categories')
            ->mapWithKeys(fn (array $category) => [$category['name'] => ucwords(str_replace('-', ' ', $category['name']))])
            ->all());
    }

    /**
     * One page of 12 projects, only those for $gameVersion when one is given. The two flags
     * are Thunderstore's and mean nothing here.
     *
     * @return array{items: array<int, array<string, mixed>>, pages: int}
     */
    public function search(string $query = '', ?string $category = null, string $ordering = 'most-downloaded', int $page = 1, bool $showClientSide = true, bool $showDeprecated = false, ?string $gameVersion = null): array
    {
        $facets = array_values(array_filter([
            // one of the loaders (OR), and the category (AND)
            array_map(fn (string $loader) => "categories:$loader", $this->loaders),
            $category ? ["categories:$category"] : null,
            $gameVersion ? ["versions:$gameVersion"] : null,
        ]));

        $params = [
            'query' => $query,
            'facets' => json_encode($facets),
            // a typed search is best sorted by how well it matches
            'index' => $query !== '' ? 'relevance' : (self::INDEXES[$ordering] ?? 'downloads'),
            'offset' => (max(1, $page) - 1) * self::PER_PAGE,
            'limit' => self::PER_PAGE,
        ];

        $result = Cache::remember('mod-manager:modrinth:search:' . md5(json_encode($params)), now()->addMinutes(5), fn () => $this->http()->get(self::API . '/search', $params)->json());
        $categories = $this->categories();

        return [
            'items' => array_map(fn (array $hit) => $this->item($hit, $categories), $result['hits'] ?? []),
            'pages' => max(1, (int) ceil(((int) ($result['total_hits'] ?? 0)) / self::PER_PAGE)),
        ];
    }

    /**
     * Everything the detail view shows; versions only for $gameVersion when one is given.
     * Modrinth keeps a changelog per version, the newest few
     * are put together into one.
     *
     * @return array<string, mixed>
     */
    public function details(string $id, ?string $gameVersion = null): array
    {
        $project = self::split($id);

        return Cache::remember("mod-manager:modrinth:details:$project:" . implode(',', $this->loaders) . ":$gameVersion", now()->addMinutes(10), function () use ($id, $project, $gameVersion) {
            // at once - one after the other they make opening the details slow
            $responses = Http::pool(fn (Pool $pool) => [
                $pool->as('project')->timeout(15)->withUserAgent(self::AGENT)->get(self::API . "/project/$project"),
                $pool->as('versions')->timeout(15)->withUserAgent(self::AGENT)->get(self::API . "/project/$project/version", array_filter(['loaders' => json_encode($this->loaders), 'game_versions' => $gameVersion ? json_encode([$gameVersion]) : null])),
                $pool->as('members')->timeout(15)->withUserAgent(self::AGENT)->get(self::API . "/project/$project/members"),
            ]);

            foreach ($responses as $response) {
                if (!$response instanceof Response) {
                    throw $response;
                }

                $response->throw();
            }

            $info = $responses['project']->json();
            $versions = $responses['versions']->json();
            $latest = $versions[0] ?? [];
            $members = collect($responses['members']->json());
            $dependencies = collect($latest['dependencies'] ?? [])
                // embedded ones are inside the jar already, incompatible ones are not wanted
                ->filter(fn (array $dependency) => in_array($dependency['dependency_type'], ['required', 'optional'], true) && filled($dependency['project_id']))
                ->keyBy('project_id');
            $projects = $dependencies->isEmpty() ? [] : collect($this->http()->get(self::API . '/projects', ['ids' => json_encode($dependencies->keys()->all())])->json())->keyBy('id')->all();

            $changelog = collect($versions)
                ->take(10)
                ->filter(fn (array $version) => filled($version['changelog'] ?? null))
                ->map(fn (array $version) => "## {$version['version_number']}\n\n{$version['changelog']}")
                ->implode("\n\n");

            return [
                'id' => $id,
                'name' => (string) $info['title'],
                'author' => (string) (($members->firstWhere('role', 'Owner') ?? $members->first())['user']['username'] ?? ''),
                'summary' => (string) ($info['description'] ?? ''),
                'icon' => ($info['icon_url'] ?? null) ?: null,
                'downloads' => (int) ($info['downloads'] ?? 0),
                'rating' => (int) ($info['followers'] ?? 0),
                'url' => self::SITE . '/plugin/' . ($info['slug'] ?? $project),
                'version' => (string) ($latest['version_number'] ?? ''),
                'website' => ($info['source_url'] ?? null) ?: (($info['wiki_url'] ?? null) ?: null),
                'size' => (int) (collect($latest['files'] ?? [])->firstWhere('primary', true)['size'] ?? ($latest['files'][0]['size'] ?? 0)),
                // modrinth does not count who depends on a project
                'dependants' => null,
                'dependencies' => $dependencies->map(fn (array $dependency, string $projectId) => [
                    'id' => self::PREFIX . $projectId,
                    'name' => (string) ($projects[$projectId]['title'] ?? $projectId),
                    'version' => '',
                    'icon' => ($projects[$projectId]['icon_url'] ?? null) ?: null,
                    'hidden' => false,
                    'optional' => $dependency['dependency_type'] === 'optional',
                ])->values()->all(),
                'versions' => array_map(fn (array $version) => [
                    'number' => (string) $version['version_number'],
                    'date' => $version['date_published'],
                    'downloads' => (int) $version['downloads'],
                ], $versions),
                'readme' => Thunderstore::markdown($info['body'] ?? ''),
                'changelog' => filled($changelog) ? Thunderstore::markdown($changelog) : null,
            ];
        });
    }

    /** @return string the project id or slug in "modrinth:<id>" */
    public static function split(string $id): string
    {
        if (!preg_match('/^modrinth:([\w-]+)$/', $id, $match)) {
            throw new RuntimeException("not a Modrinth project: $id");
        }

        return $match[1];
    }

    /**
     * @param  array<string, mixed>  $hit
     * @param  array<string, string>  $categories
     * @return array<string, mixed>
     */
    private function item(array $hit, array $categories): array
    {
        return [
            'id' => self::PREFIX . $hit['project_id'],
            'name' => (string) $hit['title'],
            'author' => (string) ($hit['author'] ?? ''),
            'summary' => (string) ($hit['description'] ?? ''),
            'icon' => ($hit['icon_url'] ?? null) ?: null,
            'downloads' => (int) ($hit['downloads'] ?? 0),
            'rating' => (int) ($hit['follows'] ?? 0),
            'updated' => $hit['date_modified'] ?? null,
            // the loader tags (paper, fabric, ...) sit in the same list, only real categories are shown
            'categories' => array_values(array_intersect_key($categories, array_flip($hit['display_categories'] ?? []))),
            'side' => null,
            'deprecated' => false,
            'url' => self::SITE . '/plugin/' . ($hit['slug'] ?? $hit['project_id']),
        ];
    }

    private function http(): PendingRequest
    {
        return Http::timeout(15)->withUserAgent(self::AGENT)->throw();
    }
}
