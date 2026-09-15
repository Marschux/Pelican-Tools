<?php

namespace Marschux\AccessLists\Lists;

/**
 * One file inside an access list entry: where it is, how it is shaped, and how a new record in
 * it looks. Comes straight from the admin form, so everything is validated on the way in.
 */
readonly class ListDefinition
{
    public const FORMAT_LINES = 'lines';

    public const FORMAT_JSON = 'json';

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
            format: ($file['format'] ?? self::FORMAT_LINES) === self::FORMAT_JSON ? self::FORMAT_JSON : self::FORMAT_LINES,
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
