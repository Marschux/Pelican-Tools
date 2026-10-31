<?php

namespace Marschux\GameManager;

use App\Models\Server;
use App\Services\Servers\EnvironmentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Marschux\GameManager\Models\ModProfile;
use Throwable;

/**
 * The game version a server runs, to pick mod versions that fit it.
 *
 * What the game wrote itself comes first (version_file, there after the first start), else the
 * egg variable the install script reads (version_variable).
 */
class GameVersion
{
    /** @return array{version: string, from: string}|null from: the file or variable it was read from */
    public static function of(Server $server, ModProfile $profile): ?array
    {
        $file = (string) $profile->setting('version_file');
        $version = $file !== '' ? self::fromFile((string) (new ServerFiles($server))->read($file)) : null;

        if ($version !== null) {
            return ['version' => $version, 'from' => $file];
        }

        $variable = (string) $profile->setting('version_variable');

        if ($variable === '') {
            return null;
        }

        $value = trim((string) (app(EnvironmentService::class)->handle($server)[$variable] ?? ''));

        if ($value === '' || $value === 'latest') {
            // "latest" is resolved the way the Paper egg does it; another game brings its own lookup
            $value = self::latestPaper();
        }

        return $value !== null ? ['version' => $value, 'from' => $variable] : null;
    }

    /** Paper's .paper/version_history.json: {"currentVersion": "26.3-226-4f49049 (MC: 26.3)"} */
    public static function fromFile(string $content): ?string
    {
        $history = json_decode($content, true);

        return is_array($history) && preg_match('/\(MC: ([^)\s]+)\)/', (string) ($history['currentVersion'] ?? ''), $match) ? $match[1] : null;
    }

    /** The newest Paper release, the same pick as the egg's install script: no pre-releases (ids with a dash). */
    private static function latestPaper(): ?string
    {
        try {
            return Cache::remember('game-manager:paper-latest', now()->addHour(), function () {
                $versions = Http::timeout(10)->withUserAgent('pelican-game-manager')->get('https://fill.papermc.io/v3/projects/paper/versions')->throw()->json('versions', []);

                foreach ($versions as $entry) {
                    $id = (string) ($entry['version']['id'] ?? '');

                    if ($id !== '' && !str_contains($id, '-')) {
                        return $id;
                    }
                }

                throw new \RuntimeException('no Paper release found');
            });
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
