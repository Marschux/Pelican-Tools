<?php

namespace Marschux\GameQuery\Models;

use App\Models\Allocation;
use App\Models\Egg;
use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;
use Marschux\GameQuery\Query\QueryResult;
use Marschux\GameQuery\Query\QueryTypeService;

/**
 * @property int $id
 * @property ?string $name
 * @property string $query_type
 * @property ?int $query_port_offset
 * @property ?string $query_port_variable
 * @property string[] $visible_fields
 * @property array<array{label: string, command: string, icon: ?string, confirm: bool}> $player_actions
 * @property bool $fetch_rules
 * @property bool $track_history
 * @property Collection|Egg[] $eggs
 * @property int|null $eggs_count
 */
class GameQuery extends Model
{
    protected $fillable = [
        'name',
        'query_type',
        'query_port_offset',
        'query_port_variable',
        'visible_fields',
        'player_actions',
        'fetch_rules',
        'track_history',
    ];

    protected $attributes = [
        'query_port_offset' => null,
        'query_port_variable' => null,
        'fetch_rules' => false,
        'track_history' => false,
    ];

    protected function casts(): array
    {
        return [
            'visible_fields' => 'array',
            'player_actions' => 'array',
            'fetch_rules' => 'bool',
            'track_history' => 'bool',
        ];
    }

    public function eggs(): BelongsToMany
    {
        return $this->belongsToMany(Egg::class);
    }

    /** Fields to show, defaulting to everything when nothing was configured yet. */
    public function visibleFields(): array
    {
        return $this->visible_fields ?: QueryResult::FIELDS;
    }

    public function shows(string $field): bool
    {
        return in_array($field, $this->visibleFields(), true);
    }

    /**
     * The widget, the players page, the server list column and the api all want the same
     * answer - without this cache each of them (and each open browser tab) opens its own
     * socket to the game server on its own polling interval.
     */
    public function runQuery(Server $server, bool $fresh = false): ?QueryResult
    {
        $ttl = (int) config('game-query.cache_ttl');
        $key = "game-query.$this->id.$server->uuid";

        if ($fresh) {
            Cache::forget($key);
        }

        if ($ttl < 1) {
            return $this->executeQuery($server);
        }

        // wrapped in an array because Cache::remember treats a null result as a miss and would
        // re-query every offline server on every poll - which is the expensive case, since an
        // unreachable server costs the full timeout
        return Cache::remember($key, $ttl, fn () => ['result' => $this->executeQuery($server)])['result'];
    }

    private function executeQuery(Server $server): ?QueryResult
    {
        if (!static::canRunQuery($server->allocation)) {
            return null;
        }

        $ip = static::resolveAddress($server->allocation);
        $ip = is_ipv6($ip) ? "[$ip]" : $ip;

        /** @var QueryTypeService $service */
        $service = app(QueryTypeService::class);

        return $service->get($this->query_type)?->process($server, $ip, $this->resolvePort($server), $this->fetch_rules);
    }

    private function resolvePort(Server $server): int
    {
        if ($this->query_port_variable) {
            $value = $server->variables()->where('env_variable', $this->query_port_variable)->first()?->server_value;

            if ($value && is_numeric($value)) {
                return (int) $value;
            }
        }

        return $server->allocation->port + ($this->query_port_offset ?? 0);
    }

    /**
     * Address the panel uses to reach the game server.
     *
     * "node" is the default because docker publishes the allocation port on the node host, so
     * it also works when the allocation ip is 0.0.0.0/:: or a lan address the panel cannot
     * reach directly - the case where querying the allocation ip simply fails.
     */
    public static function resolveAddress(Allocation $allocation): string
    {
        return match (config('game-query.address_source')) {
            'node' => gethostbyname($allocation->node->fqdn),
            'alias' => is_ip($allocation->alias) ? $allocation->alias : $allocation->ip,
            default => $allocation->ip,
        };
    }

    public static function canRunQuery(?Allocation $allocation): bool
    {
        if (!$allocation) {
            return false;
        }

        return !in_array(static::resolveAddress($allocation), ['0.0.0.0', '::'], true);
    }
}
