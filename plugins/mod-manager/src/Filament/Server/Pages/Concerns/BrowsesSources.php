<?php

namespace Marschux\ModManager\Filament\Server\Pages\Concerns;

use Filament\Notifications\Notification;
use Marschux\ModManager\Profiles\Presets;
use Marschux\ModManager\Sources\GameBanana;
use Marschux\ModManager\Sources\GoldSrc;
use Marschux\ModManager\Sources\Hangar;
use Marschux\ModManager\Sources\Modrinth;
use Marschux\ModManager\Sources\Source1;
use Marschux\ModManager\Sources\Source2;
use Marschux\ModManager\Sources\Thunderstore;
use Marschux\ModManager\Sources\Workshop;
use Throwable;

/** The browse tabs: sources, search, pages and the details of a mod. Part of ModsPage. */
trait BrowsesSources
{
    /** A new search starts at the first page again. */
    public function search(): void
    {
        $this->goToPage(1);
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, min($page, $this->pages ?: 1));
        $this->fetch();
    }

    public function showDetails(string $id): void
    {
        try {
            // from the installed tab it can be a package of another source than the one browsed
            $this->details = $this->sourceFor($id)?->details($id, $this->versionFilter());
        } catch (Throwable $exception) {
            report($exception);
            $this->details = null;
            // the modal opened with the click already
            $this->dispatch('close-modal', id: 'mod-details');

            Notification::make()->title(trans('mod-manager::mods.messages.details_failed'))->danger()->send();
        }
    }

    /** @return array<string, string> */
    public function categories(): array
    {
        try {
            return $this->source()?->categories() ?? [];
        } catch (Throwable) {
            return [];
        }
    }

    private function fetch(): void
    {
        if ($this->source() === null) {
            $this->items = [];

            return;
        }

        try {
            $result = $this->source()->search(trim($this->query), $this->category ?: null, $this->ordering, $this->page, $this->showClientSide, $this->showDeprecated, $this->versionFilter());
        } catch (Throwable $exception) {
            report($exception);
            $this->items = [];

            Notification::make()->title(trans('mod-manager::mods.messages.search_failed'))->danger()->send();

            return;
        }

        $this->items = $result['items'];
        $this->pages = $result['pages'];

        session([$this->sessionKey() => array_combine(self::REMEMBERED, array_map(fn (string $property) => $this->{$property}, self::REMEMBERED))]);
    }

    private function sessionKey(): string
    {
        return 'mod-manager.browse.' . $this->server()->id;
    }

    /** The source the browse tab shows: the one picked, else the profile's first. */
    public function sourceKey(): ?string
    {
        $sources = array_keys($this->sources());

        return in_array($this->source, $sources, true) ? $this->source : ($sources[0] ?? null);
    }

    /**
     * The browse tabs: one per source, one per kind where Modrinth offers several. Key "source" or
     * "source:kind", label what the tiles are and where they come from, e.g. "GameBanana Maps".
     *
     * @return array<string, string>
     */
    public function browseTabs(): array
    {
        $tabs = [];

        foreach ($this->sources() as $source => $name) {
            $whats = match (true) {
                $source === 'modrinth' && $this->jarMods() => collect(array_keys(Modrinth::KINDS))->mapWithKeys(fn (string $kind) => ["$source:$kind" => trans("mod-manager::mods.kinds.$kind")])->all(),
                $source === 'workshop' => [$source => trans('mod-manager::mods.tabs.maps'), "$source:collection" => trans('mod-manager::mods.tabs.collections')],
                $source === 'gamebanana' => [$source => trans('mod-manager::mods.tabs.maps')],
                $this->jarMods() => [$source => trans('mod-manager::mods.tabs.plugins')],
                default => [$source => trans('mod-manager::mods.tabs.mods')],
            };

            foreach ($whats as $key => $what) {
                $tabs[$key] = trans('mod-manager::mods.tabs.browse_from', ['source' => $name, 'what' => $what]);
            }
        }

        return $tabs;
    }

    /** Which browse tab is showing. */
    public function browseKey(): string
    {
        $source = (string) $this->sourceKey();

        return match (true) {
            $source === 'modrinth' && $this->jarMods() => "$source:$this->kind",
            $source === 'workshop' && $this->kind === 'collection' => 'workshop:collection',
            default => $source,
        };
    }

    /** Opens a browse tab; another source or kind has its own categories, so the search starts over. */
    public function browse(string $key): void
    {
        if (!isset($this->browseTabs()[$key])) {
            return;
        }

        $this->tab = 'browse';
        // the workshop's maps tab has no kind in its key
        [$source, $kind] = explode(':', $key) + [1 => $key === 'workshop' ? 'map' : $this->kind];

        if ($key !== $this->browseKey()) {
            $this->source = $source;
            $this->kind = $kind;
            $this->category = '';
            $this->search();
        } elseif ($this->items === []) {
            $this->fetch();
        }
    }

    public function sources(): array
    {
        return collect($this->profile()->sources())
            ->filter(fn (string $source) => isset(Presets::SOURCES[$source]))
            // the workshop is only browsed with a Steam Web API key; without one maps go in by id
            ->reject(fn (string $source) => $source === 'workshop' && $this->steamKey() === '')
            ->mapWithKeys(fn (string $source) => [$source => Presets::SOURCES[$source]])
            ->all();
    }

    /** The game version mods have to fit, null to show all. */
    private function versionFilter(): ?string
    {
        return $this->showAll ? null : ($this->gameVersion()['version'] ?? null);
    }

    private function source(): Thunderstore|Modrinth|Hangar|GameBanana|Workshop|null
    {
        $profile = $this->profile();

        return match ($this->sourceKey()) {
            'thunderstore' => new Thunderstore((string) $profile->setting('community'), (array) $profile->setting('hidden_packages', [])),
            'modrinth' => new Modrinth((array) $profile->setting('modrinth_loaders', []), $this->kinds() ? $this->kind : 'plugin'),
            'hangar' => new Hangar(),
            'gamebanana' => $this->gameBanana(),
            'workshop' => new Workshop($this->steamKey(), $this->kind === 'collection' ? 'collection' : 'map'),
            default => null,
        };
    }

    private function gameBanana(): GameBanana
    {
        return new GameBanana((int) $this->profile()->setting('gamebanana_game'), (int) $this->profile()->setting('gamebanana_category'));
    }

    /** The source a package belongs to, by its id. */
    private function sourceFor(string $id): Thunderstore|Modrinth|Hangar|GoldSrc|Source1|Source2|GameBanana|Workshop
    {
        $profile = $this->profile();

        return match (true) {
            $id === GoldSrc::PACKAGE, $id === GoldSrc::ENGINE => new GoldSrc(),
            $id === Source1::PACKAGE => new Source1(),
            $id === Source2::PACKAGE => new Source2(),
            str_starts_with($id, Workshop::PREFIX), str_starts_with($id, Workshop::COLLECTION) => new Workshop($this->steamKey()),
            str_starts_with($id, GameBanana::PREFIX) => $this->gameBanana(),
            Modrinth::kindOf($id) !== null => new Modrinth((array) $profile->setting('modrinth_loaders', []), (string) Modrinth::kindOf($id)),
            str_starts_with($id, Hangar::PREFIX) => new Hangar(),
            default => new Thunderstore((string) $profile->setting('community'), (array) $profile->setting('hidden_packages', [])),
        };
    }

    /** @return array<string, string> what the browse tab can show from Modrinth, kind => name - empty unless there is a choice */
    public function kinds(): array
    {
        if ($this->sourceKey() !== 'modrinth' || !$this->jarMods()) {
            return [];
        }

        return collect(array_keys(Modrinth::KINDS))->mapWithKeys(fn (string $kind) => [$kind => trans("mod-manager::mods.kinds.$kind")])->all();
    }
}
