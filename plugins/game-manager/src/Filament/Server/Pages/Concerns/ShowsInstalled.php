<?php

namespace Marschux\GameManager\Filament\Server\Pages\Concerns;

use Filament\Support\Colors\Color;
use Illuminate\Database\Eloquent\Collection;
use Marschux\GameManager\Installer;
use Marschux\GameManager\Models\ModInstall;
use Marschux\GameManager\Sources\GameBanana;
use Marschux\GameManager\Sources\GitHub;
use Marschux\GameManager\Sources\GoldSrc;
use Marschux\GameManager\Sources\Hexium;
use Marschux\GameManager\Sources\Modrinth;
use Marschux\GameManager\Sources\Source1;
use Marschux\GameManager\Sources\Source2;
use Marschux\GameManager\Sources\Thunderstore;
use Marschux\GameManager\Sources\Workshop;

/** What the installed table shows: origin, type, versions, dependencies, the list for players. Part of ModsPage. */
trait ShowsInstalled
{
    /**
     * The installed tab's pills, key => [label, icon, empty icon, empty text], each only on a game
     * that has such things or where some are installed already. Paper has no mods: plugins,
     * datapacks, resource packs.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public function sections(): array
    {
        return once(function () {
            $kinds = $this->installer()->installs()->map(fn ($install) => $this->type($install->package)[0]);
            $jars = $this->jarMods();

            return array_filter([
                'mods' => !$jars || $kinds->contains('mod') ? ['game-manager::mods.tabs.mods', 'tabler-puzzle', 'tabler-package-off', 'game-manager::mods.nothing_installed'] : null,
                'plugins' => $this->archiveLoader() || $jars || $kinds->contains('plugin') ? ['game-manager::mods.tabs.plugins', 'tabler-plug', 'tabler-plug-off', 'game-manager::mods.nothing_plugins'] : null,
                'datapacks' => $jars || $kinds->contains('datapack') ? ['game-manager::mods.kinds.datapack', 'tabler-package', 'tabler-package-off', 'game-manager::mods.nothing_yet'] : null,
                'resourcepacks' => $jars || $kinds->contains('resourcepack') ? ['game-manager::mods.kinds.resourcepack', 'tabler-photo', 'tabler-photo-off', 'game-manager::mods.nothing_yet'] : null,
                'maps' => $this->archiveLoader() || $kinds->contains('map') ? ['game-manager::mods.tabs.maps', 'tabler-map', 'tabler-map-off', 'game-manager::mods.nothing_maps'] : null,
            ]);
        });
    }

    /** The pill shown: the one picked if this game has it, else its first. */
    public function section(): string
    {
        return isset($this->sections()[$this->section]) ? $this->section : (string) array_key_first($this->sections());
    }

    /** The pill an install shows under, see sections(). */
    public function sectionOf(string $package): string
    {
        return match ($this->type($package)[0]) {
            'plugin' => 'plugins',
            'map' => 'maps',
            'datapack' => 'datapacks',
            'resourcepack' => 'resourcepacks',
            default => 'mods',
        };
    }

    public function loadPlayerList(): void
    {
        $this->playerList = $this->installer()->playerList();
    }

    /** The site a package comes from, by its id - the external links name it. */
    public function sourceName(string $id): string
    {
        return Installer::SITES[Installer::sourceKey($id)][0] ?? trans('game-manager::mods.upload.origin');
    }

    /** The source column: the site a row came from, "Uploaded", or "Added externally" for what was put on the server from outside and taken over. */
    public function sourceOf(ModInstall $install): string
    {
        return $install->adopted ? trans('game-manager::mods.columns.external') : $this->sourceName($install->package);
    }

    /** The front page of the site in the source column - the site, not the mod's page; null for uploads and what came from outside. */
    public function sourceUrl(ModInstall $install): ?string
    {
        $site = Installer::SITES[Installer::sourceKey($install->package)] ?? null;

        return $install->adopted || $site === null ? null : str_replace('{community}', (string) $this->profile()->setting('community'), $site[1]);
    }

    /** The line below a name in the installed table: who made it - null where only the source is known, that has a column of its own. */
    public function origin(string $package): ?string
    {
        return match (true) {
            $package === GoldSrc::PACKAGE => trans('game-manager::mods.by', ['author' => 'ReHLDS team, AlliedModders']),
            $package === GoldSrc::ENGINE => trans('game-manager::mods.by', ['author' => 'ReHLDS team']),
            $package === GoldSrc::BOTS => trans('game-manager::mods.by', ['author' => 'YaPB team']),
            $package === Source1::PACKAGE => trans('game-manager::mods.by', ['author' => 'AlliedModders']),
            $package === Source2::PACKAGE => trans('game-manager::mods.by', ['author' => 'AlliedModders, roflmuffin']),
            Installer::uploaded($package), str_starts_with($package, Installer::MAP_UPLOAD), str_starts_with($package, Workshop::PREFIX),
            str_starts_with($package, GameBanana::PREFIX), Installer::jarPackage($package) => null,
            str_starts_with($package, GitHub::PREFIX) => trans('game-manager::mods.by', ['author' => strtok(GitHub::parse($package)[0], '/')]),
            default => trans('game-manager::mods.by', ['author' => strtok(Hexium::bare($package), '-')]),
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
            $package === GoldSrc::BOTS => ['plugin', 'tabler-robot'],
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
        if (!$this->updatesChecked) {
            return [];
        }

        return once(fn () => $this->installer()->updates());
    }

    /** Called by the page once it is shown: the update arrows come with the next render. */
    public function checkUpdates(): void
    {
        $this->updatesChecked = true;
    }

    /** @return Collection<int, ModInstall> the mods, without the loader */
    public function installed(): Collection
    {
        // the GoldSrc engine and bots have a row of their own, like the loader
        $hidden = [...(array) $this->profile()->setting('hidden_packages', []), GoldSrc::ENGINE, GoldSrc::BOTS];
        $installs = $this->installer()->installs()->reject(fn (ModInstall $install) => in_array($install->package, $hidden, true))->values();

        $this->installer()->fillSizes($installs);

        $key = match ($this->sortColumn) {
            'type' => fn (ModInstall $install) => trans('game-manager::mods.types.' . $this->type($install->package)[0]),
            'source' => fn (ModInstall $install) => $this->sourceOf($install),
            'version' => fn (ModInstall $install) => $install->version,
            'size' => fn (ModInstall $install) => (int) $install->size,
            'dependencies' => fn (ModInstall $install) => count($install->requires),
            // switched on first
            'status' => fn (ModInstall $install) => $install->enabled ? 0 : 1,
            default => fn (ModInstall $install) => $install->name,
        };

        // natural: version 1.10 after 1.9
        return $installs->sortBy($key, SORT_NATURAL | SORT_FLAG_CASE, $this->sortDesc)->values();
    }

    /** A click on a column header: sorts by it, a second click turns the order around. */
    public function sortInstalled(string $column): void
    {
        if (!isset(static::SORTABLE[$column])) {
            return;
        }

        $this->sortDesc = $this->sortColumn === $column && !$this->sortDesc;
        $this->sortColumn = $column;
        $this->remember();
    }

    /**
     * Who needs whom, in names: what a row requires and which rows require it - the loader too.
     *
     * @return array<string, array{requires: string[], missing: string[], needed_by: string[]}> package => names
     */
    public function relations(): array
    {
        // the loader's row included, it is what nearly everything requires
        $installs = $this->installer()->installs();
        // by identity: a Hexium package and its Thunderstore twin are one and the same mod
        $names = $installs->mapWithKeys(fn (ModInstall $install) => [$install->identity() => $install->name])->all();
        $enabled = $installs->filter->enabled->mapWithKeys(fn (ModInstall $install) => [$install->identity() => true])->all();
        $hidden = (array) $this->profile()->setting('hidden_packages', []);
        $name = fn (string $package) => $names[$package] ?? Thunderstore::displayName($package);
        $relations = [];
        $neededBy = [];

        foreach ($installs as $install) {
            $requires = $install->requiredIdentities();
            $relations[$install->package]['requires'] = array_map($name, $requires);
            // required, but not there or switched off - the mod will not load; a missing loader has a row of its own saying so
            $relations[$install->package]['missing'] = array_values(array_map($name, array_filter(array_diff($requires, $hidden), fn (string $package) => !isset($enabled[$package]))));

            foreach ($requires as $required) {
                $neededBy[$required][] = $install->name;
            }
        }

        foreach ($installs as $install) {
            $relations[$install->package]['needed_by'] = $neededBy[$install->identity()] ?? [];
        }

        return $relations;
    }

    /** @return array<string, string> package => installed version, for the tiles and the details - the loader included */
    public function installedVersions(): array
    {
        return $this->installer()->installs()->pluck('version', 'package')->all();
    }
}
