<?php

namespace Marschux\GameManager\Lists;

/**
 * One file inside an access list entry: where it is, how it is shaped, and how a new record in
 * it looks. Comes straight from the admin form, so everything is validated on the way in.
 */
readonly class ListDefinition
{
    public const FORMAT_LINES = 'lines';

    public const FORMAT_JSON = 'json';

    /** key = value settings, e.g. server.properties or a cvar config */
    public const FORMAT_PAIRS = 'pairs';

    public const DEFAULT_PAIR_TEMPLATE = '{key}={value}';

    public function __construct(
        public string $key,
        public string $label,
        public string $path,
        public string $format,
        public ?string $keyField,
        public ?string $displayField,
        public ?string $template,
        /** free text for whoever edits the list: what goes in, where to find it */
        public ?string $hint = null,
    ) {}

    /**
     * @param  array<string, mixed>  $file
     */
    public static function fromArray(string $key, array $file): ?self
    {
        $path = trim((string) ($file['path'] ?? ''));

        if ($path === '') {
            return null;
        }

        return new self(
            key: $key,
            label: trim((string) ($file['label'] ?? '')) ?: $path,
            path: $path,
            format: match ($file['format'] ?? self::FORMAT_LINES) {
                self::FORMAT_JSON => self::FORMAT_JSON,
                self::FORMAT_PAIRS => self::FORMAT_PAIRS,
                default => self::FORMAT_LINES,
            },
            keyField: self::nullable($file['key_field'] ?? null),
            displayField: self::nullable($file['display_field'] ?? null),
            template: self::nullable($file['entry_template'] ?? null),
            hint: self::nullable($file['hint'] ?? null),
        );
    }

    public function isJson(): bool
    {
        return $this->format === self::FORMAT_JSON;
    }

    public function isPairs(): bool
    {
        return $this->format === self::FORMAT_PAIRS;
    }

    /** The shape one setting has in the file, e.g. "{key}={value}" or '{key} "{value}"'. */
    public function pairTemplate(): string
    {
        return $this->isPairs() && str_contains((string) $this->template, '{key}') && str_contains((string) $this->template, '{value}')
            ? (string) $this->template
            : self::DEFAULT_PAIR_TEMPLATE;
    }

    /**
     * The shape entries of a line list have around the typed value, e.g. "banid 0 {value}".
     * Without it a line list holds whatever was typed, verbatim.
     */
    public function lineTemplate(): ?string
    {
        return $this->format === self::FORMAT_LINES && str_contains((string) $this->template, '{value}') ? $this->template : null;
    }

    /** The field shown and typed in the editor; falls back to the key field. */
    public function valueField(): string
    {
        return $this->displayField ?? $this->keyField ?? 'name';
    }

    /** @return array<string, mixed> the shape a new entry gets */
    public function templateArray(): array
    {
        $template = json_decode((string) $this->template, true);

        return is_array($template) ? $template : [];
    }

    /** A template asking for a uuid means the panel has to look one up before writing an entry. */
    public function needsUuid(): bool
    {
        return str_contains((string) $this->template, '{uuid}');
    }

    private static function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
