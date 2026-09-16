<?php

namespace Marschux\ServerNavigation\Navigation;

use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Navigation\NavigationManager;
use Marschux\ServerNavigation\Models\NavigationEntry;

/**
 * Filament's navigation in the order set for the current server, with the entries it does not show
 * directly moved into a collapsed group at the end. Only the sidebar - permissions and urls stay
 * as they are.
 */
class FilteredNavigationManager extends NavigationManager
{
    public function get(): array
    {
        $groups = parent::get();

        $server = Filament::getTenant();

        if (Filament::getCurrentPanel()?->getId() !== 'server' || !$server instanceof Server) {
            return $groups;
        }

        $entries = NavigationEntry::where('server_id', $server->id)->get()->keyBy('slug');

        if ($entries->isEmpty()) {
            return $groups;
        }

        $entryOf = fn (NavigationItem $item): ?NavigationEntry => $entries->get((string) NavigationUrl::slug((string) $item->getUrl(), $server->uuid_short));
        $moved = [];

        // a group's items come back as a collection here, not the array getItems() promises
        $groups = collect($groups)
            ->map(function (NavigationGroup $group) use ($entryOf, &$moved) {
                [$hidden, $items] = collect($group->getItems())->partition(fn (NavigationItem $item) => $entryOf($item)?->visible === false);
                array_push($moved, ...$hidden->all());

                return $group->items(static::reorder($items->values()->all(), $entryOf));
            })
            ->filter(fn (NavigationGroup $group) => $group->getItems() !== [])
            ->all();

        if ($moved !== []) {
            usort($moved, fn (NavigationItem $a, NavigationItem $b) => $entryOf($a)->sort <=> $entryOf($b)->sort);

            $groups[] = NavigationGroup::make(trans('server-navigation::navigation.more'))
                ->items($moved)
                ->collapsible()
                ->collapsed();
        }

        return $groups;
    }

    /**
     * Swaps the configured items among their own places: the console before them and the admin
     * link after them keep where they are, only who sits in between changes.
     *
     * @param  NavigationItem[]  $items
     * @return NavigationItem[]
     */
    private static function reorder(array $items, callable $entryOf): array
    {
        $slots = array_keys(array_filter($items, fn (NavigationItem $item) => $entryOf($item) !== null));

        $ordered = array_map(fn (int $slot) => $items[$slot], $slots);
        usort($ordered, fn (NavigationItem $a, NavigationItem $b) => $entryOf($a)->sort <=> $entryOf($b)->sort);

        foreach ($slots as $index => $slot) {
            $items[$slot] = $ordered[$index];
        }

        return $items;
    }
}
