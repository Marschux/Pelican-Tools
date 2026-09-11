<?php

namespace Marschux\GameQuery\Models;

use App\Models\Allocation;
use App\Models\Egg;
use App\Models\Server;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property ?string $password_variable
 * @property string[] $visible_fields
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
        'password_variable',
        'visible_fields',
    ];

    protected $attributes = [
        'query_port_offset' => null,
        'query_port_variable' => null,
        'password_variable' => null,
    ];

    public function eggs(): BelongsToMany
    {
        return $this->belongsToMany(Egg::class);
    }

    /** @var array<int, ?self> memo per egg - several widgets ask for the same query per render */
    private static array $forEgg = [];

    /**
     * The query configured for this server's egg, or null.
     *
     * A plain join rather than a relation on Egg: adding one would mean registering a dynamic
     * relation on a core model at boot, and when that does not take effect everything silently
     * behaves as if no query were configured.
     */
    public static function forServer(Server $server): ?self
    {
        if (!array_key_exists($server->egg_id, static::$forEgg)) {
            static::$forEgg[$server->egg_id] = static::query()
                ->join('egg_game_query', 'egg_game_query.game_query_id', '=', 'game_queries.id')
                ->where('egg_game_query.egg_id', $server->egg_id)
                ->select('game_queries.*')
                ->first();
        }

        return static::$forEgg[$server->egg_id];
    }

    /**
     * The fields this query's protocol can actually report - e.g. Valheim has no map or VAC
     * status, no matter what is checked in the admin form - plus "password" once an admin has
     * pointed it at a startup variable. The password is never something a protocol reports, so
     * it is added here rather than in any schema's own supportedFields().
     */
    public function supportedFields(): array
    {
        /** @var QueryTypeService $service */
        $service = app(QueryTypeService::class);

        $fields = $service->get($this->query_type)?->supportedFields() ?? QueryResult::FIELDS;

        if ($this->password_variable) {
            $fields[] = 'password';
        }

        return $fields;
    }

    /**
     * Fields to show, defaulting to everything the protocol supports when nothing was
     * configured.
     *
     * Filtered on read against supportedFields() rather than in a setter: a setter would run
     * during mass assignment before query_type is necessarily set yet, and a field going away
     * from a protocol (or the protocol changing) would otherwise leave old rows with a stored
     * selection that no longer makes sense - reading it fresh here means that can't happen.
     */
    protected function visibleFields(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): array {
                $stored = json_decode($value ?? '[]', true);
                $allowed = $this->supportedFields();
                $valid = array_values(array_intersect(is_array($stored) ? $stored : [], $allowed));

                return $valid ?: $allowed;
            },
            set: fn (mixed $value): string => json_encode(array_values((array) $value)),
        );
    }

    public function shows(string $field): bool
    {
        return in_array($field, $this->visible_fields, true);
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

        $result = $service->get($this->query_type)?->process($server, $ip, $this->resolvePort($server));

        if ($result === null) {
            return null;
        }

        // a nullsafe call here would still evaluate plainPassword()'s db lookup even when
        // $result is null, since php evaluates arguments before the ?-> short-circuit
        return $result->withPassword($this->plainPassword($server));
    }

    /**
     * The literal connect password, read from a startup variable rather than the protocol -
     * no query answers with the actual password, only whether one is required at all.
     *
     * Mirrors the core Startup page's own rule exactly: a variable not flagged user-viewable
     * must not leak through here either, regardless of who else can already see this widget.
     * Whether the *current viewer* additionally holds the startup.read permission is checked
     * where this value is displayed, not here - this result is cached and shared across
     * viewers, so a per-viewer permission must never be baked into it.
     */
    public function plainPassword(Server $server): ?string
    {
        if (!$this->password_variable) {
            return null;
        }

        $variable = $server->variables()->where('env_variable', $this->password_variable)->first();

        if (!$variable || !$variable->user_viewable || !$variable->server_value) {
            return null;
        }

        return $variable->server_value;
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
