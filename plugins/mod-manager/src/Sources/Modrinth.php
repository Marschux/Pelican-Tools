<?php

namespace Marschux\ModManager\Sources;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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
     * One page of 12 projects. The last two flags are Thunderstore's and mean nothing here.
     *
     * @return array{items: array<int, array<string, mixed>>, pages: int}
     */
    public function search(string $query = '', ?string $category = null, string $ordering = 'most-downloaded', int $page = 1, bool $showClientSide = true, bool $showDeprecated = false): array
    {
        $facets = array_values(array_filter([
            // one of the loaders (OR), and the category (AND)
            array_map(fn (string $loader) => "categories:$loader", $this->loaders),
            $category ? ["categories:$category"] : null,
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
