<?php

namespace Marschux\GameQuery\Query\Schemas;

use App\Models\Server;
use Marschux\GameQuery\Query\QueryResult;
use xPaw\SourceQuery\SourceQuery;

/** Half-Life 1 engine: Counter-Strike 1.6, Condition Zero, HL Deathmatch. */
class GoldSourceSchema extends SourceSchema
{
    public function getId(): string
    {
        return 'goldsrc';
    }

    public function getName(): string
    {
        return 'GoldSrc Engine (A2S) - CS 1.6, Condition Zero, HLDM';
    }

    public function process(Server $server, string $ip, int $port): ?QueryResult
    {
        return $this->run($ip, $port, SourceQuery::GOLDSOURCE);
    }
}
