<?php

namespace Marschux\GameQuery\Filament\Admin\Resources\GameQueries;

use App\Models\Server;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Marschux\GameQuery\Filament\Admin\Resources\GameQueries\Pages\ManageGameQueries;
use Marschux\GameQuery\Models\GameQuery;
use Marschux\GameQuery\Query\QueryResult;
use Marschux\GameQuery\Query\QueryTypeService;

class GameQueryResource extends Resource
{
    protected static ?string $model = GameQuery::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-device-desktop-search';

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
            ->columns([
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
                IconColumn::make('track_history')
                    ->label(trans('game-query::query.track_history'))
                    ->boolean(),
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

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('query_type')
                    ->label(trans('game-query::query.type'))
                    ->required()
                    ->options(fn (QueryTypeService $service) => $service->getMappings())
                    ->selectablePlaceholder(false)
                    ->preload()
                    ->searchable()
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
                    ->options(collect(QueryResult::FIELDS)->mapWithKeys(fn ($field) => [$field => trans("game-query::query.field.$field")])->all())
                    ->default(QueryResult::FIELDS)
                    ->bulkToggleable()
                    ->columns(3)
                    ->columnSpanFull(),
                Fieldset::make(trans('game-query::query.options'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('fetch_rules')
                            ->label(trans('game-query::query.fetch_rules'))
                            ->hintIcon('tabler-question-mark')
                            ->hintIconTooltip(trans('game-query::query.fetch_rules_hint'))
                            ->inline(false),
                        Toggle::make('track_history')
                            ->label(trans('game-query::query.track_history'))
                            ->hintIcon('tabler-question-mark')
                            ->hintIconTooltip(trans('game-query::query.track_history_hint'))
                            ->inline(false),
                    ]),
                Repeater::make('player_actions')
                    ->label(trans('game-query::query.player_actions'))
                    ->hintIcon('tabler-question-mark')
                    ->hintIconTooltip(trans('game-query::query.player_actions_hint'))
                    ->addActionLabel(trans('game-query::query.add_action'))
                    ->itemLabel(fn (array $state) => $state['label'] ?? null)
                    ->collapsed()
                    ->defaultItems(0)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('label')
                            ->label(trans('game-query::query.action_label'))
                            ->required(),
                        TextInput::make('command')
                            ->label(trans('game-query::query.action_command'))
                            ->placeholder('kick "{player}"')
                            ->helperText(trans('game-query::query.action_command_hint'))
                            ->required(),
                        TextInput::make('icon')
                            ->label(trans('game-query::query.action_icon'))
                            ->placeholder('tabler-user-x'),
                        Toggle::make('confirm')
                            ->label(trans('game-query::query.action_confirm'))
                            ->inline(false),
                    ])
                    ->columns(4),
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
