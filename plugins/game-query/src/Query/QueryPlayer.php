<?php

namespace Marschux\GameQuery\Query;

/**
 * One player as reported by a query protocol.
 *
 * Everything but the name is optional, because what is available differs per protocol:
 * A2S gives score and playtime but no real id (just a counter), the Minecraft ping gives
 * a uuid but no score, and the Minecraft query gives names only.
 */
readonly class QueryPlayer
{
    public function __construct(
        public string $name,
        public ?int $score = null,
        public ?int $playtime = null,
        public ?string $id = null,
    ) {}
}
