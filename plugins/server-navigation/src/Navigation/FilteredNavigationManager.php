<?php

namespace Marschux\ServerNavigation\Navigation;

use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Navigation\NavigationManager;
use Marschux\ServerNavigation\Models\HiddenNavigationItem;

/**
 * Filament's navigation, minus the entries hidden for the current server. Only the sidebar -
 * a hidden page stays reachable by its url.
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

        return array_filter(array_map(function (NavigationGroup $group) use ($hidden, $server) {
            $items = array_filter(
                $group->getItems(),
                fn (NavigationItem $item) => !in_array(NavigationUrl::slug((string) $item->getUrl(), $server->uuid_short), $hidden, true),
            );

            return $items === [] ? null : $group->items($items);
        }, $groups));
    }
}
