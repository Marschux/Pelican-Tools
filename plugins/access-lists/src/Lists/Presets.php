<?php

namespace Marschux\AccessLists\Lists;

/**
 * The lists that ship with the plugin, offered as templates in the admin form. They switch
 * nothing on by themselves: a server only gets lists once an entry is linked to its egg.
 */
class Presets
{
    private const REASON = 'Banned by an operator.';

    private const VALHEIM_ID = 'One ID per entry, with the platform prefix, e.g. Steam_76561197960287930. The ID shows up in the server log when the player connects.';

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
                        'hint' => 'Enter a player name or a UUID, the panel looks up the other one itself. Only applies with white-list=true in server.properties; a running server picks up changes only after "whitelist reload" or a restart.',
                    ],
                    [
                        'label' => 'Admins',
                        'path' => 'ops.json',
                        'format' => ListDefinition::FORMAT_JSON,
                        'key_field' => 'uuid',
                        'display_field' => 'name',
                        'entry_template' => '{"uuid": "{uuid}", "name": "{name}", "level": 4, "bypassesPlayerLimit": false}',
                        'hint' => 'Enter a player name or a UUID. New admins get level 4, i.e. full permissions. Takes effect after a restart.',
                    ],
                    [
                        'label' => 'Bans',
                        'path' => 'banned-players.json',
                        'format' => ListDefinition::FORMAT_JSON,
                        'key_field' => 'uuid',
                        'display_field' => 'name',
                        'entry_template' => '{"uuid": "{uuid}", "name": "{name}", "created": "{now}", "source": "Server", "expires": "forever", "reason": "' . self::REASON . '"}',
                        'hint' => 'Enter a player name or a UUID, the panel looks up the other one itself. Takes effect after a restart.',
                    ],
                    [
                        'label' => 'Settings',
                        'path' => 'server.properties',
                        'format' => ListDefinition::FORMAT_PAIRS,
                        'entry_template' => ListDefinition::DEFAULT_PAIR_TEMPLATE,
                        'hint' => 'The settings from server.properties. Comment lines in the file are kept. A running server only reads them on its next start.',
                    ],
                    [
                        'label' => 'IP bans',
                        'path' => 'banned-ips.json',
                        'format' => ListDefinition::FORMAT_JSON,
                        'key_field' => 'ip',
                        'display_field' => 'ip',
                        'entry_template' => '{"ip": "{ip}", "created": "{now}", "source": "Server", "expires": "forever", "reason": "' . self::REASON . '"}',
                        'hint' => 'One IP address per entry, e.g. 203.0.113.7. Takes effect after a restart.',
                    ],
                ],
            ],
            'valheim' => [
                'name' => 'Valheim',
                'files' => [
                    ['label' => 'Admins', 'path' => 'savegames/adminlist.txt', 'hint' => self::VALHEIM_ID],
                    ['label' => 'Bans', 'path' => 'savegames/bannedlist.txt', 'hint' => self::VALHEIM_ID],
                    ['label' => 'Whitelist', 'path' => 'savegames/permittedlist.txt', 'hint' => self::VALHEIM_ID . ' Once there is at least one entry, only these players may join.'],
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
                        'hint' => 'One SteamID per entry, e.g. STEAM_1:0:12345678. A running server picks up changes only after "exec banned_user.cfg" in the console or a restart.',
                    ],
                    [
                        'label' => 'IP bans',
                        'path' => 'game/csgo/cfg/banned_ip.cfg',
                        'entry_template' => 'addip 0 {value}',
                        'hint' => 'One IP address per entry, e.g. 203.0.113.7. A running server picks up changes only after "exec banned_ip.cfg" in the console or a restart.',
                    ],
                    [
                        'label' => 'server.cfg',
                        'path' => 'game/csgo/cfg/server.cfg',
                        'format' => ListDefinition::FORMAT_PAIRS,
                        'entry_template' => '{key} "{value}"',
                        'hint' => 'The cvars from server.cfg, e.g. mp_maxrounds or sv_alltalk. Comment lines are kept. Takes effect after "exec server.cfg" in the console or a restart.',
                    ],
                ],
            ],
            'csgo' => [
                'name' => 'Counter-Strike: Global Offensive',
                'files' => [
                    [
                        'label' => 'Bans',
                        'path' => 'csgo/cfg/banned_user.cfg',
                        'entry_template' => 'banid 0 {value}',
                        'hint' => 'One SteamID per entry, e.g. STEAM_0:0:12345678. The server writes this file itself on "writeid", a running server picks up changes only after "exec banned_user.cfg" in the console or a restart.',
                    ],
                    [
                        'label' => 'IP bans',
                        'path' => 'csgo/cfg/banned_ip.cfg',
                        'entry_template' => 'addip 0 {value}',
                        'hint' => 'One IP address per entry, e.g. 203.0.113.7. The server writes this file itself on "writeip", a running server picks up changes only after "exec banned_ip.cfg" in the console or a restart.',
                    ],
                    [
                        'label' => 'server.cfg',
                        'path' => 'csgo/cfg/server.cfg',
                        'format' => ListDefinition::FORMAT_PAIRS,
                        'entry_template' => '{key} "{value}"',
                        'hint' => 'The cvars from server.cfg, e.g. mp_maxrounds or sv_alltalk. Comment lines are kept. Takes effect after "exec server.cfg" in the console or a restart.',
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
                        'hint' => 'One SteamID per entry, e.g. STEAM_0:0:12345678. The server writes this file itself on "writeid", a running server picks up changes only after "exec banned_user.cfg" in the console or a restart.',
                    ],
                    [
                        'label' => 'IP bans',
                        'path' => 'cstrike/cfg/banned_ip.cfg',
                        'entry_template' => 'addip 0 {value}',
                        'hint' => 'One IP address per entry, e.g. 203.0.113.7. The server writes this file itself on "writeip", a running server picks up changes only after "exec banned_ip.cfg" in the console or a restart.',
                    ],
                    [
                        'label' => 'server.cfg',
                        'path' => 'cstrike/cfg/server.cfg',
                        'format' => ListDefinition::FORMAT_PAIRS,
                        'entry_template' => '{key} "{value}"',
                        'hint' => 'The cvars from server.cfg, e.g. mp_timelimit or sv_alltalk. Comment lines are kept. Takes effect after "exec server.cfg" in the console or a restart.',
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
                        'hint' => 'One SteamID per entry, e.g. STEAM_0:0:12345678. HLDS writes this file itself on "writeid", a running server picks up changes only after "exec banned.cfg" in the console or a restart.',
                    ],
                    [
                        'label' => 'IP bans',
                        'path' => 'cstrike/listip.cfg',
                        'entry_template' => 'addip 0 {value}',
                        'hint' => 'One IP address per entry, e.g. 203.0.113.7. HLDS writes this file itself on "writeip", a running server picks up changes only after "exec listip.cfg" in the console or a restart.',
                    ],
                    [
                        'label' => 'server.cfg',
                        'path' => 'cstrike/server.cfg',
                        'format' => ListDefinition::FORMAT_PAIRS,
                        'entry_template' => '{key} "{value}"',
                        'hint' => 'The cvars from server.cfg, e.g. mp_timelimit or sv_alltalk. Comment lines are kept. Takes effect after "exec server.cfg" in the console or a restart.',
                    ],
                ],
            ],
            'cscz' => [
                'name' => 'Counter-Strike: Condition Zero',
                'files' => [
                    [
                        'label' => 'Bans',
                        'path' => 'czero/banned.cfg',
                        'entry_template' => 'banid 0 {value}',
                        'hint' => 'One SteamID per entry, e.g. STEAM_0:0:12345678. HLDS writes this file itself on "writeid", a running server picks up changes only after "exec banned.cfg" in the console or a restart.',
                    ],
                    [
                        'label' => 'IP bans',
                        'path' => 'czero/listip.cfg',
                        'entry_template' => 'addip 0 {value}',
                        'hint' => 'One IP address per entry, e.g. 203.0.113.7. HLDS writes this file itself on "writeip", a running server picks up changes only after "exec listip.cfg" in the console or a restart.',
                    ],
                    [
                        'label' => 'server.cfg',
                        'path' => 'czero/server.cfg',
                        'format' => ListDefinition::FORMAT_PAIRS,
                        'entry_template' => '{key} "{value}"',
                        'hint' => 'The cvars from server.cfg, e.g. mp_timelimit or sv_alltalk. Comment lines are kept. Takes effect after "exec server.cfg" in the console or a restart.',
                    ],
                ],
            ],
            // the egg points -userdatapath at serverconfig/; the game has no ban or whitelist file
            'sotf' => [
                'name' => 'Sons of the Forest',
                'files' => [
                    ['label' => 'Owners', 'path' => 'serverconfig/ownerswhitelist.txt', 'hint' => 'One SteamID64 per entry, e.g. 76561197960287930. Owners get the admin console commands in game. Takes effect after a restart.'],
                ],
            ],
            // only who may reach ServerQuery - client bans and groups live in the database
            'teamspeak' => [
                'name' => 'TeamSpeak',
                'files' => [
                    ['label' => 'Query allowlist', 'path' => 'query_ip_allowlist.txt', 'hint' => 'One IP address per entry. These addresses may reach ServerQuery and are exempt from flood protection. Does not affect who may join the voice server.'],
                    ['label' => 'Query denylist', 'path' => 'query_ip_denylist.txt', 'hint' => 'One IP address per entry. These addresses are locked out of ServerQuery. Does not affect who may join the voice server.'],
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
