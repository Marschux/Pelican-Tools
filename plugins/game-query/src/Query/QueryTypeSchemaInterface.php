<?php

namespace Marschux\GameQuery\Query;

use App\Models\Server;

interface QueryTypeSchemaInterface
{
    public function getId(): string;

    public function getName(): string;

    /**
     * Query the server and map the protocol answer onto a QueryResult.
     *
     * Returns null when the server did not answer - that is the normal case for a stopped
     * server, not an error, so implementations must not let exceptions escape.
     */
    public function process(Server $server, string $ip, int $port, bool $withRules = false): ?QueryResult;
}
