<?php

namespace Marschux\GameQuery\Query;

use Throwable;

class QueryTypeService
{
    /** @var QueryTypeSchemaInterface[] */
    private array $schemas = [];

    /** $id is nullable because the admin form calls this before a type has been picked yet. */
    public function get(?string $id): ?QueryTypeSchemaInterface
    {
        return $id === null ? null : ($this->schemas[$id] ?? null);
    }

    public function register(QueryTypeSchemaInterface $schema): void
    {
        $this->schemas[$schema->getId()] ??= $schema;
    }

    /** @return array<string, string> */
    public function getMappings(): array
    {
        return collect($this->schemas)->mapWithKeys(fn ($schema) => [$schema->getId() => $schema->getName()])->all();
    }

    /** Socket/http timeout for every query, in seconds. */
    public static function timeout(): int
    {
        return max(1, (int) config('game-query.timeout', 3));
    }

    /**
     * A failed query is the expected outcome for an offline or non-queryable server, so it is
     * only logged when debug is on - otherwise every polled server writes an exception every
     * time a widget refreshes.
     */
    public static function reportFailure(Throwable $exception): void
    {
        if (config('game-query.debug')) {
            report($exception);
        }
    }
}
