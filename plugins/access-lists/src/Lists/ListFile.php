<?php

namespace Marschux\AccessLists\Lists;

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
