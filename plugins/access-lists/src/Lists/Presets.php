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

    private const VALHEIM_ID = 'Eine ID pro Eintrag, mit Plattform-Präfix, z. B. Steam_76561197960287930. Die ID steht im Server-Log, wenn sich der Spieler verbindet.';

    /**
     * @return array<string, array{dir: string, files: array<int, array<string, mixed>>}>
     */
    public static function all(): array
    {
        return [
            'minecraft' => [
                'name' => 'Minecraft',
                'dir' => '/',
                'files' => [
                    [
                        'label' => 'Whitelist',
                        'path' => 'whitelist.json',
                        'format' => ListDefinition::FORMAT_JSON,
                        'key_field' => 'uuid',
                        'display_field' => 'name',
                        'entry_template' => '{"uuid": "{uuid}", "name": "{name}"}',
                        'hint' => 'Spielernamen eintragen, die UUID sucht das Panel selbst. Wirkt nur mit white-list=true in server.properties; ein laufender Server übernimmt Änderungen erst nach "whitelist reload" oder einem Neustart.',
                    ],
                    [
                        'label' => 'Admins',
                        'path' => 'ops.json',
                        'format' => ListDefinition::FORMAT_JSON,
                        'key_field' => 'uuid',
                        'display_field' => 'name',
                        'entry_template' => '{"uuid": "{uuid}", "name": "{name}", "level": 4, "bypassesPlayerLimit": false}',
                        'hint' => 'Spielernamen eintragen. Neue Admins bekommen Level 4, also volle Rechte. Greift nach einem Neustart.',
                    ],
                    [
                        'label' => 'Bans',
                        'path' => 'banned-players.json',
                        'format' => ListDefinition::FORMAT_JSON,
                        'key_field' => 'uuid',
                        'display_field' => 'name',
                        'entry_template' => '{"uuid": "{uuid}", "name": "{name}", "created": "{now}", "source": "Server", "expires": "forever", "reason": "' . self::REASON . '"}',
                        'hint' => 'Spielernamen eintragen, die UUID sucht das Panel selbst. Greift nach einem Neustart.',
                    ],
                    [
                        'label' => 'IP bans',
                        'path' => 'banned-ips.json',
                        'format' => ListDefinition::FORMAT_JSON,
                        'key_field' => 'ip',
                        'display_field' => 'ip',
                        'entry_template' => '{"ip": "{ip}", "created": "{now}", "source": "Server", "expires": "forever", "reason": "' . self::REASON . '"}',
                        'hint' => 'Eine IP-Adresse pro Eintrag, z. B. 203.0.113.7. Greift nach einem Neustart.',
                    ],
                ],
            ],
            'valheim' => [
                'name' => 'Valheim',
                'dir' => 'savegames',
                'files' => [
                    ['label' => 'Admins', 'path' => 'savegames/adminlist.txt', 'hint' => self::VALHEIM_ID],
                    ['label' => 'Bans', 'path' => 'savegames/bannedlist.txt', 'hint' => self::VALHEIM_ID],
                    ['label' => 'Whitelist', 'path' => 'savegames/permittedlist.txt', 'hint' => self::VALHEIM_ID . ' Steht hier mindestens ein Eintrag, dürfen nur diese Spieler beitreten.'],
                ],
            ],
            // only who may reach ServerQuery - client bans and groups live in the database
            'teamspeak' => [
                'name' => 'TeamSpeak',
                'dir' => '/',
                'files' => [
                    ['label' => 'Query allowlist', 'path' => 'query_ip_allowlist.txt', 'hint' => 'Eine IP-Adresse pro Eintrag. Diese Adressen dürfen auf ServerQuery und sind vom Flood-Schutz ausgenommen. Betrifft nicht, wer auf den Voice-Server darf.'],
                    ['label' => 'Query denylist', 'path' => 'query_ip_denylist.txt', 'hint' => 'Eine IP-Adresse pro Eintrag. Diese Adressen werden von ServerQuery ausgesperrt. Betrifft nicht, wer auf den Voice-Server darf.'],
                ],
            ],
        ];
    }

    /** @return array<string, string> game => name, for the admin form's picker */
    public static function options(): array
    {
        return array_map(fn (array $preset) => $preset['name'], self::all());
    }

    /**
     * A preset's files in the shape the admin form stores, so picking one fills the repeater.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function formFiles(string $game): array
    {
        return array_map(fn (array $file) => $file + [
            'format' => ListDefinition::FORMAT_LINES,
            'key_field' => null,
            'display_field' => null,
            'entry_template' => null,
            'hint' => null,
        ], self::all()[$game]['files'] ?? []);
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
            $listings = [];

            foreach (self::all() as $game => $preset) {
                $names = $listings[$preset['dir']] ??= self::names($server, $preset['dir']);

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
