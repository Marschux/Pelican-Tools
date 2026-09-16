<?php

namespace Marschux\ServerNavigation\Navigation;

use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Navigation\NavigationManager;
use Marschux\ServerNavigation\Models\HiddenNavigationItem;

/**
 * Filament's navigation, with the entries hidden for the current server moved into a collapsed
 * group at the end. Only the sidebar - permissions and urls stay as they are.
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

        $hidden = HiddenNavigationItem::where('server_id', $server->id)->pluck('slug')->all();

        if ($hidden === []) {
            return $groups;
        }

        $isHidden = fn (NavigationItem $item) => in_array(NavigationUrl::slug((string) $item->getUrl(), $server->uuid_short), $hidden, true);
        $moved = [];

        // a group's items come back as a collection here, not the array getItems() promises
        $groups = collect($groups)
            ->map(function (NavigationGroup $group) use ($isHidden, &$moved) {
                [$hiddenItems, $items] = collect($group->getItems())->partition($isHidden);
                array_push($moved, ...$hiddenItems->all());

                return $group->items($items->all());
            })
            ->filter(fn (NavigationGroup $group) => $group->getItems() !== [])
            ->all();

        if ($moved !== []) {
            $groups[] = NavigationGroup::make(trans('server-navigation::navigation.more'))
                ->items($moved)
                ->collapsible()
                ->collapsed();
        }

        return $groups;
    }
}
