<?php

namespace Marschux\GameQuery\Query\Schemas;

use App\Models\Server;
use Marschux\GameQuery\Query\QueryResult;
use Marschux\GameQuery\Query\QueryTypeSchemaInterface;
use Marschux\GameQuery\Query\TeamSpeakWebQuery;

class TeamSpeakSchema implements QueryTypeSchemaInterface
{
    public function getId(): string
    {
        return 'teamspeak';
    }

    public function getName(): string
    {
        return 'TeamSpeak (WebQuery)';
    }

    public function process(Server $server, string $ip, int $port): ?QueryResult
    {
        $envelope = TeamSpeakWebQuery::call($server, $ip, $port, 'serverinfo');

        if ($envelope === null) {
            return null;
        }

        $ok = (int) ($envelope['status']['code'] ?? 1) === 0;

        if (!$ok) {
            // the server did answer, it just rejected the key (reinstalled, key deleted by hand,
            // ...) - drop it so the next poll mints a fresh one instead of failing forever
            TeamSpeakWebQuery::forgetApiKey($server);

            return null;
        }

        $info = $envelope['body'][0] ?? null;

        if (!is_array($info)) {
            return null;
        }

        return new QueryResult(
            hostname: (string) ($info['virtualserver_name'] ?? ''),
            // clientsonline counts query connections as clients, and this very request is one of
            // them - reporting it raw would show an empty server as permanently having one user
            players: max(0, (int) ($info['virtualserver_clientsonline'] ?? 0) - (int) ($info['virtualserver_queryclientsonline'] ?? 0)),
            maxPlayers: (int) ($info['virtualserver_maxclients'] ?? 0),
            version: $info['virtualserver_version'] ?? null,
            passworded: isset($info['virtualserver_flag_password']) ? (bool) $info['virtualserver_flag_password'] : null,
            reservedSlots: isset($info['virtualserver_reserved_slots']) ? (int) $info['virtualserver_reserved_slots'] : null,
        );
    }

    public function supportedFields(): array
    {
        // "passworded" and not "password": no startup variable carries a TeamSpeak server
        // password, so the yes/no tile is the only thing that can say anything here - unlike
        // Source/Valheim, where the masked real password makes it redundant. The order is the
        // display order; reserved slots ride along inside the player count rather than as a tile
        return ['hostname', 'version', 'passworded', 'players'];
    }
}
