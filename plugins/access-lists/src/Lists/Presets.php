<?php

namespace Marschux\AccessLists\Lists;

/**
 * The lists that ship with the plugin, offered as templates in the admin form. They switch
 * nothing on by themselves: a server only gets lists once an entry is linked to its egg.
 *
 * A preset's hint is a key into the "preset_hints" translations: the admin form puts the text in
 * the admin's language into the list, and hint() shows a preset text nobody changed in the
 * language of whoever reads it.
 */
class Presets
{
    private const REASON = 'Banned by an operator.';


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
                        'hint' => 'mc_whitelist',
                    ],
                    [
                        'label' => 'Admins',
                        'path' => 'ops.json',
                        'format' => ListDefinition::FORMAT_JSON,
                        'key_field' => 'uuid',
                        'display_field' => 'name',
                        'entry_template' => '{"uuid": "{uuid}", "name": "{name}", "level": 4, "bypassesPlayerLimit": false}',
                        'hint' => 'mc_ops',
                    ],
                    [
                        'label' => 'Bans',
                        'path' => 'banned-players.json',
                        'format' => ListDefinition::FORMAT_JSON,
                        'key_field' => 'uuid',
                        'display_field' => 'name',
                        'entry_template' => '{"uuid": "{uuid}", "name": "{name}", "created": "{now}", "source": "Server", "expires": "forever", "reason": "' . self::REASON . '"}',
                        'hint' => 'mc_bans',
                    ],
                    [
                        'label' => 'Settings',
                        'path' => 'server.properties',
                        'format' => ListDefinition::FORMAT_PAIRS,
                        'entry_template' => ListDefinition::DEFAULT_PAIR_TEMPLATE,
                        'hint' => 'mc_properties',
                    ],
                    [
                        'label' => 'IP bans',
                        'path' => 'banned-ips.json',
                        'format' => ListDefinition::FORMAT_JSON,
                        'key_field' => 'ip',
                        'display_field' => 'ip',
                        'entry_template' => '{"ip": "{ip}", "created": "{now}", "source": "Server", "expires": "forever", "reason": "' . self::REASON . '"}',
                        'hint' => 'mc_ip_bans',
                    ],
                ],
            ],
            'valheim' => [
                'name' => 'Valheim',
                'files' => [
                    ['label' => 'Admins', 'path' => 'savegames/adminlist.txt', 'hint' => 'valheim_id'],
                    ['label' => 'Bans', 'path' => 'savegames/bannedlist.txt', 'hint' => 'valheim_id'],
                    ['label' => 'Whitelist', 'path' => 'savegames/permittedlist.txt', 'hint' => 'valheim_whitelist'],
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
                        'hint' => 'cs2_bans',
                    ],
                    [
                        'label' => 'IP bans',
                        'path' => 'game/csgo/cfg/banned_ip.cfg',
                        'entry_template' => 'addip 0 {value}',
                        'hint' => 'cs2_ip_bans',
                    ],
                    [
                        'label' => 'server.cfg',
                        'path' => 'game/csgo/cfg/server.cfg',
                        'format' => ListDefinition::FORMAT_PAIRS,
                        'entry_template' => '{key} "{value}"',
                        'hint' => 'server_cfg_rounds',
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
                        'hint' => 'source_bans',
                    ],
                    [
                        'label' => 'IP bans',
                        'path' => 'csgo/cfg/banned_ip.cfg',
                        'entry_template' => 'addip 0 {value}',
                        'hint' => 'source_ip_bans',
                    ],
                    [
                        'label' => 'server.cfg',
                        'path' => 'csgo/cfg/server.cfg',
                        'format' => ListDefinition::FORMAT_PAIRS,
                        'entry_template' => '{key} "{value}"',
                        'hint' => 'server_cfg_rounds',
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
                        'hint' => 'source_bans',
                    ],
                    [
                        'label' => 'IP bans',
                        'path' => 'cstrike/cfg/banned_ip.cfg',
                        'entry_template' => 'addip 0 {value}',
                        'hint' => 'source_ip_bans',
                    ],
                    [
                        'label' => 'server.cfg',
                        'path' => 'cstrike/cfg/server.cfg',
                        'format' => ListDefinition::FORMAT_PAIRS,
                        'entry_template' => '{key} "{value}"',
                        'hint' => 'server_cfg_timelimit',
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
                        'hint' => 'goldsrc_bans',
                    ],
                    [
                        'label' => 'IP bans',
                        'path' => 'cstrike/listip.cfg',
                        'entry_template' => 'addip 0 {value}',
                        'hint' => 'goldsrc_ip_bans',
                    ],
                    [
                        'label' => 'server.cfg',
                        'path' => 'cstrike/server.cfg',
                        'format' => ListDefinition::FORMAT_PAIRS,
                        'entry_template' => '{key} "{value}"',
                        'hint' => 'server_cfg_timelimit',
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
                        'hint' => 'goldsrc_bans',
                    ],
                    [
                        'label' => 'IP bans',
                        'path' => 'czero/listip.cfg',
                        'entry_template' => 'addip 0 {value}',
                        'hint' => 'goldsrc_ip_bans',
                    ],
                    [
                        'label' => 'server.cfg',
                        'path' => 'czero/server.cfg',
                        'format' => ListDefinition::FORMAT_PAIRS,
                        'entry_template' => '{key} "{value}"',
                        'hint' => 'server_cfg_timelimit',
                    ],
                ],
            ],
            // only who may reach ServerQuery - client bans and groups live in the database
            'teamspeak' => [
                'name' => 'TeamSpeak',
                'files' => [
                    ['label' => 'Query allowlist', 'path' => 'query_ip_allowlist.txt', 'hint' => 'ts_query_allowlist'],
                    ['label' => 'Query denylist', 'path' => 'query_ip_denylist.txt', 'hint' => 'ts_query_denylist'],
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

    /** The languages the preset hints are translated into. */
    public const LANGUAGES = ['en', 'de', 'es'];

    /**
     * A list's hint for the reader: a preset hint in any language comes back in the current one,
     * anything written by hand stays as it is.
     */
    public static function hint(?string $hint): ?string
    {
        if ($hint === null) {
            return null;
        }

        foreach (self::LANGUAGES as $language) {
            $key = array_search($hint, (array) trans('access-lists::lists.preset_hints', [], $language), true);

            if ($key !== false) {
                return trans("access-lists::lists.preset_hints.$key");
            }
        }

        return $hint;
    }
}
