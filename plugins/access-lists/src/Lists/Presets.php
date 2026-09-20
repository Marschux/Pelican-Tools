<?php

namespace Marschux\AccessLists\Lists;

/**
 * The lists that ship with the plugin, offered as templates in the admin form. They switch
 * nothing on by themselves: a server only gets lists once an entry is linked to its egg.
 */
class Presets
{
    private const REASON = 'Banned by an operator.';

    private const VALHEIM_ID = 'Eine ID pro Eintrag, mit Plattform-Präfix, z. B. Steam_76561197960287930. Die ID steht im Server-Log, wenn sich der Spieler verbindet.';

    /**
     * @return array<string, array{name: string, files: array<int, array<string, mixed>>}>
     */
    public static function all(): array
    {
        return [
            'minecraft' => [
                'name' => 'Minecraft',
                'files' => [
                    [
                        'label' => 'Whitelist',
                        'path' => 'whitelist.json',
                        'format' => ListDefinition::FORMAT_JSON,
                        'key_field' => 'uuid',
                        'display_field' => 'name',
                        'entry_template' => '{"uuid": "{uuid}", "name": "{name}"}',
                        'hint' => 'Spielernamen oder UUID eintragen, das jeweils andere sucht das Panel selbst. Wirkt nur mit white-list=true in server.properties; ein laufender Server übernimmt Änderungen erst nach "whitelist reload" oder einem Neustart.',
                    ],
                    [
                        'label' => 'Admins',
                        'path' => 'ops.json',
                        'format' => ListDefinition::FORMAT_JSON,
                        'key_field' => 'uuid',
                        'display_field' => 'name',
                        'entry_template' => '{"uuid": "{uuid}", "name": "{name}", "level": 4, "bypassesPlayerLimit": false}',
                        'hint' => 'Spielernamen oder UUID eintragen. Neue Admins bekommen Level 4, also volle Rechte. Greift nach einem Neustart.',
                    ],
                    [
                        'label' => 'Bans',
                        'path' => 'banned-players.json',
                        'format' => ListDefinition::FORMAT_JSON,
                        'key_field' => 'uuid',
                        'display_field' => 'name',
                        'entry_template' => '{"uuid": "{uuid}", "name": "{name}", "created": "{now}", "source": "Server", "expires": "forever", "reason": "' . self::REASON . '"}',
                        'hint' => 'Spielernamen oder UUID eintragen, das jeweils andere sucht das Panel selbst. Greift nach einem Neustart.',
                    ],
                    [
                        'label' => 'Einstellungen',
                        'path' => 'server.properties',
                        'format' => ListDefinition::FORMAT_PAIRS,
                        'entry_template' => ListDefinition::DEFAULT_PAIR_TEMPLATE,
                        'hint' => 'Die Einstellungen aus server.properties. Kommentarzeilen der Datei bleiben erhalten. Ein laufender Server liest sie erst beim nächsten Start.',
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
                'files' => [
                    ['label' => 'Admins', 'path' => 'savegames/adminlist.txt', 'hint' => self::VALHEIM_ID],
                    ['label' => 'Bans', 'path' => 'savegames/bannedlist.txt', 'hint' => self::VALHEIM_ID],
                    ['label' => 'Whitelist', 'path' => 'savegames/permittedlist.txt', 'hint' => self::VALHEIM_ID . ' Steht hier mindestens ein Eintrag, dürfen nur diese Spieler beitreten.'],
                ],
            ],
            // the server rewrites both files itself on "writeid"/"writeip", so an edit here is
            // gone once someone bans in game without writing the list back
            'cs2' => [
                'name' => 'Counter-Strike 2',
                'files' => [
                    [
                        'label' => 'Bans',
                        'path' => 'game/csgo/cfg/banned_user.cfg',
                        'entry_template' => 'banid 0 {value}',
                        'hint' => 'Eine SteamID pro Eintrag, z. B. STEAM_1:0:12345678. Ein laufender Server übernimmt Änderungen erst nach "exec banned_user.cfg" in der Konsole oder einem Neustart.',
                    ],
                    [
                        'label' => 'IP bans',
                        'path' => 'game/csgo/cfg/banned_ip.cfg',
                        'entry_template' => 'addip 0 {value}',
                        'hint' => 'Eine IP-Adresse pro Eintrag, z. B. 203.0.113.7. Ein laufender Server übernimmt Änderungen erst nach "exec banned_ip.cfg" in der Konsole oder einem Neustart.',
                    ],
                    [
                        'label' => 'server.cfg',
                        'path' => 'game/csgo/cfg/server.cfg',
                        'format' => ListDefinition::FORMAT_PAIRS,
                        'entry_template' => '{key} "{value}"',
                        'hint' => 'Die cvars aus server.cfg, z. B. mp_maxrounds oder sv_alltalk. Kommentarzeilen bleiben erhalten. Wirksam nach "exec server.cfg" in der Konsole oder einem Neustart.',
                    ],
                ],
            ],
            'css' => [
                'name' => 'Counter-Strike: Source',
                'files' => [
                    [
                        'label' => 'Bans',
                        'path' => 'cstrike/cfg/banned_user.cfg',
                        'entry_template' => 'banid 0 {value}',
                        'hint' => 'Eine SteamID pro Eintrag, z. B. STEAM_0:0:12345678. Der Server schreibt diese Datei bei "writeid" selbst, ein laufender Server übernimmt Änderungen erst nach "exec banned_user.cfg" in der Konsole oder einem Neustart.',
                    ],
                    [
                        'label' => 'IP bans',
                        'path' => 'cstrike/cfg/banned_ip.cfg',
                        'entry_template' => 'addip 0 {value}',
                        'hint' => 'Eine IP-Adresse pro Eintrag, z. B. 203.0.113.7. Der Server schreibt diese Datei bei "writeip" selbst, ein laufender Server übernimmt Änderungen erst nach "exec banned_ip.cfg" in der Konsole oder einem Neustart.',
                    ],
                    [
                        'label' => 'server.cfg',
                        'path' => 'cstrike/cfg/server.cfg',
                        'format' => ListDefinition::FORMAT_PAIRS,
                        'entry_template' => '{key} "{value}"',
                        'hint' => 'Die cvars aus server.cfg, z. B. mp_timelimit oder sv_alltalk. Kommentarzeilen bleiben erhalten. Wirksam nach "exec server.cfg" in der Konsole oder einem Neustart.',
                    ],
                ],
            ],
            'cs16' => [
                'name' => 'Counter-Strike 1.6',
                'files' => [
                    [
                        'label' => 'Bans',
                        'path' => 'cstrike/banned.cfg',
                        'entry_template' => 'banid 0 {value}',
                        'hint' => 'Eine SteamID pro Eintrag, z. B. STEAM_0:0:12345678. HLDS schreibt diese Datei bei "writeid" selbst, ein laufender Server übernimmt Änderungen erst nach "exec banned.cfg" in der Konsole oder einem Neustart.',
                    ],
                    [
                        'label' => 'IP bans',
                        'path' => 'cstrike/listip.cfg',
                        'entry_template' => 'addip 0 {value}',
                        'hint' => 'Eine IP-Adresse pro Eintrag, z. B. 203.0.113.7. HLDS schreibt diese Datei bei "writeip" selbst, ein laufender Server übernimmt Änderungen erst nach "exec listip.cfg" in der Konsole oder einem Neustart.',
                    ],
                    [
                        'label' => 'server.cfg',
                        'path' => 'cstrike/server.cfg',
                        'format' => ListDefinition::FORMAT_PAIRS,
                        'entry_template' => '{key} "{value}"',
                        'hint' => 'Die cvars aus server.cfg, z. B. mp_timelimit oder sv_alltalk. Kommentarzeilen bleiben erhalten. Wirksam nach "exec server.cfg" in der Konsole oder einem Neustart.',
                    ],
                ],
            ],
            // only who may reach ServerQuery - client bans and groups live in the database
            'teamspeak' => [
                'name' => 'TeamSpeak',
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
}
