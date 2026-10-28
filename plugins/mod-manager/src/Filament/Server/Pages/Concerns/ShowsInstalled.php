<?php

namespace Marschux\ModManager\Filament\Server\Pages\Concerns;

use Filament\Support\Colors\Color;
use Illuminate\Database\Eloquent\Collection;
use Marschux\ModManager\Installer;
use Marschux\ModManager\Models\ModInstall;
use Marschux\ModManager\Profiles\Presets;
use Marschux\ModManager\Sources\GameBanana;
use Marschux\ModManager\Sources\GitHub;
use Marschux\ModManager\Sources\GoldSrc;
use Marschux\ModManager\Sources\Hangar;
use Marschux\ModManager\Sources\Modrinth;
use Marschux\ModManager\Sources\Source1;
use Marschux\ModManager\Sources\Source2;
use Marschux\ModManager\Sources\Workshop;

/** What the installed table shows: origin, type, versions, dependencies, the list for players. Part of ModsPage. */
trait ShowsInstalled
{
    public function playerList(): string
    {
        return $this->installer()->playerList();
    }

    /** The site a package comes from, by its id. */
    public function sourceName(string $id): string
    {
        return match (true) {
            $id === GoldSrc::PACKAGE => 'AMX Mod X',
            $id === Source1::PACKAGE => 'SourceMod',
            $id === Source2::PACKAGE => 'CounterStrikeSharp',
            str_starts_with($id, GameBanana::PREFIX) => Presets::SOURCES['gamebanana'],
            Modrinth::kindOf($id) !== null => Presets::SOURCES['modrinth'],
            str_starts_with($id, Hangar::PREFIX) => Presets::SOURCES['hangar'],
            default => Presets::SOURCES['thunderstore'],
        };
    }

    /** The line below a name in the installed table: a mod's author, a plugin's source, a pack's kind. */
    public function origin(string $package): string
    {
        return match (true) {
            $package === GoldSrc::PACKAGE => trans('mod-manager::mods.by', ['author' => 'ReHLDS team, AlliedModders']),
            $package === GoldSrc::ENGINE => trans('mod-manager::mods.by', ['author' => 'ReHLDS team']),
            $package === Source1::PACKAGE => trans('mod-manager::mods.by', ['author' => 'AlliedModders']),
            $package === Source2::PACKAGE => trans('mod-manager::mods.by', ['author' => 'AlliedModders, roflmuffin']),
            Installer::uploaded($package), str_starts_with($package, Installer::MAP_UPLOAD) => trans('mod-manager::mods.upload.origin'),
            str_starts_with($package, GitHub::PREFIX) => trans('mod-manager::mods.by', ['author' => strtok(GitHub::parse($package)[0], '/')]),
            str_starts_with($package, Workshop::PREFIX) => 'Steam Workshop',
            str_starts_with($package, GameBanana::PREFIX), Installer::jarPackage($package) => $this->sourceName($package),
            default => trans('mod-manager::mods.by', ['author' => strtok($package, '-')]),
        };
    }

    /**
     * What a row is, for the type column: key of mods.types and its icon.
     *
     * @return array{0: string, 1: string}
     */
    public function type(string $package, bool $isLoader = false): array
    {
        return match (true) {
            $package === GoldSrc::ENGINE => ['engine', 'tabler-engine'],
            $isLoader => ['loader', 'tabler-plug'],
            Installer::mapPackage($package), str_starts_with($package, Workshop::PREFIX) => ['map', 'tabler-map'],
            Modrinth::kindOf($package) === 'datapack' => ['datapack', 'tabler-package'],
            Modrinth::kindOf($package) === 'resourcepack' => ['resourcepack', 'tabler-photo'],
            // jars of Paper and AMX Mod X's .amxx are plugins, Thunderstore packages mods
            Installer::uploaded($package), Installer::jarPackage($package), str_starts_with($package, GitHub::PREFIX) => ['plugin', 'tabler-puzzle'],
            default => ['mod', 'tabler-puzzle'],
        };
    }

    /** A type's badge colour: one hue each, none of the status colours (green, amber, red). */
    public function typeColor(string $type): array
    {
        return match ($type) {
            'loader' => Color::Sky,
            'engine' => Color::Orange,
            'map' => Color::Teal,
            'plugin' => Color::Violet,
            'datapack' => Color::Fuchsia,
            'resourcepack' => Color::Pink,
            default => Color::Indigo,
        };
    }

    /** @return array<string, string> package => newer version, worked out once per request */
    public function updates(): array
    {
        return once(fn () => $this->installer()->updates());
    }

    /** @return Collection<int, ModInstall> the mods, without the loader */
    public function installed(): Collection
    {
        // the GoldSrc engine has a row of its own, like the loader
        $hidden = [...(array) $this->profile()->setting('hidden_packages', []), GoldSrc::ENGINE];
        $installs = $this->installer()->installs()->reject(fn (ModInstall $install) => in_array($install->package, $hidden, true))->values();

        $this->installer()->fillSizes($installs);

        return $installs;
    }

    /**
     * Who needs whom, in names: what a mod requires and which mods require it. The loader is
     * left out - every mod needs it.
     *
     * @return array<string, array{requires: string[], missing: string[], needed_by: string[]}> package => names
     */
    public function relations(): array
    {
        $installs = $this->installed();
        $names = $installs->pluck('name', 'package')->all();
        $hidden = (array) $this->profile()->setting('hidden_packages', []);
        $relations = [];

        foreach ($installs as $install) {
            $relations[$install->package]['requires'] = array_values(array_map(
                fn (string $package) => $names[$package] ?? str_replace('_', ' ', substr($package, strpos($package, '-') + 1)),
                array_diff($install->requires, $hidden),
            ));
            // required, but not there or switched off - the mod will not load
            $relations[$install->package]['missing'] = array_values(array_map(
                fn (string $package) => $names[$package] ?? str_replace('_', ' ', substr($package, strpos($package, '-') + 1)),
                array_filter(array_diff($install->requires, $hidden), fn (string $package) => !$installs->contains(fn (ModInstall $other) => $other->package === $package && $other->enabled)),
            ));
            $relations[$install->package]['needed_by'] = $installs
                ->filter(fn (ModInstall $other) => in_array($install->package, $other->requires, true))
                ->pluck('name')
                ->all();
        }

        return $relations;
    }

    /** @return array<string, string> package => installed version, for the tiles and the details - the loader included */
    public function installedVersions(): array
    {
        return $this->installer()->installs()->pluck('version', 'package')->all();
    }
}
