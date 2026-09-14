<?php

namespace Marschux\AccessLists\Filament\Admin\Resources\AccessLists;

use App\Enums\CustomizationKey;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Marschux\AccessLists\Filament\Admin\Resources\AccessLists\Pages\ManageAccessLists;
use Marschux\AccessLists\Models\AccessList;

class AccessListResource extends Resource
{
    protected static ?string $model = AccessList::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-list-check';

    /**
     * Mirrors what the core resources do, so this lands in the same "Server" group as Eggs and
     * Servers instead of opening a second group with the same label. The topbar check has to be
     * copied too - in that mode the core entries report no group at all.
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
        return trans_choice('access-lists::lists.list', 2);
    }

    public static function getModelLabel(): string
    {
        return trans_choice('access-lists::lists.list', 1);
    }

    public static function getPluralModelLabel(): string
    {
        return trans_choice('access-lists::lists.list', 2);
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count() ?: null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(trans('access-lists::lists.fields.name'))
                ->placeholder(trans('access-lists::lists.fields.name_placeholder'))
                ->required()
                ->maxLength(191),
            TextInput::make('path')
                ->label(trans('access-lists::lists.fields.path'))
                ->placeholder('savegames/adminlist.txt')
                ->hintIcon('tabler-question-mark')
                ->hintIconTooltip(trans('access-lists::lists.fields.path_hint'))
                ->required()
                ->maxLength(191),
            Select::make('format')
                ->label(trans('access-lists::lists.fields.format'))
                ->options([
                    AccessList::FORMAT_LINES => trans('access-lists::lists.formats.lines'),
                    AccessList::FORMAT_JSON => trans('access-lists::lists.formats.json'),
                ])
                ->default(AccessList::FORMAT_LINES)
                ->selectablePlaceholder(false)
                ->live()
                ->required(),
            TextInput::make('key_field')
                ->label(trans('access-lists::lists.fields.key_field'))
                ->placeholder('uuid')
                ->hintIcon('tabler-question-mark')
                ->hintIconTooltip(trans('access-lists::lists.fields.key_field_hint'))
                ->visible(fn (Get $get) => $get('format') === AccessList::FORMAT_JSON)
                ->required(fn (Get $get) => $get('format') === AccessList::FORMAT_JSON),
            TextInput::make('display_field')
                ->label(trans('access-lists::lists.fields.display_field'))
                ->placeholder('name')
                ->hintIcon('tabler-question-mark')
                ->hintIconTooltip(trans('access-lists::lists.fields.display_field_hint'))
                ->visible(fn (Get $get) => $get('format') === AccessList::FORMAT_JSON)
                ->required(fn (Get $get) => $get('format') === AccessList::FORMAT_JSON),
            Textarea::make('entry_template')
                ->label(trans('access-lists::lists.fields.entry_template'))
                ->placeholder('{"uuid": "{uuid}", "name": "{name}"}')
                ->helperText(trans('access-lists::lists.fields.entry_template_hint'))
                ->rows(4)
                ->visible(fn (Get $get) => $get('format') === AccessList::FORMAT_JSON)
                ->required(fn (Get $get) => $get('format') === AccessList::FORMAT_JSON)
                ->rule('json')
                ->columnSpanFull(),
            Select::make('eggs')
                ->label(trans('access-lists::lists.fields.eggs'))
                ->relationship('eggs', 'name')
                ->multiple()
                ->preload()
                ->searchable()
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(trans('access-lists::lists.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('path')
                    ->label(trans('access-lists::lists.fields.path'))
                    ->searchable(),
                TextColumn::make('format')
                    ->label(trans('access-lists::lists.fields.format'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => trans("access-lists::lists.formats.$state")),
                TextColumn::make('eggs_count')
                    ->label(trans('access-lists::lists.fields.eggs'))
                    ->counts('eggs')
                    ->badge(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make(),
            ])
            ->emptyStateIcon('tabler-list-check')
            ->emptyStateDescription('')
            ->emptyStateHeading(trans('access-lists::lists.no_lists'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAccessLists::route('/'),
        ];
    }
}
