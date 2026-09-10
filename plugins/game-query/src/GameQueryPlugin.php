<?php

namespace Marschux\GameQuery;

use App\Contracts\Plugins\HasPluginSettings;
use App\Enums\ConsoleWidgetPosition;
use App\Filament\Server\Pages\Console;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Marschux\GameQuery\Filament\Server\Widgets\PlayerHistoryChart;
use Marschux\GameQuery\Filament\Server\Widgets\ServerInfoWidget;

class GameQueryPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'game-query';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverResources(plugin_path($this->getId(), "src/Filament/$id/Resources"), "Marschux\\GameQuery\\Filament\\$id\\Resources");
        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "Marschux\\GameQuery\\Filament\\$id\\Pages");
        $panel->discoverWidgets(plugin_path($this->getId(), "src/Filament/$id/Widgets"), "Marschux\\GameQuery\\Filament\\$id\\Widgets");
    }

    public function boot(Panel $panel): void
    {
        if ($panel->getId() !== 'server') {
            return;
        }

        // Discovering a widget only makes it known to the panel - the console page renders a
        // fixed list plus whatever was registered at one of its named positions, so without
        // this the widgets exist but appear nowhere.
        Console::registerCustomWidgets(ConsoleWidgetPosition::Bottom, [ServerInfoWidget::class, PlayerHistoryChart::class]);
    }

    public function getSettingsFormData(): array
    {
        return config('game-query');
    }

    public function getSettingsForm(): array
    {
        return [
            Select::make('address_source')
                ->label(trans('game-query::query.address_source'))
                ->hintIcon('tabler-question-mark')
                ->hintIconTooltip(trans('game-query::query.address_source_hint'))
                ->options([
                    'node' => trans('game-query::query.address_node'),
                    'allocation' => trans('game-query::query.address_allocation'),
                    'alias' => trans('game-query::query.address_alias'),
                ])
                ->selectablePlaceholder(false)
                ->default(fn () => config('game-query.address_source')),
            TextInput::make('timeout')
                ->label(trans('game-query::query.timeout'))
                ->hintIcon('tabler-question-mark')
                ->hintIconTooltip(trans('game-query::query.timeout_hint'))
                ->numeric()
                ->minValue(1)
                ->maxValue(30)
                ->suffix('s')
                ->default(fn () => config('game-query.timeout')),
            TextInput::make('cache_ttl')
                ->label(trans('game-query::query.cache_ttl'))
                ->hintIcon('tabler-question-mark')
                ->hintIconTooltip(trans('game-query::query.cache_ttl_hint'))
                ->numeric()
                ->minValue(0)
                ->maxValue(600)
                ->suffix('s')
                ->default(fn () => config('game-query.cache_ttl')),
            TextInput::make('history_interval')
                ->label(trans('game-query::query.history_interval'))
                ->hintIcon('tabler-question-mark')
                ->hintIconTooltip(trans('game-query::query.history_interval_hint'))
                ->numeric()
                ->minValue(1)
                ->maxValue(60)
                ->suffix('min')
                ->default(fn () => config('game-query.history_interval')),
            TextInput::make('history_retention_days')
                ->label(trans('game-query::query.history_retention'))
                ->hintIcon('tabler-question-mark')
                ->hintIconTooltip(trans('game-query::query.history_retention_hint'))
                ->numeric()
                ->minValue(1)
                ->maxValue(3650)
                ->suffix('d')
                ->default(fn () => config('game-query.history_retention_days')),
            Toggle::make('debug')
                ->label(trans('game-query::query.debug'))
                ->hintIcon('tabler-question-mark')
                ->hintIconTooltip(trans('game-query::query.debug_hint'))
                ->inline(false)
                ->default(fn () => config('game-query.debug')),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'GAME_QUERY_ADDRESS_SOURCE' => $data['address_source'],
            'GAME_QUERY_TIMEOUT' => $data['timeout'],
            'GAME_QUERY_CACHE_TTL' => $data['cache_ttl'],
            'GAME_QUERY_HISTORY_INTERVAL' => $data['history_interval'],
            'GAME_QUERY_HISTORY_RETENTION_DAYS' => $data['history_retention_days'],
            'GAME_QUERY_DEBUG' => $data['debug'],
        ]);

        Notification::make()
            ->title(trans('game-query::query.notifications.settings_saved'))
            ->success()
            ->send();
    }
}
