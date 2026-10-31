<?php

namespace Marschux\GameManager\Models;

use App\Models\Egg;
use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Marschux\GameManager\Lists\ListDefinition;
use Marschux\GameManager\Sources\GoldSrc;
use Marschux\GameManager\Sources\Source1;
use Marschux\GameManager\Sources\Source2;

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

    /** A profile without a mods folder holds only access lists (TeamSpeak). */
    public function hasMods(): bool
    {
        return (string) $this->setting('mods_dir') !== '';
    }

    /**
     * The access list files of this server's egg, keyed by "<profile id>:<index>" - what the
     * configs tab offers to pick from. Each entry comes from the admin form and is checked on the way in.
     *
     * @return array<string, ListDefinition>
     */
    public static function listsFor(Server $server): array
    {
        $profile = static::forServer($server);
        $definitions = [];

        foreach (array_values((array) $profile?->setting('lists', [])) as $index => $file) {
            $definition = is_array($file) ? ListDefinition::fromArray("$profile->id:$index", $file) : null;

            if ($definition) {
                $definitions[$definition->key] = $definition;
            }
        }

        return $definitions;
    }
}
