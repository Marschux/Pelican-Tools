<?php

namespace Marschux\ModManager\Sources;

use Illuminate\Http\Client\PendingRequest;
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

    public const ORDERINGS = ['most-downloaded', 'top-rated', 'last-updated', 'newest'];

    /** @param  string[]  $hidden  packages never shown, as "Namespace-Name" */
    public function __construct(private string $community, private array $hidden = []) {}

    /** @return array<string, string> category id => name */
    public function categories(): array
    {
        return Cache::remember("mod-manager:thunderstore:$this->community:categories", now()->addDay(), fn () => collect(
            $this->http()->get(self::SITE . "/api/cyberstorm/community/$this->community/filters/")->json('package_categories', [])
        )->pluck('name', 'id')->all());
    }

    /**
     * One page of 20 packages.
     *
     * @return array{items: array<int, array<string, mixed>>, more: bool}
     */
    public function search(string $query = '', ?string $category = null, string $ordering = 'most-downloaded', int $page = 1): array
    {
        $response = $this->http()->get(self::SITE . "/api/cyberstorm/listing/$this->community/", array_filter([
            'q' => $query,
            'included_categories' => $category,
            'ordering' => in_array($ordering, self::ORDERINGS, true) ? $ordering : 'most-downloaded',
            'page' => $page,
        ]));

        $items = collect($response->json('results', []))
            ->reject(fn (array $package) => in_array("{$package['namespace']}-{$package['name']}", $this->hidden, true))
            ->map(fn (array $package) => $this->item($package))
            ->values()
            ->all();

        return ['items' => $items, 'more' => $response->json('next') !== null];
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
            $versions = $this->http()->get(self::SITE . "/api/cyberstorm/package/$namespace/$name/versions/")->json();
            $readme = $this->http()->get(self::SITE . "/api/experimental/package/$namespace/$name/{$package['latest_version_number']}/readme/")->json('markdown', '');

            return $this->item($package) + [
                'version' => $package['latest_version_number'],
                'website' => $package['website_url'] ?: null,
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
                // readmes are written by anyone: raw html is stripped, unsafe links dropped
                'readme' => Str::markdown((string) $readme, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
            ];
        });
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
            'deprecated' => (bool) ($package['is_deprecated'] ?? false),
            'url' => self::SITE . "/c/$this->community/p/{$package['namespace']}/{$package['name']}/",
        ];
    }

    private function http(): PendingRequest
    {
        return Http::timeout(15)->withUserAgent('Marschux/pelican-mod-manager')->throw();
    }
}
