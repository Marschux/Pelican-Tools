<?php

namespace Marschux\ModManager\Models;

use App\Models\Egg;
use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Which mod site a game uses and where its mods go, linked to the eggs that run that game.
 *
 * @property int $id
 * @property string $name
 * @property array<string, mixed> $settings  see Profiles\Presets for the keys
 * @property Collection|Egg[] $eggs
 */
class ModProfile extends Model
{
    protected $fillable = ['name', 'settings'];

    protected $casts = ['settings' => 'array'];

    public function eggs(): BelongsToMany
    {
        return $this->belongsToMany(Egg::class, 'mod_profile_egg')->withTimestamps();
    }

    /**
     * The profile for this server's egg. A plain join rather than a relation on Egg, the same
     * as access lists: a dynamic relation on a core model that silently fails to register
     * looks exactly like "no profile".
     */
    public static function forServer(Server $server): ?self
    {
        return static::query()
            ->join('mod_profile_egg', 'mod_profile_egg.mod_profile_id', '=', 'mod_profiles.id')
            ->where('mod_profile_egg.egg_id', $server->egg_id)
            ->select('mod_profiles.*')
            ->first();
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }
}
