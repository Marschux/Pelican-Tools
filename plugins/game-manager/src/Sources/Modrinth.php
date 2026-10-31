<?php

namespace Marschux\GameManager\Sources;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Modrinth, through its public v2 API. A project is addressed as "modrinth:<project id>" - the
 * prefix keeps it apart from packages of other sources on the same server. Datapacks and
 * resource packs get prefixes of their own: one project can come as plugin, datapack and
 * resource pack, and each is installed somewhere else.
 */
class Modrinth
{
    private const API = 'https://api.modrinth.com/v2';

    private const SITE = 'https://modrinth.com';

    private const AGENT = 'Marschux/pelican-game-manager';

    public const PREFIX = 'modrinth:';

    /**
     * What the browser can show from Modrinth: the package prefix, the loaders a project needs
     * (the profile's for plugins), whose categories it uses and its page on the site.
     */
    public const KINDS = [
        'plugin' => ['prefix' => self::PREFIX, 'loaders' => null, 'categories' => 'mod', 'page' => 'plugin'],
        'datapack' => ['prefix' => 'modrinth-datapack:', 'loaders' => ['datapack'], 'categories' => 'mod', 'page' => 'datapack'],
        'resourcepack' => ['prefix' => 'modrinth-resourcepack:', 'loaders' => ['minecraft'], 'categories' => 'resourcepack', 'page' => 'resourcepack'],
    ];

    /** mods per page in the browser: four rows of three */
    private const PER_PAGE = 12;

    /** the browser's orderings => modrinth's index */
    private const INDEXES = ['most-downloaded' => 'downloads', 'top-rated' => 'follows', 'last-updated' => 'updated', 'newest' => 'newest'];

    /** @param  string[]  $loaders  e.g. paper, spigot, bukkit - a project needs one of them; only for plugins */
    public function __construct(private array $loaders, private string $kind = 'plugin')
    {
        $this->kind = isset(self::KINDS[$kind]) ? $kind : 'plugin';
        $this->loaders = self::KINDS[$this->kind]['loaders'] ?? $loaders;
    }

    /** plugin, datapack or resourcepack for a package of Modrinth, null for any other */
    public static function kindOf(string $package): ?string
    {
        foreach (self::KINDS as $kind => $settings) {
            if (str_starts_with($package, $settings['prefix'])) {
                return $kind;
            }
        }

        return null;
    }

    /** @return array<string, string> category slug => name */
    public function categories(): array
    {
        $type = self::KINDS[$this->kind]['categories'];

        return Cache::remember("game-manager:modrinth:categories:$type", now()->addDay(), fn () => collect($this->http()->get(self::API . '/tag/category')->json())
            // plugins and datapacks share the categories of mods, the loaders come as their own tags
            ->filter(fn (array $category) => $category['project_type'] === $type && $category['header'] === 'categories')
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

        $result = Cache::remember('game-manager:modrinth:search:' . md5(json_encode($params)), now()->addMinutes(5), fn () => $this->http()->get(self::API . '/search', $params)->json());
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

        return Cache::remember("game-manager:modrinth:details:$project:" . implode(',', $this->loaders) . ":$gameVersion", now()->addHour(), function () use ($id, $project, $gameVersion) {
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
                'url' => self::SITE . '/' . self::KINDS[$this->kind]['page'] . '/' . ($info['slug'] ?? $project),
                'version' => (string) ($latest['version_number'] ?? ''),
                'website' => ($info['source_url'] ?? null) ?: (($info['wiki_url'] ?? null) ?: null),
                'size' => (int) (collect($latest['files'] ?? [])->firstWhere('primary', true)['size'] ?? ($latest['files'][0]['size'] ?? 0)),
                // modrinth does not count who depends on a project
                'dependants' => null,
                'dependencies' => $dependencies->map(fn (array $dependency, string $projectId) => [
                    'id' => $this->prefix() . $projectId,
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

    /**
     * One version of a project, as much as installing it needs: $version, else the newest for
     * $gameVersion (or for any game version without one).
     *
     * @return array{id: string, name: string, version: string, icon: ?string, download: string, file: string, size: int, requires: string[], optional: array<string, string>}
     */
    public function release(string $id, ?string $version = null, ?string $gameVersion = null): array
    {
        $project = $this->project(self::split($id));
        $versions = $this->versions($project['id'], $version === null ? $gameVersion : null);
        $release = $version === null ? ($versions[0] ?? null) : collect($versions)->firstWhere('version_number', $version);

        if ($release === null) {
            throw new RuntimeException(trans('game-manager::mods.messages.no_version', ['name' => $project['title'], 'version' => $version ?? $gameVersion]));
        }

        $file = collect($release['files'] ?? [])->firstWhere('primary', true) ?? ($release['files'][0] ?? null)
            ?? throw new RuntimeException(trans('game-manager::mods.messages.no_version', ['name' => $project['title'], 'version' => $release['version_number']]));

        $dependencies = collect($release['dependencies'] ?? [])->filter(fn (array $dependency) => filled($dependency['project_id']));
        $optional = $dependencies->where('dependency_type', 'optional')->pluck('project_id')->all();
        $names = $optional === [] ? [] : collect($this->http()->get(self::API . '/projects', ['ids' => json_encode($optional)])->json())->pluck('title', 'id')->all();

        return [
            'id' => $this->prefix() . $project['id'],
            'name' => (string) $project['title'],
            'version' => (string) $release['version_number'],
            'icon' => ($project['icon_url'] ?? null) ?: null,
            'download' => (string) $file['url'],
            'file' => (string) $file['filename'],
            'size' => (int) ($file['size'] ?? 0),
            // a resource pack is not put on the server, players get it from the link and check it with this
            'sha1' => (string) ($file['hashes']['sha1'] ?? ''),
            'requires' => $dependencies->where('dependency_type', 'required')->map(fn (array $dependency) => $this->prefix() . $dependency['project_id'])->values()->all(),
            'optional' => collect($optional)->mapWithKeys(fn (string $projectId) => [$this->prefix() . $projectId => (string) ($names[$projectId] ?? $projectId)])->all(),
        ];
    }

    /** The newest version number for $gameVersion, for the update hints. */
    public function latestVersion(string $id, ?string $gameVersion = null): string
    {
        return $this->release($id, null, $gameVersion)['version'];
    }

    /**
     * Which versions these jars are, by their SHA1 - for jars put on the server by hand. A
     * version for none of the profile's loaders does not count.
     *
     * @param  string[]  $hashes  sha1
     * @return array<string, array{id: string, name: string, version: string, icon: ?string, requires: string[]}> hash => found ones only
     */
    public function byHashes(array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        $versions = array_filter(
            $this->http()->post(self::API . '/version_files', ['hashes' => array_values($hashes), 'algorithm' => 'sha1'])->json() ?: [],
            // e.g. the fabric build of a project that also has a paper one: a mod, not a plugin here
            fn (array $version) => array_intersect($version['loaders'] ?? [], $this->loaders) !== [],
        );
        $projectIds = array_values(array_unique(array_column($versions, 'project_id')));
        $projects = $projectIds === [] ? [] : collect($this->http()->get(self::API . '/projects', ['ids' => json_encode($projectIds)])->json())->keyBy('id')->all();
        $found = [];

        foreach ($versions as $hash => $version) {
            $project = $projects[$version['project_id']] ?? [];

            $found[$hash] = [
                'id' => $this->prefix() . $version['project_id'],
                'name' => (string) ($project['title'] ?? $version['name'] ?? $version['project_id']),
                'version' => (string) $version['version_number'],
                'icon' => ($project['icon_url'] ?? null) ?: null,
                'requires' => collect($version['dependencies'] ?? [])
                    ->filter(fn (array $dependency) => $dependency['dependency_type'] === 'required' && filled($dependency['project_id']))
                    ->map(fn (array $dependency) => $this->prefix() . $dependency['project_id'])
                    ->values()
                    ->all(),
            ];
        }

        return $found;
    }

    /** @return array<string, mixed> */
    private function project(string $project): array
    {
        return Cache::remember("game-manager:modrinth:project:$project", now()->addHour(), fn () => $this->http()->get(self::API . "/project/$project")->json());
    }

    /** @return array<int, array<string, mixed>> newest first, for the profile's loaders */
    private function versions(string $project, ?string $gameVersion): array
    {
        return Cache::remember(
            "game-manager:modrinth:versions:$project:" . implode(',', $this->loaders) . ":$gameVersion",
            now()->addHour(),
            fn () => $this->http()->get(self::API . "/project/$project/version", array_filter([
                'loaders' => json_encode($this->loaders),
                'game_versions' => $gameVersion ? json_encode([$gameVersion]) : null,
            ]))->json(),
        );
    }

    /** @return string the project id or slug in "modrinth:<id>" (or modrinth-datapack:, modrinth-resourcepack:) */
    public static function split(string $id): string
    {
        if (!preg_match('/^modrinth(?:-datapack|-resourcepack)?:([\w-]+)$/', $id, $match)) {
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
            'id' => $this->prefix() . $hit['project_id'],
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
            'url' => self::SITE . '/' . self::KINDS[$this->kind]['page'] . '/' . ($hit['slug'] ?? $hit['project_id']),
        ];
    }

    private function prefix(): string
    {
        return self::KINDS[$this->kind]['prefix'];
    }

    private function http(): PendingRequest
    {
        return Http::timeout(15)->withUserAgent(self::AGENT)->throw();
    }
}
