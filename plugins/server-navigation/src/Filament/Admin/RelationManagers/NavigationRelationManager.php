<?php

namespace Marschux\ServerNavigation\Filament\Admin\RelationManagers;

use App\Filament\Server\Pages\Console;
use App\Models\Server;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Marschux\ServerNavigation\Navigation\NavigationUrl;
use Throwable;

/**
 * A tab next to allocations and databases: which sidebar entries this server shows.
 *
 * A form with a plain blade save button instead of the relation table - actions did not render in
 * this panel's own pages, and a switch list is what this is anyway.
 *
 * @property Server $ownerRecord
 */
class NavigationRelationManager extends RelationManager
{
    protected static string $relationship = 'hiddenNavigationItems';

    protected static string|BackedEnum|null $icon = 'tabler-layout-sidebar';

    protected string $view = 'server-navigation::relation-manager';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return trans('server-navigation::navigation.tab');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) user()?->can('update server', $ownerRecord);
    }

    public function mount(): void
    {
        parent::mount();

        $hidden = $this->ownerRecord->hiddenNavigationItems()->pluck('slug')->all();

        $this->form->fill([
            'visible' => array_values(array_diff(array_keys(static::entries()), $hidden)),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                CheckboxList::make('visible')
                    ->label(trans('server-navigation::navigation.visible'))
                    ->helperText(trans('server-navigation::navigation.visible_hint'))
                    ->options(static::entries())
                    ->bulkToggleable()
                    ->columns(3),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canViewForRecord($this->ownerRecord, $this->getPageClass()), 403);

        $visible = $this->form->getState()['visible'] ?? [];
        $hidden = array_diff(array_keys(static::entries()), $visible);

        $this->ownerRecord->hiddenNavigationItems()->delete();
        $this->ownerRecord->hiddenNavigationItems()->createMany(array_map(fn (string $slug) => ['slug' => $slug], $hidden));

        Notification::make()->title(trans('server-navigation::navigation.saved'))->success()->send();
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
