<?php

namespace Marschux\GameManager\Sources;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The loader of Counter-Strike 2: Metamod:Source 2.0 from the AlliedModders build drop and
 * CounterStrikeSharp - with its .NET runtime - from its GitHub releases, one package "cs2:loader".
 * Metamod gets into gameinfo.gi through the egg's start.sh, on every start.
 */
class Source2
{
    public const PACKAGE = 'cs2:loader';

    private const METAMOD = 'https://mms.alliedmods.net/mmsdrop/2.0/';

    private const CSS = 'https://api.github.com/repos/roflmuffin/CounterStrikeSharp/releases/latest';

    private const AGENT = 'Marschux/pelican-game-manager';

    /** @return array{id: string, name: string, version: string, icon: ?string, parts: array<int, array{name: string, url: string}>} */
    public function release(): array
    {
        return Cache::remember('game-manager:source2:release', now()->addHour(), function () {
            $file = trim($this->http()->get(self::METAMOD . 'mmsource-latest-linux')->body());

            if (!preg_match('/^mmsource-([\w.-]+)-linux\.tar\.gz$/', $file, $metamod)) {
                throw new RuntimeException("Metamod:Source: unexpected build name $file");
            }

            $css = $this->http()->get(self::CSS)->json();
            $asset = collect($css['assets'] ?? [])->first(fn (array $asset) => str_starts_with($asset['name'], 'counterstrikesharp-with-runtime-linux-') && str_ends_with($asset['name'], '.zip'))
                ?? throw new RuntimeException('CounterStrikeSharp: no linux release zip');

            return [
                'id' => self::PACKAGE,
                'name' => 'Metamod:Source + CounterStrikeSharp',
                'version' => $metamod[1] . ' / ' . ltrim((string) $css['tag_name'], 'v'),
                'icon' => null,
                'parts' => [
                    ['name' => 'metamod', 'url' => self::METAMOD . $file],
                    ['name' => 'counterstrikesharp', 'url' => (string) $asset['browser_download_url']],
                ],
            ];
        });
    }

    public function latestVersion(string $id, ?string $gameVersion = null): string
    {
        return $this->release()['version'];
    }

    /** What the detail modal shows for the loader row: no page to read, only links. */
    public function details(string $id, ?string $gameVersion = null): array
    {
        $release = $this->release();

        return [
            'id' => $release['id'],
            'name' => $release['name'],
            'author' => 'AlliedModders, roflmuffin',
            'summary' => '',
            'icon' => null,
            'downloads' => 0,
            'rating' => 0,
            'url' => 'https://docs.cssharp.dev',
            'version' => $release['version'],
            'website' => 'https://www.metamodsource.net',
            'size' => 0,
            'dependants' => null,
            'dependencies' => [],
            'versions' => [['number' => $release['version'], 'date' => now()->toIso8601String(), 'downloads' => 0]],
            'readme' => '<p>Metamod:Source loads CounterStrikeSharp into the game; the server\'s start script lists it in <code>gameinfo.gi</code> on every start. CounterStrikeSharp runs the plugins in <code>addons/counterstrikesharp/plugins</code>.</p>',
            'changelog' => null,
        ];
    }

    private function http(): PendingRequest
    {
        return Http::timeout(15)->withUserAgent(self::AGENT)->throw();
    }
}
