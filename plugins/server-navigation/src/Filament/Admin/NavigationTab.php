<?php

namespace Marschux\ServerNavigation\Filament\Admin;

use App\Filament\Server\Pages\Console;
use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;
use Marschux\ServerNavigation\Navigation\NavigationUrl;
use Throwable;

/**
 * The tab after "actions" on the admin's edit server page: the sidebar entries of this server in
 * their order, split by a divider row: above it shown directly, below it under "more". Saved with
 * the page's own save button.
 */
class NavigationTab
{
    /** The row between shown entries and those under "more" - no page has a slug like it. */
    private const DIVIDER = '__more__';

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
                    ])
                    ->compact()
                    ->schema([
                        Hidden::make('slug'),
                        Text::make(fn (Get $get) => $get('slug') === self::DIVIDER
                            ? trans('server-navigation::navigation.more')
                            : (static::entries()[$get('slug')] ?? $get('slug')))
                            ->badge(fn (Get $get) => $get('slug') === self::DIVIDER)
                            ->color(fn (Get $get) => $get('slug') === self::DIVIDER ? 'gray' : null)
                            ->icon(fn (Get $get) => $get('slug') === self::DIVIDER ? 'tabler-chevron-down' : null),
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
                        $visible = true;
                        $rows = [];

                        foreach (array_values($state ?? []) as $item) {
                            $slug = $item['slug'] ?? '';

                            if ($slug === self::DIVIDER) {
                                $visible = false;
                            } elseif (isset($known[$slug])) {
                                $rows[] = ['slug' => $slug, 'visible' => $visible, 'sort' => count($rows)];
                            }
                        }

                        $record->navigationEntries()->delete();
                        $record->navigationEntries()->createMany($rows);
                    }),
            ]);
    }

    /**
     * The shown entries, the divider, then the entries under "more" - each part in the stored
     * order. Entries the server has none stored for yet - a page a plugin added later - go last
     * among the shown ones.
     *
     * Keyed by uuid like the repeater's own items: reordering merges by key, and with plain
     * 0..n keys a move appended empty rows instead.
     *
     * @return array<string, array{slug: string}>
     */
    private static function state(?Server $record): array
    {
        $known = static::entries();
        $stored = $record?->navigationEntries()->orderBy('sort')->get()->filter(fn ($entry) => isset($known[$entry->slug])) ?? collect();

        $slugs = [
            ...$stored->where('visible', true)->pluck('slug')->all(),
            ...array_diff(array_keys($known), $stored->pluck('slug')->all()),
            self::DIVIDER,
            ...$stored->where('visible', false)->pluck('slug')->all(),
        ];

        $state = [];

        foreach ($slugs as $slug) {
            $state[(string) Str::uuid()] = ['slug' => $slug];
        }

        return $state;
    }

    /**
     * Every page of the server panel that has a sidebar entry, core and plugins alike, as
     * slug => label in the panel's own order, plus the admin link. The console stays out: it is where a
     * server opens.
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

        // not a page: the panel adds it for admins only, with its sort of 99
        $entries[NavigationUrl::ADMIN] = [99, trans('server/console.open_in_admin')];

        uasort($entries, fn (array $a, array $b) => $a <=> $b);

        return array_map(fn (array $entry) => $entry[1], $entries);
    }
}
