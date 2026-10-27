<?php

namespace Marschux\ModManager\Filament\Admin\Resources\ModProfiles;

use App\Enums\CustomizationKey;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Marschux\ModManager\Filament\Admin\Resources\ModProfiles\Pages\ManageModProfiles;
use Marschux\ModManager\Models\ModProfile;
use Marschux\ModManager\Profiles\Presets;

class ModProfileResource extends Resource
{
    protected static ?string $model = ModProfile::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-puzzle';

    /** Next to Eggs and Servers, the same way access lists do it. */
    public static function getNavigationGroup(): ?string
    {
        if (user()?->getCustomization(CustomizationKey::TopNavigation) === 'topbar') {
            return null;
        }

        return trans('admin/dashboard.server');
    }

    public static function getNavigationLabel(): string
    {
        return trans_choice('mod-manager::mods.profile.label', 2);
    }

    public static function getModelLabel(): string
    {
        return trans_choice('mod-manager::mods.profile.label', 1);
    }

    public static function getPluralModelLabel(): string
    {
        return trans_choice('mod-manager::mods.profile.label', 2);
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count() ?: null;
    }

    public static function form(Schema $schema): Schema
    {
        $field = fn (string $key) => "mod-manager::mods.profile.fields.$key";

        return $schema->components([
            // only a starting point: fills the fields below and is not stored itself
            Select::make('preset')
                ->label(trans($field('preset')))
                ->helperText(trans($field('preset_hint')))
                ->options(Presets::options())
                ->dehydrated(false)
                ->live()
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                    if ($state === null) {
                        return;
                    }

                    $set('settings', Presets::all()[$state]['settings']);

                    if (blank($get('name'))) {
                        $set('name', Presets::options()[$state]);
                    }
                })
                ->columnSpanFull(),
            TextInput::make('name')
                ->label(trans($field('name')))
                ->required()
                ->maxLength(191),
            Select::make('eggs')
                ->label(trans($field('eggs')))
                ->helperText(trans($field('eggs_hint')))
                // an egg has one profile at most, so eggs taken by another profile are not offered
                ->relationship('eggs', 'name', fn ($query, ?ModProfile $record) => $query->whereNotIn(
                    'eggs.id',
                    DB::table('mod_profile_egg')->when($record, fn ($taken) => $taken->where('mod_profile_id', '!=', $record->id))->pluck('egg_id'),
                ))
                ->multiple()
                ->preload()
                ->searchable(),
            Section::make(trans($field('source_section')))
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('settings.sources')
                        ->label(trans($field('sources')))
                        ->helperText(trans($field('sources_hint')))
                        ->options(Presets::SOURCES)
                        ->multiple()
                        ->live()
                        ->required(),
                    TextInput::make('settings.community')
                        ->label(trans($field('community')))
                        ->helperText(trans($field('community_hint')))
                        ->required(fn (Get $get) => in_array('thunderstore', (array) $get('settings.sources'), true)),
                    TextInput::make('settings.mods_dir')
                        ->label(trans($field('mods_dir')))
                        ->required(),
                    TextInput::make('settings.config_dir')
                        ->label(trans($field('config_dir'))),
                    TextInput::make('settings.version_file')
                        ->label(trans($field('version_file')))
                        ->helperText(trans($field('version_file_hint'))),
                    TextInput::make('settings.version_variable')
                        ->label(trans($field('version_variable')))
                        ->helperText(trans($field('version_variable_hint'))),
                    TagsInput::make('settings.hidden_packages')
                        ->label(trans($field('hidden_packages')))
                        ->helperText(trans($field('hidden_packages_hint')))
                        ->columnSpanFull(),
                ]),
            Section::make(trans($field('loader_section')))
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('settings.loader_name')
                        ->label(trans($field('loader_name'))),
                    TextInput::make('settings.loader_package')
                        ->label(trans($field('loader_package')))
                        ->helperText(trans($field('loader_package_hint'))),
                    TextInput::make('settings.loader_check')
                        ->label(trans($field('loader_check')))
                        ->helperText(trans($field('loader_check_hint'))),
                    Textarea::make('settings.start_prefix')
                        ->label(trans($field('start_prefix')))
                        ->helperText(trans($field('start_prefix_hint')))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('eggs'))
            ->columns([
                ImageColumn::make('icon')
                    ->label('')
                    ->size(32)
                    ->state(fn (ModProfile $record) => $record->eggs->first()?->icon),
                TextColumn::make('name')
                    ->label(trans('mod-manager::mods.profile.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sources')
                    ->label(trans('mod-manager::mods.profile.fields.sources'))
                    ->badge()
                    ->state(fn (ModProfile $record) => array_map(fn (string $source) => Presets::SOURCES[$source] ?? $source, $record->sources())),
                TextColumn::make('eggs_count')
                    ->label(trans('mod-manager::mods.profile.fields.eggs'))
                    ->counts('eggs')
                    ->badge(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make()->createAnother(false),
            ])
            ->emptyStateIcon('tabler-puzzle')
            ->emptyStateDescription('')
            ->emptyStateHeading(trans('mod-manager::mods.profile.none'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageModProfiles::route('/'),
        ];
    }
}
