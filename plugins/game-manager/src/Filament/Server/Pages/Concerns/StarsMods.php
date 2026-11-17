<?php

namespace Marschux\GameManager\Filament\Server\Pages\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Marschux\GameManager\Models\ModStar;

/** Stars on browse tiles: the user's own, per game, listed in the "Starred" pill of the add tab. */
trait StarsMods
{
    /** @return array<int, array<string, mixed>> the starred tiles, newest first */
    public function starredItems(): array
    {
        return once(fn () => $this->stars()->latest('id')->pluck('item')->all());
    }

    /** @return array<string, true> the starred tile ids, to fill a tile's star */
    public function starredIds(): array
    {
        return array_fill_keys(array_column($this->starredItems(), 'id'), true);
    }

    public function toggleStar(string $id): void
    {
        if ($this->stars()->where('package', $id)->delete() > 0) {
            return;
        }

        // only a tile the page listed, as it listed it
        $item = collect($this->items)->firstWhere('id', $id);

        if ($item !== null) {
            ModStar::create(['user_id' => user()->id, 'mod_profile_id' => $this->profile()->id, 'package' => $id, 'item' => $item]);
        }
    }

    private function stars(): Builder
    {
        return ModStar::query()->where('user_id', user()?->id)->where('mod_profile_id', $this->profile()->id);
    }
}
