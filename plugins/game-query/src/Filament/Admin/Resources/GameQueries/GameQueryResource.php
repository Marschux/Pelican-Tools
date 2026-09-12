<?php

namespace Marschux\GameQuery\Filament\Admin\Resources\GameQueries;

use App\Enums\CustomizationKey;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Marschux\GameQuery\Filament\Admin\Resources\GameQueries\Pages\ManageGameQueries;
use Marschux\GameQuery\Models\GameQuery;
use Marschux\GameQuery\Query\QueryResult;
use Marschux\GameQuery\Query\QueryTypeService;

class GameQueryResource extends Resource
{
    protected static ?string $model = GameQuery::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-device-desktop-search';

    /**
     * Mirrors what the core resources do, so this lands in the same "Server" group as Eggs,
     * Nodes and Servers instead of opening a second group with the same label. The topbar
     * check has to be copied too - in that mode the core entries report no group at all.
     */
    public static function getNavigationGroup(): ?string
    {
        if (user()?->getCustomization(CustomizationKey::TopNavigation) === 'topbar') {
            return null;
        }

        return trans('admin/dashboard.server');
    }

    public static function getNavigationLabel(): string
    {
        return trans_choice('game-query::query.query', 2);
    }

    public static function getModelLabel(): string
    {
        return trans_choice('game-query::query.query', 1);
    }

    public static function getPluralModelLabel(): string
    {
        return trans_choice('game-query::query.query', 2);
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count() ?: null;
    }

    public static function table(Table $table): Table
    {
        return $table
            // without this every row would load its eggs separately just to draw the icons
            ->modifyQueryUsing(fn (Builder $query) => $query->with('eggs'))
            ->columns([
                ImageColumn::make('icon')
                    ->label('')
                    // an entry can cover several eggs, so all their icons are stacked rather
                    // than picking one of them arbitrarily. Eggs without an icon yield null
                    // and are dropped instead of rendering a broken image.
                    ->state(fn (GameQuery $record) => $record->eggs->map(fn ($egg) => $egg->icon)->filter()->values()->all())
                    ->stacked()
                    ->limit(3)
                    ->limitedRemainingText()
                    ->size(28),
                TextColumn::make('name')
                    ->label(trans('game-query::query.name'))
                    // without a label the type is the only thing identifying a row
                    ->placeholder(trans('game-query::query.no_name'))
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('query_type')
                    ->label(trans('game-query::query.type'))
                    ->badge()
                    ->formatStateUsing(fn ($state, QueryTypeService $service) => $service->getMappings()[$state] ?? $state),
                TextColumn::make('query_port_offset')
                    ->label(trans('game-query::query.port_offset'))
                    ->placeholder(trans('game-query::query.no_offset')),
                TextColumn::make('query_port_variable')
                    ->label(trans('game-query::query.port_variable'))
                    ->placeholder(trans('game-query::query.no_variable'))
                    ->badge(),
                TextColumn::make('eggs.name')
                    ->label(trans('game-query::query.eggs'))
                    ->placeholder(trans('game-query::query.no_eggs'))
                    ->icon('tabler-eggs')
                    ->badge(),
            ])
            ->recordActions([
                Action::make('test')
                    ->label(trans('game-query::query.test'))
                    ->icon('tabler-plug-connected')
                    ->color('gray')
                    ->action(fn (GameQuery $record) => static::test($record)),
                ViewAction::make()
                    ->hidden(fn ($record) => static::getEditAuthorizationResponse($record)->allowed()),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->createAnother(false),
            ])
            ->emptyStateIcon('tabler-device-desktop-search')
            ->emptyStateDescription('')
            ->emptyStateHeading(trans('game-query::query.no_queries'));
    }

    /**
     * Runs the query against any server using one of the linked eggs, bypassing the cache.
     * The point is to see whether the port offset/variable actually hits a responding query
     * port - guessing that from the admin form alone is what wastes the most time.
     */
    protected static function test(GameQuery $record): void
    {
        $server = Server::query()->whereIn('egg_id', $record->eggs()->pluck('eggs.id'))->first();

        if (!$server) {
            Notification::make()
                ->title(trans('game-query::query.notifications.test_no_server'))
                ->warning()
                ->send();

            return;
        }

        $result = $record->runQuery($server, true);

        if (!$result) {
            Notification::make()
                ->title(trans('game-query::query.notifications.test_failed'))
                ->body(trans('game-query::query.notifications.test_failed_body', ['server' => $server->name]))
                ->danger()
                ->send();

            return;
        }

        $body = collect([
            $result->hostname,
            $result->players . ' / ' . $result->maxPlayers . ($result->bots ? " (+{$result->bots} bots)" : ''),
            $result->map,
            $result->version,
        ])->filter()->implode(' · ');

        Notification::make()
            ->title(trans('game-query::query.notifications.test_success', ['server' => $server->name]))
            ->body($body)
            ->success()
            ->send();
    }

    /**
     * Fields the current form state can offer: whatever the selected protocol supports, plus
     * "password" - always offered, same as GameQuery::supportedFields(). Read from live form
     * state (Get), not a saved model, since this runs while a query is still being created or
     * edited.
     */
    private static function allowedFields(Get $get, QueryTypeService $service): array
    {
        $fields = $service->get($get('query_type'))?->supportedFields() ?? QueryResult::FIELDS;
        $fields[] = 'password';

        return $fields;
    }

    /** Drops any checked field the newly selected type can no longer show. */
    private static function pruneVisibleFields(Set $set, Get $get, QueryTypeService $service): void
    {
        $allowed = static::allowedFields($get, $service);

        $set('visible_fields', array_values(array_intersect((array) $get('visible_fields'), $allowed)));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(trans('game-query::query.name'))
                    ->placeholder(trans('game-query::query.no_name'))
                    ->hintIcon('tabler-question-mark')
                    ->hintIconTooltip(trans('game-query::query.name_hint'))
                    ->maxLength(191)
                    ->nullable()
                    ->columnSpanFull(),
                Select::make('query_type')
                    ->label(trans('game-query::query.type'))
                    ->required()
                    ->options(fn (QueryTypeService $service) => $service->getMappings())
                    ->selectablePlaceholder(false)
                    ->preload()
                    ->searchable()
                    ->live()
                    // switching the type can make some checked fields invalid for it - e.g.
                    // valheim has no map or VAC, so a leftover checkmark from "Source" would
                    // silently persist without ever being able to show anything again
                    // $state (the field's own new value) is used rather than $get('query_type')
                    // here - within a field's own afterStateUpdated, $get() on that same field
                    // is not reliably updated yet at the point this callback runs
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get, QueryTypeService $service) {
                        static::pruneVisibleFields($set, $get, $service);

                        // valheim always queries one port above the allocation port - filling
                        // this in when nothing has been typed yet saves the most common lookup
                        if ($state === 'valheim' && !$get('query_port_offset')) {
                            $set('query_port_offset', 1);
                        }
                    })
                    ->columnSpanFull(),
                TextInput::make('query_port_offset')
                    ->label(trans('game-query::query.port_offset'))
                    ->placeholder(trans('game-query::query.no_offset'))
                    ->numeric()
                    ->nullable()
                    ->minValue(1)
                    ->maxValue(65535 - 1024)
                    ->hintIcon('tabler-question-mark')
                    ->hintIconTooltip(trans('game-query::query.port_offset_hint')),
                TextInput::make('query_port_variable')
                    ->label(trans('game-query::query.port_variable'))
                    ->placeholder(trans('game-query::query.no_variable'))
                    ->nullable()
                    ->hintIcon('tabler-question-mark')
                    ->hintIconTooltip(trans('game-query::query.port_variable_hint')),
                CheckboxList::make('visible_fields')
                    ->label(trans('game-query::query.visible_fields'))
                    ->hintIcon('tabler-question-mark')
                    ->hintIconTooltip(trans('game-query::query.visible_fields_hint'))
                    // only fields the selected protocol can actually report are offered -
                    // showing a "VAC" checkbox for valheim would just be a checkbox that lies
                    ->options(fn (Get $get, QueryTypeService $service) => collect(static::allowedFields($get, $service))
                        ->mapWithKeys(fn ($field) => [$field => trans("game-query::query.field.$field")])
                        ->all())
                    ->bulkToggleable()
                    ->columns(3)
                    ->columnSpanFull(),
                Select::make('eggs')
                    ->label(trans('game-query::query.eggs'))
                    ->relationship('eggs', 'name')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')
                    ->label(trans('game-query::query.name'))
                    ->placeholder(trans('game-query::query.no_name')),
                TextEntry::make('query_type')
                    ->label(trans('game-query::query.type')),
                TextEntry::make('query_port_offset')
                    ->label(trans('game-query::query.port_offset'))
                    ->placeholder(trans('game-query::query.no_offset'))
                    ->numeric(),
                TextEntry::make('eggs.name')
                    ->label(trans('game-query::query.eggs'))
                    ->placeholder(trans('game-query::query.no_eggs'))
                    ->badge()
                    ->columnSpanFull(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGameQueries::route('/'),
        ];
    }
}
