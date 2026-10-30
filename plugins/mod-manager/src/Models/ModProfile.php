<?php

namespace Marschux\ModManager\Models;

use App\Models\Egg;
use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Marschux\ModManager\Sources\GoldSrc;
use Marschux\ModManager\Sources\Source1;
use Marschux\ModManager\Sources\Source2;

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

    /**
     * The loader's package id. An archive loader has a fixed one - the admin form hides the field
     * for those, so a profile saved through it has none stored.
     */
    public function loaderPackage(): string
    {
        return match ($this->setting('loader_type')) {
            'goldsrc' => GoldSrc::PACKAGE,
            'source1' => Source1::PACKAGE,
            'source2' => Source2::PACKAGE,
            default => (string) $this->setting('loader_package'),
        };
    }

    /** @return string[] */
    public function sources(): array
    {
        return array_values((array) $this->setting('sources', []));
    }
}
