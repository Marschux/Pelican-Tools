<?php

namespace Marschux\ModManager\Models;

use App\Models\Egg;
use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Marschux\ModManager\Lists\ListDefinition;

/**
 * A set of editable files for one game - its admin list, ban list and whitelist together.
 *
 * What those files look like is configuration rather than code: a path, a format, and for json
 * which field identifies an entry plus the shape a new one gets. A game nobody here has heard of
 * is added in the admin panel instead of in a class.
 *
 * @property int $id
 * @property string $name
 * @property array<int, array<string, mixed>> $files
 * @property Collection|Egg[] $eggs
 * @property int|null $eggs_count
 */
class AccessList extends Model
{
    // the access-lists plugin had "access_lists" - and drops it when uninstalled
    protected $table = 'mod_access_lists';

    protected $fillable = [
        'name',
        'files',
    ];

    protected $casts = [
        'files' => 'array',
    ];

    public function eggs(): BelongsToMany
    {
        return $this->belongsToMany(Egg::class, 'mod_access_list_egg', 'mod_access_list_id');
    }

    /**
     * Every list configured for this server's egg, in a stable order.
     *
     * A plain join rather than a relation on Egg: registering a dynamic relation on a core model
     * at boot fails silently when it does not take effect, and then nothing here works while
     * looking exactly like "no lists configured".
     *
     * @return Collection<int, static>
     */
    public static function forServer(Server $server): Collection
    {
        return static::query()
            ->join('mod_access_list_egg', 'mod_access_list_egg.mod_access_list_id', '=', 'mod_access_lists.id')
            ->where('mod_access_list_egg.egg_id', $server->egg_id)
            ->orderBy('mod_access_lists.id')
            ->select('mod_access_lists.*')
            ->get();
    }

    /**
     * Every file of every list for this server, keyed by "<list id>:<index>" - what the editor
     * offers to pick from.
     *
     * @return array<string, ListDefinition>
     */
    public static function definitionsFor(Server $server): array
    {
        $definitions = [];

        foreach (static::forServer($server) as $list) {
            foreach ($list->definitions() as $definition) {
                $definitions[$definition->key] = $definition;
            }
        }

        return $definitions;
    }

    /** @return ListDefinition[] */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->files ?? [] as $index => $file) {
            $definition = is_array($file) ? ListDefinition::fromArray("$this->id:$index", $file) : null;

            if ($definition) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }
}
