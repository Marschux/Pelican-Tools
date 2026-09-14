<?php

namespace Marschux\AccessLists\Models;

use App\Models\Egg;
use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One editable file on a game server - an admin list, a ban list, a whitelist.
 *
 * What a list looks like is configuration rather than code: a path, a format, and for json
 * files which field identifies an entry plus the shape a new one gets. That way a game nobody
 * here has heard of can be added in the admin panel instead of in a schema class.
 *
 * @property int $id
 * @property string $name
 * @property string $path
 * @property string $format
 * @property ?string $key_field
 * @property ?string $display_field
 * @property ?string $entry_template
 * @property Collection|Egg[] $eggs
 * @property int|null $eggs_count
 */
class AccessList extends Model
{
    public const FORMAT_LINES = 'lines';

    public const FORMAT_JSON = 'json';

    protected $fillable = [
        'name',
        'path',
        'format',
        'key_field',
        'display_field',
        'entry_template',
    ];

    public function eggs(): BelongsToMany
    {
        return $this->belongsToMany(Egg::class);
    }

    /**
     * Every list configured for this server's egg, in a stable order.
     *
     * A plain join rather than a relation on Egg: registering a dynamic relation on a core model
     * at boot fails silently when it does not take effect, and then nothing here works while
     * looking exactly like "no lists configured".
     *
     * @return Collection|static[]
     */
    public static function forServer(Server $server): Collection
    {
        return static::query()
            ->join('access_list_egg', 'access_list_egg.access_list_id', '=', 'access_lists.id')
            ->where('access_list_egg.egg_id', $server->egg_id)
            ->orderBy('access_lists.id')
            ->select('access_lists.*')
            ->get();
    }

    public function isJson(): bool
    {
        return $this->format === self::FORMAT_JSON;
    }

    /** @return array<string, mixed> the shape a new entry gets, or an empty array for line lists */
    public function template(): array
    {
        $template = json_decode((string) $this->entry_template, true);

        return is_array($template) ? $template : [];
    }

    /** A template asking for a uuid means the panel has to look one up before writing an entry. */
    public function needsUuid(): bool
    {
        return str_contains((string) $this->entry_template, '{uuid}');
    }
}
