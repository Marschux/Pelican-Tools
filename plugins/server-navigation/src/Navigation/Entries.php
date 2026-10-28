<?php

namespace Marschux\ServerNavigation\Navigation;

use App\Filament\Server\Pages\Console;
use App\Models\Server;
use Filament\Facades\Filament;
use Throwable;

/**
 * The sidebar entries of a server's panel and their stored order. An order is a list of slugs with
 * the divider somewhere: above it shown directly, below it under "more".
 */
class Entries
{
    /** The row between shown entries and those under "more" - no page has a slug like it. */
    public const DIVIDER = '__more__';

    /**
     * Every page of the server panel that has a sidebar entry, core and plugins alike, as
     * slug => label in the panel's own order, plus the admin link. The console stays out: it is where a
     * server opens.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $panel = Filament::getPanel('server');
        $entries = [];

        foreach ([...$panel->getPages(), ...$panel->getResources()] as $class) {
            if ($class === Console::class) {
                continue;
            }

            $slug = NavigationUrl::firstSegment($class::getSlug($panel));

            try {
                // with a server at hand, only what its sidebar shows - a page for other eggs stays out
                if (!$class::shouldRegisterNavigation() || (Filament::getTenant() && !$class::canAccess())) {
                    continue;
                }

                $entries[$slug] = [$class::getNavigationSort() ?? 999, $class::getNavigationLabel()];
            } catch (Throwable) {
                // some entries ask for things a page only has while it runs
                $entries[$slug] ??= [999, class_basename($class)];
            }
        }

        // not a page: the panel adds it for admins only, with its sort of 99
        $entries[NavigationUrl::ADMIN] = [99, trans('server/console.open_in_admin')];

        uasort($entries, fn (array $a, array $b) => $a <=> $b);

        return array_map(fn (array $entry) => $entry[1], $entries);
    }

    /**
     * The shown entries, the divider, then the entries under "more" - each part in the stored
     * order. Entries the server has none stored for yet - a page a plugin added later - go last
     * among the shown ones.
     *
     * @return string[]
     */
    public static function order(Server $server): array
    {
        $known = static::all();
        $stored = $server->navigationEntries()->orderBy('sort')->get()->filter(fn ($entry) => isset($known[$entry->slug]));

        return [
            ...$stored->where('visible', true)->pluck('slug')->all(),
            ...array_values(array_diff(array_keys($known), $stored->pluck('slug')->all())),
            self::DIVIDER,
            ...$stored->where('visible', false)->pluck('slug')->all(),
        ];
    }

    /** @param  string[]  $order  slugs and the divider, as order() gives them */
    public static function save(Server $server, array $order): void
    {
        $known = static::all();
        $visible = true;
        $rows = [];

        foreach (array_values($order) as $slug) {
            if ($slug === self::DIVIDER) {
                $visible = false;
            } elseif (is_string($slug) && isset($known[$slug]) && !in_array($slug, array_column($rows, 'slug'), true)) {
                $rows[] = ['slug' => $slug, 'visible' => $visible, 'sort' => count($rows)];
            }
        }

        $server->navigationEntries()->delete();
        $server->navigationEntries()->createMany($rows);
    }
}
