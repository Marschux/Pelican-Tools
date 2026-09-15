<?php

namespace Marschux\AccessLists\Lists;

use Illuminate\Support\Facades\Http;

/**
 * Names to uuids, for lists whose entries carry one.
 *
 * Minecraft is the only game here that needs this, but it lives with the list logic rather than
 * in a game class: which lists need a uuid is decided by their configured template, not by code.
 */
class MinecraftUuid
{
    /**
     * What an offline-mode server computes for a name: a version 3 uuid over
     * "OfflinePlayer:<name>". Asking Mojang instead would hand back the uuid of the real account
     * with that name, which such a server never uses.
     */
    public static function offline(string $name): string
    {
        $hash = md5('OfflinePlayer:' . $name, true);
        $hash[6] = chr(ord($hash[6]) & 0x0f | 0x30);
        $hash[8] = chr(ord($hash[8]) & 0x3f | 0x80);

        return self::dashed(bin2hex($hash)) ?? '';
    }

    /** Null when Mojang does not know the name; throws only when Mojang cannot be reached. */
    public static function lookup(string $name, int $timeout): ?string
    {
        $response = Http::timeout($timeout)->get('https://api.mojang.com/users/profiles/minecraft/' . rawurlencode($name));

        $id = $response->json('id');

        return is_string($id) ? self::dashed($id) : null;
    }

    /** The dashed uuid when someone typed one instead of a name, with or without dashes. */
    public static function fromInput(string $value): ?string
    {
        // lowercase like the uuids Mojang and the server itself write
        return self::dashed(strtolower(str_replace('-', '', trim($value))));
    }

    /** Null when Mojang has no account with this uuid; throws only when Mojang cannot be reached. */
    public static function nameFor(string $uuid, int $timeout): ?string
    {
        $response = Http::timeout($timeout)->get('https://sessionserver.mojang.com/session/minecraft/profile/' . str_replace('-', '', $uuid));

        $name = $response->json('name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** Mojang returns uuids without dashes; the files store them with. */
    public static function dashed(string $hex): ?string
    {
        if (preg_match('/^[0-9a-f]{32}$/i', $hex) !== 1) {
            return null;
        }

        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        ]);
    }

    /** True when the server derives uuids itself, read from its own server.properties. */
    public static function isOfflineMode(?string $serverProperties): bool
    {
        if ($serverProperties === null) {
            return false;
        }

        foreach (preg_split('/\R/', $serverProperties) as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'online-mode')) {
                return str_contains($line, '=') && trim(explode('=', $line, 2)[1]) === 'false';
            }
        }

        return false;
    }
}
