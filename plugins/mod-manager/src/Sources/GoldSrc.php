<?php

namespace Marschux\ModManager\Sources;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The loader of GoldSrc games: Metamod-R from its GitHub releases and AMX Mod X 1.10 from the
 * AlliedModders build drop. One package, "goldsrc:loader", made of three archives.
 */
class GoldSrc
{
    public const PACKAGE = 'goldsrc:loader';

    private const METAMOD = 'https://api.github.com/repos/rehlds/Metamod-R/releases/latest';

    private const AMXX = 'https://www.amxmodx.org/amxxdrop/1.10/';

    private const AGENT = 'Marschux/pelican-mod-manager';

    /**
     * The newest builds. AMX Mod X is "base" plus the game's addon (cstrike), each a tar.gz
     * whose name the drop's amxmodx-latest-* file gives.
     *
     * @return array{id: string, name: string, version: string, icon: ?string, parts: array<int, array{name: string, url: string}>}
     */
    public function release(): array
    {
        return Cache::remember('mod-manager:goldsrc:release', now()->addHour(), function () {
            $metamod = $this->http()->get(self::METAMOD)->json();
            $asset = collect($metamod['assets'] ?? [])->first(fn (array $asset) => str_ends_with($asset['name'], '.zip'))
                ?? throw new RuntimeException('Metamod-R: no release zip');

            $amxx = [];

            foreach (['base', 'cstrike'] as $part) {
                $file = trim($this->http()->get(self::AMXX . "amxmodx-latest-$part-linux")->body());

                if (!preg_match('/^amxmodx-([\w.-]+)-' . $part . '-linux\.tar\.gz$/', $file, $match)) {
                    throw new RuntimeException("AMX Mod X: unexpected build name $file");
                }

                $amxx[$part] = ['version' => $match[1], 'url' => self::AMXX . $file];
            }

            return [
                'id' => self::PACKAGE,
                'name' => 'Metamod-R + AMX Mod X',
                'version' => ltrim((string) $metamod['tag_name'], 'v') . ' / ' . $amxx['base']['version'],
                'icon' => null,
                'parts' => [
                    ['name' => 'metamod', 'url' => (string) $asset['browser_download_url']],
                    ['name' => 'amxmodx-base', 'url' => $amxx['base']['url']],
                    ['name' => 'amxmodx-cstrike', 'url' => $amxx['cstrike']['url']],
                ],
            ];
        });
    }

    public function latestVersion(string $id, ?string $gameVersion = null): string
    {
        return $this->release()['version'];
    }

    /** What the detail modal shows for the loader row: there is no page to read, only links. */
    public function details(string $id, ?string $gameVersion = null): array
    {
        $release = $this->release();

        return [
            'id' => self::PACKAGE,
            'name' => $release['name'],
            'author' => 'ReHLDS team, AlliedModders',
            'summary' => '',
            'icon' => null,
            'downloads' => 0,
            'rating' => 0,
            'url' => 'https://www.amxmodx.org',
            'version' => $release['version'],
            'website' => 'https://github.com/rehlds/Metamod-R',
            'size' => 0,
            'dependants' => null,
            'dependencies' => [],
            'versions' => [['number' => $release['version'], 'date' => now()->toIso8601String(), 'downloads' => 0]],
            'readme' => '<p>Metamod-R loads AMX Mod X into the game; it is hooked in through <code>liblist.gam</code>. AMX Mod X runs the plugins in <code>addons/amxmodx/plugins</code>.</p>',
            'changelog' => null,
        ];
    }

    private function http(): PendingRequest
    {
        return Http::timeout(15)->withUserAgent(self::AGENT)->throw();
    }
}
