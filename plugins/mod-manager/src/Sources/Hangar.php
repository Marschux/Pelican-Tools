<?php

namespace Marschux\ModManager\Sources;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Hangar, PaperMC's plugin site, through its v1 API. A project is addressed as
 * "hangar:<slug>" - slugs are unique across Hangar, the owner is not needed.
 */
class Hangar
{
    private const API = 'https://hangar.papermc.io/api/v1';

    private const SITE = 'https://hangar.papermc.io';

    private const AGENT = 'Marschux/pelican-mod-manager';

    public const PREFIX = 'hangar:';

    private const PLATFORM = 'PAPER';

    /** mods per page in the browser: four rows of three */
    private const PER_PAGE = 12;

    /** the browser's orderings => hangar's sort */
    private const SORTS = ['most-downloaded' => '-downloads', 'top-rated' => '-stars', 'last-updated' => '-updated', 'newest' => '-newest'];

    /** hangar's categories are a fixed list, there is no endpoint for them */
    private const CATEGORIES = ['admin_tools', 'chat', 'dev_tools', 'economy', 'gameplay', 'games', 'protection', 'role_playing', 'world_management', 'misc'];

    /** @return array<string, string> category => name */
    public function categories(): array
    {
        return array_combine(self::CATEGORIES, array_map(fn (string $category) => ucwords(str_replace('_', ' ', $category)), self::CATEGORIES));
    }

    /**
     * One page of 12 projects, only those for $gameVersion when one is given. The two flags are
     * Thunderstore's and mean nothing here.
     *
     * @return array{items: array<int, array<string, mixed>>, pages: int}
     */
    public function search(string $query = '', ?string $category = null, string $ordering = 'most-downloaded', int $page = 1, bool $showClientSide = true, bool $showDeprecated = false, ?string $gameVersion = null): array
    {
        $params = array_filter([
            'query' => $query,
            'category' => in_array($category, self::CATEGORIES, true) ? $category : null,
            'platform' => self::PLATFORM,
            'version' => $gameVersion,
            // a typed search is best sorted by how well it matches, hangar's default then
            'sort' => $query !== '' ? null : (self::SORTS[$ordering] ?? '-downloads'),
            'offset' => (max(1, $page) - 1) * self::PER_PAGE,
            'limit' => self::PER_PAGE,
        ], fn ($value) => $value !== null && $value !== '');

        $result = Cache::remember('mod-manager:hangar:search:' . md5(json_encode($params)), now()->addMinutes(5), fn () => $this->http()->get(self::API . '/projects', $params)->json());

        return [
            'items' => array_map(fn (array $project) => $this->item($project), $result['result'] ?? []),
            'pages' => max(1, (int) ceil(((int) ($result['pagination']['count'] ?? 0)) / self::PER_PAGE)),
        ];
    }

    /**
     * Everything the detail view shows; versions only for $gameVersion when one is given.
     * Hangar keeps a changelog per version, the newest few are put together into one.
     *
     * @return array<string, mixed>
     */
    public function details(string $id, ?string $gameVersion = null): array
    {
        $slug = self::split($id);

        return Cache::remember("mod-manager:hangar:details:$slug:$gameVersion", now()->addHour(), function () use ($slug, $gameVersion) {
            $responses = Http::pool(fn (Pool $pool) => [
                $pool->as('project')->timeout(15)->withUserAgent(self::AGENT)->get(self::API . "/projects/$slug"),
                $pool->as('versions')->timeout(15)->withUserAgent(self::AGENT)->get(self::API . "/projects/$slug/versions", array_filter([
                    'platform' => self::PLATFORM,
                    'platformVersion' => $gameVersion,
                    'limit' => 25,
                ])),
                // the project itself leaves its page out, the listing has it
                $pool->as('page')->timeout(15)->withUserAgent(self::AGENT)->get(self::API . "/pages/main/$slug"),
            ]);

            foreach ($responses as $response) {
                if (!$response instanceof Response) {
                    throw $response;
                }

                $response->throw();
            }

            $project = $responses['project']->json();
            $versions = $responses['versions']->json('result', []);
            $latest = $versions[0] ?? [];
            $file = $latest['downloads'][self::PLATFORM]['fileInfo'] ?? [];

            $changelog = collect($versions)
                ->take(10)
                ->filter(fn (array $version) => filled($version['description'] ?? null))
                ->map(fn (array $version) => "## {$version['name']}\n\n{$version['description']}")
                ->implode("\n\n");

            $links = collect($project['settings']['links'] ?? [])->pluck('links')->flatten(1);

            return $this->item($project) + [
                'version' => (string) ($latest['name'] ?? ''),
                'website' => ($links->first(fn (array $link) => preg_match('/source|github|git/i', (string) ($link['name'] ?? '')))['url'] ?? null) ?: null,
                'size' => (int) ($file['sizeBytes'] ?? 0),
                // hangar does not count who depends on a project
                'dependants' => null,
                'dependencies' => array_map(fn (array $dependency) => [
                    // a dependency outside hangar only has a link
                    'id' => isset($dependency['projectId']) ? self::PREFIX . $dependency['name'] : null,
                    'name' => (string) $dependency['name'],
                    'version' => '',
                    'icon' => null,
                    'hidden' => false,
                    'optional' => !($dependency['required'] ?? false),
                ], $latest['pluginDependencies'][self::PLATFORM] ?? []),
                'versions' => array_map(fn (array $version) => [
                    'number' => (string) $version['name'],
                    'date' => $version['createdAt'],
                    'downloads' => (int) ($version['stats']['totalDownloads'] ?? 0),
                ], $versions),
                'readme' => Thunderstore::markdown($responses['page']->body()),
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
        $name = (string) $project['name'];
        $versions = $this->versions($project['namespace']['slug'], $version === null ? $gameVersion : null);
        $release = $version === null ? ($versions[0] ?? null) : collect($versions)->firstWhere('name', $version);

        if ($release === null) {
            throw new RuntimeException(trans('mod-manager::mods.messages.no_version', ['name' => $name, 'version' => $version ?? $gameVersion]));
        }

        $download = $release['downloads'][self::PLATFORM] ?? [];

        // only a link to somewhere else: often a web page rather than the jar, so not pulled blindly
        if (blank($download['downloadUrl'] ?? null)) {
            throw new RuntimeException(trans('mod-manager::mods.messages.external_only', ['name' => $name, 'url' => $download['externalUrl'] ?? self::SITE]));
        }

        // a dependency outside hangar (no project id) is left out, the details list it
        $dependencies = collect($release['pluginDependencies'][self::PLATFORM] ?? [])->filter(fn (array $dependency) => isset($dependency['projectId']));
        $slug = fn (array $dependency) => self::PREFIX . $this->project((string) $dependency['projectId'])['namespace']['slug'];

        return [
            'id' => self::PREFIX . $project['namespace']['slug'],
            'name' => $name,
            'version' => (string) $release['name'],
            'icon' => ($project['avatarUrl'] ?? null) ?: null,
            'download' => (string) $download['downloadUrl'],
            'file' => (string) ($download['fileInfo']['name'] ?? ''),
            'size' => (int) ($download['fileInfo']['sizeBytes'] ?? 0),
            'requires' => $dependencies->filter(fn (array $dependency) => $dependency['required'] ?? false)->map($slug)->values()->all(),
            'optional' => $dependencies->reject(fn (array $dependency) => $dependency['required'] ?? false)->mapWithKeys(fn (array $dependency) => [$slug($dependency) => (string) $dependency['name']])->all(),
        ];
    }

    /** The newest version number for $gameVersion, for the update hints. */
    public function latestVersion(string $id, ?string $gameVersion = null): string
    {
        return $this->release($id, null, $gameVersion)['version'];
    }

    /**
     * Which project a jar belongs to, by its SHA256 - for jars put on the server by hand. Hangar
     * names only the project, the version is looked up among its newest ones.
     *
     * @return array{id: string, name: string, version: ?string, icon: ?string, requires: string[]}|null
     */
    public function byHash(string $sha256): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            return null;
        }

        try {
            $project = $this->http()->get(self::API . "/versions/hash/$sha256")->json();
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                return null;
            }

            throw $exception;
        }

        $slug = (string) $project['namespace']['slug'];
        $version = collect($this->versions($slug, null))->first(fn (array $version) => ($version['downloads'][self::PLATFORM]['fileInfo']['sha256Hash'] ?? null) === $sha256);

        return [
            'id' => self::PREFIX . $slug,
            'name' => (string) $project['name'],
            'version' => $version['name'] ?? null,
            'icon' => ($project['avatarUrl'] ?? null) ?: null,
            'requires' => collect($version['pluginDependencies'][self::PLATFORM] ?? [])
                ->filter(fn (array $dependency) => ($dependency['required'] ?? false) && isset($dependency['projectId']))
                ->map(fn (array $dependency) => self::PREFIX . $this->project((string) $dependency['projectId'])['namespace']['slug'])
                ->values()
                ->all(),
        ];
    }

    /** The project's page: hangar wants the owner in it, the slug alone is not enough. */
    public function url(string $id): string
    {
        $project = $this->project(self::split($id));

        return self::SITE . '/' . ($project['namespace']['owner'] ?? '') . '/' . ($project['namespace']['slug'] ?? self::split($id));
    }

    /** @return array<string, mixed> by slug or by hangar's numeric id */
    private function project(string $project): array
    {
        return Cache::remember("mod-manager:hangar:project:$project", now()->addHour(), fn () => $this->http()->get(self::API . "/projects/$project")->json());
    }

    /** @return array<int, array<string, mixed>> newest first */
    private function versions(string $slug, ?string $gameVersion): array
    {
        return Cache::remember("mod-manager:hangar:versions:$slug:$gameVersion", now()->addHour(), fn () => $this->http()->get(self::API . "/projects/$slug/versions", array_filter([
            'platform' => self::PLATFORM,
            'platformVersion' => $gameVersion,
            'limit' => 25,
        ]))->json('result', []));
    }

    /** @return string the slug in "hangar:<slug>" */
    public static function split(string $id): string
    {
        if (!preg_match('/^hangar:([\w.-]+)$/', $id, $match) || str_contains($match[1], '..')) {
            throw new RuntimeException("not a Hangar project: $id");
        }

        return $match[1];
    }

    /**
     * @param  array<string, mixed>  $project
     * @return array<string, mixed>
     */
    private function item(array $project): array
    {
        $owner = (string) ($project['namespace']['owner'] ?? '');
        $slug = (string) ($project['namespace']['slug'] ?? $project['name']);

        return [
            'id' => self::PREFIX . $slug,
            'name' => (string) $project['name'],
            'author' => $owner,
            'summary' => (string) ($project['description'] ?? ''),
            'icon' => ($project['avatarUrl'] ?? null) ?: null,
            'downloads' => (int) ($project['stats']['downloads'] ?? 0),
            'rating' => (int) ($project['stats']['stars'] ?? 0),
            'updated' => $project['lastUpdated'] ?? null,
            'categories' => isset($project['category']) ? [$this->categories()[$project['category']] ?? $project['category']] : [],
            'side' => null,
            'deprecated' => false,
            'url' => self::SITE . "/$owner/$slug",
        ];
    }

    private function http(): PendingRequest
    {
        return Http::timeout(15)->withUserAgent(self::AGENT)->throw();
    }
}
