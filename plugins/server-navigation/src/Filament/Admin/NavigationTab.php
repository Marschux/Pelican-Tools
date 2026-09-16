<?php

namespace Marschux\ServerNavigation\Filament\Admin;

use App\Filament\Server\Pages\Console;
use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Marschux\ServerNavigation\Navigation\NavigationUrl;
use Throwable;

/**
 * The tab after "actions" on the admin's edit server page: the sidebar entries of this server in
 * their order, each shown directly or under "more". Saved with the page's own save button.
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
                Repeater::make('server_navigation')
                    ->hiddenLabel()
                    ->helperText(fn () => trans('server-navigation::navigation.hint'))
                    // one table row per entry instead of a card each
                    ->table([
                        TableColumn::make(fn () => trans('server-navigation::navigation.entry')),
                        TableColumn::make(fn () => trans('server-navigation::navigation.visible'))->width('1%'),
                    ])
                    ->schema([
                        Hidden::make('slug'),
                        Text::make(fn (Get $get) => static::entries()[$get('slug')] ?? $get('slug')),
                        Toggle::make('visible')->hiddenLabel(),
                    ])
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable()
                    ->reorderableWithButtons()
                    ->columnSpanFull()
                    // not a column of the server - kept out of its update, stored as rows instead
                    ->dehydrated(false)
                    // formatStateUsing, not afterStateHydrated: that would replace the repeater's own
                    // hook which gives every item its key
                    ->formatStateUsing(fn (?Server $record) => static::state($record))
                    ->saveRelationshipsUsing(function (Server $record, ?array $state) {
                        $known = static::entries();
                        $rows = [];

                        foreach (array_values($state ?? []) as $sort => $item) {
                            if (isset($known[$item['slug'] ?? ''])) {
                                $rows[] = ['slug' => $item['slug'], 'visible' => (bool) ($item['visible'] ?? true), 'sort' => $sort];
                            }
                        }

                        $record->navigationEntries()->delete();
                        $record->navigationEntries()->createMany($rows);
                    }),
            ]);
    }

    /**
     * What the server has stored, in its order, followed by entries it has none for yet - a page a
     * plugin added later - shown directly.
     *
     * @return array<int, array{slug: string, visible: bool}>
     */
    private static function state(?Server $record): array
    {
        $known = static::entries();
        $stored = $record?->navigationEntries()->orderBy('sort')->get()->filter(fn ($entry) => isset($known[$entry->slug])) ?? collect();

        $state = $stored->map(fn ($entry) => ['slug' => $entry->slug, 'visible' => $entry->visible])->values()->all();

        foreach (array_diff(array_keys($known), $stored->pluck('slug')->all()) as $slug) {
            $state[] = ['slug' => $slug, 'visible' => true];
        }

        return $state;
    }

    /**
     * Every page of the server panel that has a sidebar entry, core and plugins alike, as
     * slug => label in the panel's own order. The console stays out: it is where a server opens.
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

            $slug = NavigationUrl::firstSegment($class::getSlug($panel));

            try {
                if (!$class::shouldRegisterNavigation()) {
                    continue;
                }

                $entries[$slug] = [$class::getNavigationSort() ?? 999, $class::getNavigationLabel()];
            } catch (Throwable) {
                // some entries ask for the current server, which the admin panel has none of
                $entries[$slug] ??= [999, class_basename($class)];
            }
        }

        uasort($entries, fn (array $a, array $b) => $a <=> $b);

        return array_map(fn (array $entry) => $entry[1], $entries);
    }
}
