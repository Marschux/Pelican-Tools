<?php

namespace Marschux\GameManager\Sources;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The loader of Source 1 games (Counter-Strike: Source, CS:GO): Metamod:Source and SourceMod,
 * both 1.12 from the AlliedModders build drops, one package "source1:loader". Metamod loads
 * itself through addons/metamod.vdf from its own archive - nothing to hook in.
 */
class Source1
{
    public const PACKAGE = 'source1:loader';

    private const METAMOD = 'https://mms.alliedmods.net/mmsdrop/1.12/';

    private const SOURCEMOD = 'https://sm.alliedmods.net/smdrop/1.12/';

    private const AGENT = 'Marschux/pelican-game-manager';

    /** @return array{id: string, name: string, version: string, icon: ?string, parts: array<int, array{name: string, url: string}>} */
    public function release(): array
    {
        return Cache::remember('game-manager:source1:release', now()->addHour(), function () {
            $metamod = $this->latest(self::METAMOD . 'mmsource-latest-linux', 'mmsource');
            $sourcemod = $this->latest(self::SOURCEMOD . 'sourcemod-latest-linux', 'sourcemod');

            return [
                'id' => self::PACKAGE,
                'name' => 'Metamod:Source + SourceMod',
                'version' => $metamod[1] . ' / ' . $sourcemod[1],
                'icon' => null,
                'parts' => [
                    ['name' => 'metamod', 'url' => self::METAMOD . $metamod[0]],
                    ['name' => 'sourcemod', 'url' => self::SOURCEMOD . $sourcemod[0]],
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
            'author' => 'AlliedModders',
            'summary' => '',
            'icon' => null,
            'downloads' => 0,
            'rating' => 0,
            'url' => 'https://www.sourcemod.net',
            'version' => $release['version'],
            'website' => 'https://www.metamodsource.net',
            'size' => 0,
            'dependants' => null,
            'dependencies' => [],
            'versions' => [['number' => $release['version'], 'date' => now()->toIso8601String(), 'downloads' => 0]],
            'readme' => '<p>Metamod:Source loads SourceMod into the game through <code>addons/metamod.vdf</code>. SourceMod runs the plugins in <code>addons/sourcemod/plugins</code>; those in its <code>disabled</code> folder are skipped.</p>',
            'changelog' => null,
        ];
    }

    /** @return array{0: string, 1: string} the build drop's newest file and its version, e.g. "1.12.0-git7259" */
    private function latest(string $url, string $name): array
    {
        $file = trim($this->http()->get($url)->body());

        if (!preg_match('/^' . $name . '-([\w.-]+)-linux\.tar\.gz$/', $file, $match)) {
            throw new RuntimeException("$name: unexpected build name $file");
        }

        return [$file, $match[1]];
    }

    private function http(): PendingRequest
    {
        return Http::timeout(15)->withUserAgent(self::AGENT)->throw();
    }
}
