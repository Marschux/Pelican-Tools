<?php

namespace Marschux\GameManager\Filament\Admin\Resources\ModProfiles;

use App\Enums\CustomizationKey;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
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
use Illuminate\Support\Str;
use Marschux\GameManager\Lists\ListDefinition;
use Marschux\GameManager\Lists\Presets as ListPresets;
use Marschux\GameManager\Filament\Admin\Resources\ModProfiles\Pages\ManageModProfiles;
use Marschux\GameManager\Models\ModProfile;
use Marschux\GameManager\Profiles\Presets;

class ModProfileResource extends Resource
{
    protected static ?string $model = ModProfile::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-puzzle';

    /** Next to Eggs and Servers. */
    public static function getNavigationGroup(): ?string
    {
        if (user()?->getCustomization(CustomizationKey::TopNavigation) === 'topbar') {
            return null;
        }

        return trans('admin/dashboard.server');
    }

    public static function getNavigationLabel(): string
    {
        return trans_choice('game-manager::mods.profile.label', 2);
    }

    public static function getModelLabel(): string
    {
        return trans_choice('game-manager::mods.profile.label', 1);
    }

    public static function getPluralModelLabel(): string
    {
        return trans_choice('game-manager::mods.profile.label', 2);
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count() ?: null;
    }

    public static function form(Schema $schema): Schema
    {
        $field = fn (string $key) => "game-manager::mods.profile.fields.$key";

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

                    $set('settings', Presets::all()[$state]['settings'] + ['lists' => collect(ListPresets::formFiles(Presets::all()[$state]['lists']))
                        ->map(fn (array $file) => ['hint' => $file['hint'] === null ? null : trans("game-manager::lists.preset_hints.{$file['hint']}")] + $file)
                        ->keyBy(fn () => (string) Str::uuid())
                        ->all()]);

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
                        ->required(fn (Get $get) => blank($get('settings.lists'))),
                    TextInput::make('settings.community')
                        ->label(trans($field('community')))
                        ->helperText(trans($field('community_hint')))
                        ->required(fn (Get $get) => in_array('thunderstore', (array) $get('settings.sources'), true)),
                    TagsInput::make('settings.modrinth_loaders')
                        ->label(trans($field('modrinth_loaders')))
                        ->helperText(trans($field('modrinth_loaders_hint')))
                        ->required(fn (Get $get) => in_array('modrinth', (array) $get('settings.sources'), true))
                        ->columnSpanFull(),
                    TextInput::make('settings.gamebanana_game')
                        ->label(trans($field('gamebanana_game')))
                        ->helperText(trans($field('gamebanana_game_hint')))
                        ->numeric()
                        ->visible(fn (Get $get) => in_array('gamebanana', (array) $get('settings.sources'), true))
                        ->required(fn (Get $get) => in_array('gamebanana', (array) $get('settings.sources'), true)),
                    TextInput::make('settings.gamebanana_category')
                        ->label(trans($field('gamebanana_category')))
                        ->helperText(trans($field('gamebanana_category_hint')))
                        ->numeric()
                        ->visible(fn (Get $get) => in_array('gamebanana', (array) $get('settings.sources'), true))
                        ->required(fn (Get $get) => in_array('gamebanana', (array) $get('settings.sources'), true)),
                    TextInput::make('settings.mods_dir')
                        ->label(trans($field('mods_dir')))
                        ->helperText(trans($field('mods_dir_hint')))
                        ->required(fn (Get $get) => filled($get('settings.sources'))),
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
                    Select::make('settings.loader_type')
                        ->label(trans($field('loader_type')))
                        ->helperText(trans($field('loader_type_hint')))
                        ->options(Presets::LOADER_TYPES)
                        ->placeholder(Presets::LOADER_TYPES['thunderstore'])
                        ->live(),
                    TextInput::make('settings.game_dir')
                        ->label(trans($field('game_dir')))
                        ->helperText(trans($field('game_dir_hint')))
                        ->visible(fn (Get $get) => in_array($get('settings.loader_type'), Presets::ARCHIVE_LOADERS, true))
                        ->required(fn (Get $get) => in_array($get('settings.loader_type'), Presets::ARCHIVE_LOADERS, true)),
                    TextInput::make('settings.loader_name')
                        ->label(trans($field('loader_name'))),
                    TextInput::make('settings.loader_package')
                        ->visible(fn (Get $get) => !in_array($get('settings.loader_type'), Presets::ARCHIVE_LOADERS, true))
                        ->label(trans($field('loader_package')))
                        ->helperText(trans($field('loader_package_hint'))),
                    TextInput::make('settings.loader_check')
                        ->label(trans($field('loader_check')))
                        ->helperText(trans($field('loader_check_hint'))),
                    Textarea::make('settings.start_prefix')
                        ->visible(fn (Get $get) => !in_array($get('settings.loader_type'), Presets::ARCHIVE_LOADERS, true))
                        ->label(trans($field('start_prefix')))
                        ->helperText(trans($field('start_prefix_hint')))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
            Section::make(trans($field('lists_section')))
                ->description(trans($field('lists_section_hint')))
                ->columnSpanFull()
                ->collapsible()
                ->schema([self::listFiles()]),
        ]);
    }

    /** The access list files: admins, bans, whitelists, settings - each a pill in the configs tab. */
    private static function listFiles(): Repeater
    {
        return Repeater::make('settings.lists')
            ->hiddenLabel()
            ->addActionLabel(trans('game-manager::lists.fields.add_file'))
            ->itemLabel(fn (array $state) => filled($state['path'] ?? null)
                ? trim(($state['label'] ?? '') . ' - ' . $state['path'], ' -')
                : null)
            ->collapsed()
            ->defaultItems(0)
            ->reorderable()
            ->columns(2)
            ->schema([
                TextInput::make('label')
                    ->label(trans('game-manager::lists.fields.label'))
                    ->placeholder(trans('game-manager::lists.fields.label_placeholder'))
                    ->required()
                    ->maxLength(191),
                TextInput::make('path')
                    ->label(trans('game-manager::lists.fields.path'))
                    ->placeholder('savegames/adminlist.txt')
                    ->hintIcon('tabler-question-mark')
                    ->hintIconTooltip(trans('game-manager::lists.fields.path_hint'))
                    ->required()
                    ->maxLength(191),
                Textarea::make('hint')
                    ->label(trans('game-manager::lists.fields.hint'))
                    ->placeholder(trans('game-manager::lists.fields.hint_placeholder'))
                    ->helperText(trans('game-manager::lists.fields.hint_help'))
                    ->rows(2)
                    ->maxLength(1000)
                    ->columnSpanFull(),
                Select::make('format')
                    ->label(trans('game-manager::lists.fields.format'))
                    ->options([
                        ListDefinition::FORMAT_LINES => trans('game-manager::lists.formats.lines'),
                        ListDefinition::FORMAT_JSON => trans('game-manager::lists.formats.json'),
                        ListDefinition::FORMAT_PAIRS => trans('game-manager::lists.formats.pairs'),
                    ])
                    ->default(ListDefinition::FORMAT_LINES)
                    // presets and older lists leave plain lines out
                    ->afterStateHydrated(fn (Select $component, ?string $state) => $component->state($state ?? ListDefinition::FORMAT_LINES))
                    ->selectablePlaceholder(false)
                    ->live()
                    ->required(),
                TextInput::make('key_field')
                    ->label(trans('game-manager::lists.fields.key_field'))
                    ->placeholder('uuid')
                    ->hintIcon('tabler-question-mark')
                    ->hintIconTooltip(trans('game-manager::lists.fields.key_field_hint'))
                    ->visible(fn (Get $get) => $get('format') === ListDefinition::FORMAT_JSON)
                    ->required(fn (Get $get) => $get('format') === ListDefinition::FORMAT_JSON),
                TextInput::make('display_field')
                    ->label(trans('game-manager::lists.fields.display_field'))
                    ->placeholder('name')
                    ->hintIcon('tabler-question-mark')
                    ->hintIconTooltip(trans('game-manager::lists.fields.display_field_hint'))
                    ->visible(fn (Get $get) => $get('format') === ListDefinition::FORMAT_JSON)
                    ->required(fn (Get $get) => $get('format') === ListDefinition::FORMAT_JSON),
                Textarea::make('entry_template')
                    ->label(trans('game-manager::lists.fields.entry_template'))
                    ->placeholder(fn (Get $get) => match ($get('format')) {
                        ListDefinition::FORMAT_JSON => '{"uuid": "{uuid}", "name": "{name}"}',
                        ListDefinition::FORMAT_PAIRS => ListDefinition::DEFAULT_PAIR_TEMPLATE,
                        default => 'banid 0 {value}',
                    })
                    ->helperText(fn (Get $get) => trans(match ($get('format')) {
                        ListDefinition::FORMAT_JSON => 'game-manager::lists.fields.entry_template_hint',
                        ListDefinition::FORMAT_PAIRS => 'game-manager::lists.fields.entry_template_pairs_hint',
                        default => 'game-manager::lists.fields.entry_template_lines_hint',
                    }))
                    ->rows(3)
                    ->required(fn (Get $get) => $get('format') === ListDefinition::FORMAT_JSON)
                    ->rule(fn (Get $get) => $get('format') === ListDefinition::FORMAT_JSON ? 'json' : null)
                    ->columnSpanFull(),
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
                    ->label(trans('game-manager::mods.profile.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sources')
                    ->label(trans('game-manager::mods.profile.fields.sources'))
                    ->badge()
                    ->state(fn (ModProfile $record) => array_map(fn (string $source) => Presets::SOURCES[$source] ?? $source, $record->sources())),
                TextColumn::make('lists')
                    ->label(trans('game-manager::mods.profile.fields.lists_section'))
                    ->badge()
                    ->color('gray')
                    ->state(fn (ModProfile $record) => array_column(array_values((array) $record->setting('lists', [])), 'label')),
                TextColumn::make('eggs_count')
                    ->label(trans('game-manager::mods.profile.fields.eggs'))
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
            ->emptyStateHeading(trans('game-manager::mods.profile.none'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageModProfiles::route('/'),
        ];
    }
}
