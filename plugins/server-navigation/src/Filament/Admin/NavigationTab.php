<?php

namespace Marschux\ServerNavigation\Filament\Admin;

use App\Filament\Server\Pages\Console;
use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Components\Tabs\Tab;
use Marschux\ServerNavigation\Navigation\NavigationUrl;
use Throwable;

/**
 * The tab after "actions" on the admin's edit server page: which sidebar entries this server
 * shows directly. Saved with the page's own save button.
 */
class NavigationTab
{
    public static function make(): Tab
    {
        // labels as closures: this is built while the panel registers, before translations load
        return Tab::make('server-navigation')
            ->label(fn () => trans('server-navigation::navigation.tab'))
            ->icon('tabler-layout-sidebar')
            ->schema([
                CheckboxList::make('server_navigation_visible')
                    ->label(fn () => trans('server-navigation::navigation.visible'))
                    ->helperText(fn () => trans('server-navigation::navigation.visible_hint'))
                    ->options(fn () => static::entries())
                    ->bulkToggleable()
                    ->columns(3)
                    ->columnSpanFull()
                    // not a column of the server - kept out of its update, stored as rows instead
                    ->dehydrated(false)
                    ->afterStateHydrated(function (CheckboxList $component, ?Server $record) {
                        $hidden = $record?->hiddenNavigationItems()->pluck('slug')->all() ?? [];

                        $component->state(array_values(array_diff(array_keys(static::entries()), $hidden)));
                    })
                    ->saveRelationshipsUsing(function (Server $record, ?array $state) {
                        $hidden = array_diff(array_keys(static::entries()), $state ?? []);

                        $record->hiddenNavigationItems()->delete();
                        $record->hiddenNavigationItems()->createMany(array_map(fn (string $slug) => ['slug' => $slug], $hidden));
                    }),
            ]);
    }

    /**
     * Every page of the server panel that has a sidebar entry, core and plugins alike, as
     * slug => label. The console stays out: it is where a server opens.
     *
     * @return array<string, string>
     */
    public static function entries(): array
    {
        $panel = Filament::getPanel('server');
        $entries = [];

        foreach ([...$panel->getPages(), ...$panel->getResources()] as $class) {
            if ($class === Console::class) {
                continue;
            }

            try {
                if (!$class::shouldRegisterNavigation()) {
                    continue;
                }

                $entries[NavigationUrl::firstSegment($class::getSlug($panel))] = $class::getNavigationLabel();
            } catch (Throwable) {
                // some entries ask for the current server, which the admin panel has none of
                $entries[NavigationUrl::firstSegment($class::getSlug($panel))] ??= class_basename($class);
            }
        }

        asort($entries);

        return $entries;
    }
}
