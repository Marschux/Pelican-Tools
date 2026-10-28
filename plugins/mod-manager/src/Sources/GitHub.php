<?php

namespace Marschux\ModManager\Sources;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Plugins from a GitHub repository's releases - how CounterStrikeSharp plugins are published.
 * A package is "github:<owner>/<repo>@<asset>", the asset's name with its version cut out ("#"),
 * so an update finds the same file of the next release.
 */
class GitHub
{
    public const PREFIX = 'github:';

    private const AGENT = 'Marschux/pelican-mod-manager';

    /** "owner/repo" from a link to the repository or anything below it, null when it is none. */
    public static function repository(string $link): ?string
    {
        $link = trim($link);

        if (preg_match('#^(?:https?://)?(?:www\.)?github\.com/([\w.-]+)/([\w.-]+)#i', $link, $match)
            || preg_match('#^([\w.-]+)/([\w.-]+)$#', $link, $match)) {
            return $match[1] . '/' . preg_replace('/\.git$/', '', $match[2]);
        }

        return null;
    }

    /** An asset's name with its version cut out: "RockTheVote-v1.9.6.RELEASE.zip" gives "RockTheVote-#.RELEASE.zip". */
    public static function shape(string $asset): string
    {
        return (string) preg_replace('/v?\d+(?:\.\d+)+/i', '#', $asset);
    }

    public static function package(string $repository, string $asset): string
    {
        return self::PREFIX . $repository . '@' . self::shape($asset);
    }

    /** @return array{0: string, 1: string} repository and asset shape of a package */
    public static function parse(string $package): array
    {
        [$repository, $shape] = explode('@', substr($package, strlen(self::PREFIX)), 2) + [1 => ''];

        return [$repository, $shape];
    }

    /**
     * The newest release with zip assets - a repository may mark none as "latest", then the
     * newest that is no pre-release counts.
     *
     * @return array{tag: string, assets: array<int, array{name: string, url: string, size: int}>}
     */
    public function release(string $repository): array
    {
        return Cache::remember("mod-manager:github:$repository", now()->addHour(), function () use ($repository) {
            $releases = $this->http()->get("https://api.github.com/repos/$repository/releases", ['per_page' => 10])->json();

            foreach (is_array($releases) ? $releases : [] as $release) {
                $assets = collect($release['assets'] ?? [])
                    ->filter(fn (array $asset) => preg_match('/\.(zip|amxx)\z/i', $asset['name']))
                    ->map(fn (array $asset) => ['name' => (string) $asset['name'], 'url' => (string) $asset['browser_download_url'], 'size' => (int) $asset['size']])
                    ->values()
                    ->all();

                if (!($release['prerelease'] ?? false) && !($release['draft'] ?? false) && $assets !== []) {
                    return ['tag' => (string) $release['tag_name'], 'assets' => $assets];
                }
            }

            throw new RuntimeException(trans('mod-manager::mods.messages.github_none', ['repository' => $repository]));
        });
    }

    /** The newest release's asset of the same shape, for an update. */
    public function asset(string $package): array
    {
        [$repository, $shape] = self::parse($package);
        $release = $this->release($repository);
        $asset = collect($release['assets'])->first(fn (array $asset) => self::shape($asset['name']) === $shape)
            ?? throw new RuntimeException(trans('mod-manager::mods.messages.github_none', ['repository' => $repository]));

        return $asset + ['tag' => $release['tag']];
    }

    public function latestVersion(string $package, ?string $gameVersion = null): string
    {
        return $this->release(self::parse($package)[0])['tag'];
    }

    private function http(): PendingRequest
    {
        return Http::timeout(15)->withUserAgent(self::AGENT)->throw();
    }
}
