<?php

namespace Marschux\ModManager\Sources;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * What GoldSrc games get on top: the loader - Metamod-R from its GitHub releases and AMX Mod X
 * 1.10 from the AlliedModders build drop, one package "goldsrc:loader" - and, optionally, the
 * engine - ReHLDS, ReGameDLL and the ReAPI module, "goldsrc:engine".
 */
class GoldSrc
{
    public const PACKAGE = 'goldsrc:loader';

    public const ENGINE = 'goldsrc:engine';

    private const METAMOD = 'https://api.github.com/repos/rehlds/Metamod-R/releases/latest';

    private const AMXX = 'https://www.amxmodx.org/amxxdrop/1.10/';

    /** the engine's parts, each the "-bin-" zip of its latest release */
    private const ENGINE_REPOS = ['rehlds' => 'rehlds/ReHLDS', 'regamedll' => 'rehlds/ReGameDLL_CS', 'reapi' => 'rehlds/ReAPI'];

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

    /**
     * ReHLDS, ReGameDLL and ReAPI, newest releases. ReHLDS is built against the Steam API of
     * Valve's steam_legacy branch, which today's HLDS no longer ships - so its libsteam_api.so
     * comes along, from the ReHLDS repository at the same release.
     *
     * @return array{id: string, name: string, version: string, icon: ?string, parts: array<int, array{name: string, url: string}>}
     */
    public function engineRelease(): array
    {
        return Cache::remember('mod-manager:goldsrc:engine', now()->addHour(), function () {
            $tags = [];
            $parts = [];

            foreach (self::ENGINE_REPOS as $name => $repo) {
                $release = $this->http()->get("https://api.github.com/repos/$repo/releases/latest")->json();
                $asset = collect($release['assets'] ?? [])->first(fn (array $asset) => str_contains($asset['name'], '-bin-') && str_ends_with($asset['name'], '.zip'))
                    ?? throw new RuntimeException("$repo: no release zip");

                $tags[$name] = (string) $release['tag_name'];
                $parts[] = ['name' => $name, 'url' => (string) $asset['browser_download_url']];
            }

            $parts[] = ['name' => 'steam_api', 'url' => "https://raw.githubusercontent.com/rehlds/ReHLDS/{$tags['rehlds']}/rehlds/lib/linux32/libsteam_api.so"];

            return [
                'id' => self::ENGINE,
                'name' => 'ReHLDS + ReGameDLL + ReAPI',
                'version' => implode(' / ', array_map(fn (string $tag) => ltrim($tag, 'v'), $tags)),
                'icon' => null,
                'parts' => $parts,
            ];
        });
    }

    public function latestVersion(string $id, ?string $gameVersion = null): string
    {
        return $id === self::ENGINE ? $this->engineRelease()['version'] : $this->release()['version'];
    }

    /** What the detail modal shows for the loader and the engine rows: there is no page to read, only links. */
    public function details(string $id, ?string $gameVersion = null): array
    {
        $engine = $id === self::ENGINE;
        $release = $engine ? $this->engineRelease() : $this->release();

        return [
            'id' => $release['id'],
            'name' => $release['name'],
            'author' => $engine ? 'ReHLDS team' : 'ReHLDS team, AlliedModders',
            'summary' => '',
            'icon' => null,
            'downloads' => 0,
            'rating' => 0,
            'url' => $engine ? 'https://github.com/rehlds/ReHLDS' : 'https://www.amxmodx.org',
            'version' => $release['version'],
            'website' => $engine ? 'https://github.com/rehlds/ReGameDLL_CS' : 'https://github.com/rehlds/Metamod-R',
            'size' => 0,
            'dependants' => null,
            'dependencies' => [],
            'versions' => [['number' => $release['version'], 'date' => now()->toIso8601String(), 'downloads' => 0]],
            'readme' => $engine
                ? '<p>ReHLDS replaces the engine of HLDS, ReGameDLL the game library (<code>dlls/cs.so</code>), ReAPI gives AMX Mod X plugins access to both. Valve\'s files are kept and come back when this is removed.</p>'
                : '<p>Metamod-R loads AMX Mod X into the game; it is hooked in through <code>liblist.gam</code>. AMX Mod X runs the plugins in <code>addons/amxmodx/plugins</code>.</p>',
            'changelog' => null,
        ];
    }

    private function http(): PendingRequest
    {
        return Http::timeout(15)->withUserAgent(self::AGENT)->throw();
    }
}
