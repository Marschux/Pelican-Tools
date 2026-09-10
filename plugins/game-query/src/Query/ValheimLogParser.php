<?php

namespace Marschux\GameQuery\Query;

/**
 * Pulls what Valheim reports about itself into its own console output.
 *
 * With crossplay enabled the server routes everything through the PlayFab relay and never
 * opens the udp ports locally, so no A2S query can reach it. It does however log its session
 * state, which is the only source of a player count in that mode.
 *
 * Kept dependency-free so it can be checked without a panel.
 */
class ValheimLogParser
{
    /** "Session "Name" with join code 123456 and IP 1.2.3.4:2456 is active with 2 player(s)" */
    private const SESSION = '/Session "(?<name>.*)" with join code (?<code>\d+) and IP \S+ is active with (?<players>\d+) player/';

    /** the line the server prints when it first registers, without a player count */
    private const REGISTERED = '/Session "(?<name>.*)" registered with join code (?<code>\d+)/';

    /**
     * @param  string[]  $lines
     */
    public static function players(array $lines): ?int
    {
        $match = ConsoleLog::lastMatch($lines, self::SESSION);

        return $match === null ? null : (int) $match['players'];
    }

    /**
     * @param  string[]  $lines
     */
    public static function hostname(array $lines): ?string
    {
        foreach ([self::SESSION, self::REGISTERED] as $pattern) {
            $match = ConsoleLog::lastMatch($lines, $pattern);

            if ($match !== null && $match['name'] !== '') {
                return QueryResult::plain($match['name']);
            }
        }

        return null;
    }

    /**
     * The crossplay join code players need instead of an ip.
     *
     * @param  string[]  $lines
     */
    public static function joinCode(array $lines): ?string
    {
        foreach ([self::SESSION, self::REGISTERED] as $pattern) {
            $match = ConsoleLog::lastMatch($lines, $pattern);

            if ($match !== null) {
                return $match['code'];
            }
        }

        return null;
    }
}
