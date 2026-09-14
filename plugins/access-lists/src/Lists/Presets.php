<?php

namespace Marschux\AccessLists\Lists;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The lists that ship with the plugin, so the common games need no setup at all.
 *
 * Which of them apply is decided by the files a server actually has, not by its egg name: eggs
 * get renamed, copied and written by anyone, while a Minecraft server that has no whitelist.json
 * has no whitelist to edit either. One directory listing per preset directory answers it.
 */
class Presets
{
    /** Detection asks the daemon, so the answer is kept for a while - navigation renders often. */
    private const TTL = 300;

    private const REASON = 'Banned by an operator.';

    /**
     * @return array<string, array{dir: string, files: array<int, array<string, mixed>>}>
     */
    public static function all(): array
    {
        return [
            'minecraft' => [
                'dir' => '/',
                'files' => [
                    [
                        'label' => 'Whitelist',
                        'path' => 'whitelist.json',
                        'format' => ListDefinition::FORMAT_JSON,
                        'key_field' => 'uuid',
                        'display_field' => 'name',
                        'entry_template' => '{"uuid": "{uuid}", "name": "{name}"}',
                    ],
                    [
                        'label' => 'Admins',
                        'path' => 'ops.json',
                        'format' => ListDefinition::FORMAT_JSON,
                        'key_field' => 'uuid',
                        'display_field' => 'name',
                        'entry_template' => '{"uuid": "{uuid}", "name": "{name}", "level": 4, "bypassesPlayerLimit": false}',
                    ],
                    [
                        'label' => 'Bans',
                        'path' => 'banned-players.json',
                        'format' => ListDefinition::FORMAT_JSON,
                        'key_field' => 'uuid',
                        'display_field' => 'name',
                        'entry_template' => '{"uuid": "{uuid}", "name": "{name}", "created": "{now}", "source": "Server", "expires": "forever", "reason": "' . self::REASON . '"}',
                    ],
                    [
                        'label' => 'IP bans',
                        'path' => 'banned-ips.json',
                        'format' => ListDefinition::FORMAT_JSON,
                        'key_field' => 'ip',
                        'display_field' => 'ip',
                        'entry_template' => '{"ip": "{ip}", "created": "{now}", "source": "Server", "expires": "forever", "reason": "' . self::REASON . '"}',
                    ],
                ],
            ],
            'valheim' => [
                'dir' => 'savegames',
                'files' => [
                    ['label' => 'Admins', 'path' => 'savegames/adminlist.txt'],
                    ['label' => 'Bans', 'path' => 'savegames/bannedlist.txt'],
                    ['label' => 'Whitelist', 'path' => 'savegames/permittedlist.txt'],
                ],
            ],
        ];
    }

    /**
     * The preset lists this server actually has, keyed like the configured ones.
     *
     * @return array<string, ListDefinition>
     */
    public static function forServer(Server $server): array
    {
        $definitions = [];

        foreach (self::detected($server) as $key) {
            [$game, $index] = explode(':', $key, 2);
            $file = self::all()[$game]['files'][(int) $index] ?? null;

            $definition = $file === null ? null : ListDefinition::fromArray("preset:$key", $file);

            if ($definition) {
                $definitions[$definition->key] = $definition;
            }
        }

        return $definitions;
    }

    public static function forget(Server $server): void
    {
        Cache::forget(self::cacheKey($server));
    }

    /** @return string[] "<game>:<index>" of every preset file that exists on this server */
    private static function detected(Server $server): array
    {
        return Cache::remember(self::cacheKey($server), self::TTL, function () use ($server) {
            $found = [];

            foreach (self::all() as $game => $preset) {
                $names = self::names($server, $preset['dir']);

                if ($names === []) {
                    continue;
                }

                foreach ($preset['files'] as $index => $file) {
                    if (in_array(basename($file['path']), $names, true)) {
                        $found[] = "$game:$index";
                    }
                }
            }

            return $found;
        });
    }

    /**
     * File names in one directory, empty when it cannot be listed - a stopped server, a missing
     * folder or no permission all mean "no presets from here" rather than an error.
     *
     * @return string[]
     */
    private static function names(Server $server, string $directory): array
    {
        try {
            $entries = app(DaemonFileRepository::class)->setServer($server)->getDirectory($directory);
        } catch (Throwable) {
            return [];
        }

        $names = [];

        foreach ($entries as $entry) {
            if (!($entry['directory'] ?? false)) {
                $names[] = (string) ($entry['name'] ?? '');
            }
        }

        return $names;
    }

    private static function cacheKey(Server $server): string
    {
        return "access-lists.presets.$server->uuid";
    }
}
