<?php

namespace Marschux\GameManager\Filament\Server\Pages\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Marschux\GameManager\Models\ModStar;
use Throwable;

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

        // a tile the page listed, as it listed it, else the package's details: the open ones, or asked from its source (a row of the installed tab)
        $item = collect($this->items)->firstWhere('id', $id) ?? $this->tileOfDetails($id, ($this->details['id'] ?? null) === $id ? $this->details : null);

        if ($item === null) {
            try {
                $item = $this->tileOfDetails($id, $this->sourceFor($id)->details($id, $this->versionFilter()));
            } catch (Throwable $exception) {
                report($exception);
                Notification::make()->title(trans('game-manager::mods.messages.details_failed'))->danger()->send();
            }
        }

        if ($item !== null) {
            ModStar::create(['user_id' => user()->id, 'mod_profile_id' => $this->profile()->id, 'package' => $id, 'item' => $item]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @return array<string, mixed>|null the details as a tile: what the tile shows and the details have not, left empty
     */
    private function tileOfDetails(string $id, ?array $details): ?array
    {
        if ($details === null) {
            return null;
        }

        return [
            'id' => $id,
            'name' => $details['name'],
            'icon' => $details['icon'] ?? null,
            'author' => $details['author'] ?? '',
            'summary' => $details['summary'] ?? '',
            'downloads' => $details['downloads'] ?? 0,
            'rating' => $details['rating'] ?? 0,
            'updated' => $details['updated'] ?? null,
            'categories' => $details['categories'] ?? [],
            'deprecated' => $details['deprecated'] ?? false,
            'side' => $details['side'] ?? null,
            'url' => $details['url'] ?? null,
        ];
    }

    private function stars(): Builder
    {
        return ModStar::query()->where('user_id', user()?->id)->where('mod_profile_id', $this->profile()->id);
    }
}
