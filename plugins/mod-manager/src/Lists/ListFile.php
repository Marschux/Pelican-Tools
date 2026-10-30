<?php

namespace Marschux\ModManager\Lists;

/**
 * Reading and writing the two file shapes an access list can have.
 *
 * Dependency-free on purpose, so all of it can be checked without a panel: this is the part
 * where a mistake silently corrupts someone's ban list.
 */
class ListFile
{
    /**
     * The entries of a line list - one per line, without the comment header the games write.
     *
     * @return string[]
     */
    public static function lineEntries(string $content): array
    {
        $entries = [];

        foreach (self::lines($content) as $line) {
            if ($line !== '' && !self::isComment($line)) {
                $entries[] = $line;
            }
        }

        return $entries;
    }

    /**
     * Rebuilds a line list, keeping the original comment lines as a header.
     *
     * @param  string[]  $entries
     */
    public static function buildLines(string $original, array $entries): string
    {
        $kept = [];

        foreach (self::lines($original) as $line) {
            if (self::isComment($line)) {
                $kept[] = $line;
            }
        }

        foreach ($entries as $entry) {
            $entry = trim($entry);

            if ($entry !== '' && !in_array($entry, $kept, true)) {
                $kept[] = $entry;
            }
        }

        return $kept === [] ? '' : implode("\n", $kept) . "\n";
    }

    /**
     * The entries of a json list, or null when the file is not a json array - which must never
     * be written back over, or whatever is really in there is gone.
     *
     * @return ?array<int, array<string, mixed>>
     */
    public static function jsonEntries(string $content): ?array
    {
        if (trim($content) === '') {
            return [];
        }

        $entries = json_decode($content, true);

        if (!is_array($entries)) {
            return null;
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                return null;
            }
        }

        return array_values($entries);
    }

    /**
     * What the form shows for a json list: one value per entry, taken from the display field.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return string[]
     */
    public static function displayValues(array $entries, string $field): array
    {
        $values = [];

        foreach ($entries as $entry) {
            $value = $entry[$field] ?? null;

            if (is_scalar($value) && (string) $value !== '') {
                $values[] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * Rebuilds a json list from the values the form holds.
     *
     * An entry that is still there keeps every field it had, including ones this plugin knows
     * nothing about - minecraft stores ban reasons and expiry dates in the same objects. Only
     * genuinely new values are built from the template.
     *
     * @param  array<int, array<string, mixed>>  $existing
     * @param  string[]  $values
     * @param  callable(string): ?array<string, mixed>  $make
     * @return array<int, array<string, mixed>>
     */
    public static function buildJson(array $existing, array $values, string $field, callable $make): array
    {
        $byValue = [];

        foreach ($existing as $entry) {
            $key = $entry[$field] ?? null;

            if (is_scalar($key)) {
                $byValue[strtolower((string) $key)] = $entry;
            }
        }

        $entries = [];

        foreach ($values as $value) {
            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $entry = $byValue[strtolower($value)] ?? $make($value);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        // array_values, or a list with a hole in it encodes as a json object and the games
        // refuse to read it
        return array_values($entries);
    }

    /**
     * The settings of a key = value file, in file order.
     *
     * @return array<string, string>
     */
    public static function pairEntries(string $content, string $template): array
    {
        $pairs = [];

        foreach (self::lines($content) as $line) {
            $pair = self::parsePair($line, $template);

            if ($pair !== null) {
                $pairs[$pair[0]] = $pair[1];
            }
        }

        return $pairs;
    }

    /**
     * Rebuilds a key = value file: every line that is not a setting stays where it is - comments
     * in server.properties explain what the settings below them do, and a rewrite that drops them
     * loses that. Changed settings are written in place, removed ones disappear, new ones are
     * appended.
     *
     * @param  array<string, string>  $pairs
     */
    public static function buildPairs(string $original, array $pairs, string $template): string
    {
        $out = [];

        foreach (self::lines($original) as $line) {
            $pair = self::parsePair($line, $template);

            if ($pair === null) {
                $out[] = $line;

                continue;
            }

            // a key the editor no longer holds was deleted there
            if (array_key_exists($pair[0], $pairs)) {
                $out[] = self::buildPair($pair[0], $pairs[$pair[0]], $template);
                unset($pairs[$pair[0]]);
            }
        }

        // trailing blank lines of the original, or a gap left where a setting was removed, would
        // otherwise sit between the kept lines and the appended ones
        while ($out !== [] && end($out) === '') {
            array_pop($out);
        }

        foreach ($pairs as $key => $value) {
            $out[] = self::buildPair((string) $key, (string) $value, $template);
        }

        return $out === [] ? '' : implode("
", $out) . "
";
    }

    public static function buildPair(string $key, string $value, string $template): string
    {
        return strtr($template, ['{key}' => $key, '{value}' => $value]);
    }

    /**
     * Splits one line into key and value along the template, or null when the line is a comment,
     * empty, or simply not a setting.
     *
     * @return ?array{0: string, 1: string}
     */
    public static function parsePair(string $line, string $template): ?array
    {
        if ($line === '' || self::isComment($line)) {
            return null;
        }

        [$before, $between, $after] = self::pairParts($template);

        if ($before !== '' && !str_starts_with($line, $before)) {
            return null;
        }

        $rest = substr($line, strlen($before));
        $split = strpos($rest, $between);

        if ($between === '' || $split === false || $split === 0) {
            return null;
        }

        $key = trim(substr($rest, 0, $split));
        $value = substr($rest, $split + strlen($between));

        if ($after !== '') {
            if (!str_ends_with($value, $after)) {
                return null;
            }

            $value = substr($value, 0, strlen($value) - strlen($after));
        }

        return $key === '' ? null : [$key, trim($value)];
    }

    /** @return array{0: string, 1: string, 2: string} text before the key, between key and value, after the value */
    private static function pairParts(string $template): array
    {
        $key = strpos($template, '{key}');
        $value = strpos($template, '{value}');

        if ($key === false || $value === false || $value < $key) {
            return ['', '=', ''];
        }

        return [
            substr($template, 0, $key),
            substr($template, $key + strlen('{key}'), $value - $key - strlen('{key}')),
            substr($template, $value + strlen('{value}')),
        ];
    }

    /**
     * Wraps a typed value into a configured line shape, e.g. "76561..." into
     * "banid 0 76561..." for a source engine ban list. A value that already carries the shape is
     * left as it is, so editing an entry twice does not nest it.
     */
    public static function wrapLine(string $template, string $value): string
    {
        [$prefix, $suffix] = self::lineParts($template);

        return self::unwrapLine($template, $value) === $value && ($prefix !== '' || $suffix !== '')
            ? $prefix . $value . $suffix
            : $value;
    }

    /** The bare value inside a templated line, or the line itself when it does not fit the shape. */
    public static function unwrapLine(string $template, string $line): string
    {
        [$prefix, $suffix] = self::lineParts($template);

        if ($prefix !== '' && !str_starts_with(strtolower($line), strtolower($prefix))) {
            return $line;
        }

        if ($suffix !== '' && !str_ends_with(strtolower($line), strtolower($suffix))) {
            return $line;
        }

        $inner = substr($line, strlen($prefix), strlen($line) - strlen($prefix) - strlen($suffix));

        return trim($inner) === '' ? $line : trim($inner);
    }

    /** @return array{0: string, 1: string} */
    private static function lineParts(string $template): array
    {
        $position = strpos($template, '{value}');

        return $position === false
            ? ['', '']
            : [substr($template, 0, $position), substr($template, $position + strlen('{value}'))];
    }

    /**
     * Fills {name}, {uuid}, {ip} and {now} into a configured entry shape.
     *
     * @param  array<string, mixed>  $template
     * @param  array<string, string>  $replacements
     * @return array<string, mixed>
     */
    public static function fillTemplate(array $template, array $replacements): array
    {
        $filled = [];

        foreach ($template as $key => $value) {
            $filled[$key] = match (true) {
                is_string($value) => strtr($value, $replacements),
                is_array($value) => self::fillTemplate($value, $replacements),
                default => $value,
            };
        }

        return $filled;
    }

    public static function encodeJson(array $entries): string
    {
        return json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return string[] */
    private static function lines(string $content): array
    {
        return array_map(trim(...), preg_split('/\R/', $content));
    }

    private static function isComment(string $line): bool
    {
        return str_starts_with($line, '//') || str_starts_with($line, '#');
    }
}
