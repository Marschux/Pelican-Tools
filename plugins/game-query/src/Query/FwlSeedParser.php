<?php

namespace Marschux\GameQuery\Query;

/**
 * Reads the world seed out of a Valheim world metadata file.
 *
 * Layout, all little endian: int32 data length, int32 world version, then two strings each
 * prefixed by a single length byte - the world name, then the seed. Kept dependency-free so
 * it can be checked without a running panel.
 */
class FwlSeedParser
{
    /** Anything longer is treated as a failed parse rather than a seed. */
    private const MAX_STRING = 64;

    public static function parse(string $content): ?string
    {
        // 8 header bytes, a length byte, at least one name byte, a length byte, one seed byte
        if (strlen($content) < 12) {
            return null;
        }

        $nameLength = ord($content[8]);

        if ($nameLength < 1 || $nameLength > self::MAX_STRING) {
            return null;
        }

        $seedLengthOffset = 9 + $nameLength;

        if (strlen($content) < $seedLengthOffset + 2) {
            return null;
        }

        $seedLength = ord($content[$seedLengthOffset]);

        if ($seedLength < 1 || $seedLength > self::MAX_STRING) {
            return null;
        }

        $seed = substr($content, $seedLengthOffset + 1, $seedLength);

        // a real seed is printable ascii; anything else means the layout did not match, which
        // is what happens on a 1.0 file whose header differs - better nothing than a wrong seed
        return strlen($seed) === $seedLength && preg_match('/^[\x20-\x7e]+$/', $seed) === 1 ? $seed : null;
    }
}
