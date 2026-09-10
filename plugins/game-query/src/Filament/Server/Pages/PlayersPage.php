<?php

namespace Marschux\GameQuery\Filament\Server\Pages;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Traits\Filament\BlockAccessInConflict;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Marschux\GameQuery\Models\GameQuery;
use Marschux\GameQuery\Query\QueryPlayer;
use Marschux\GameQuery\Query\QueryResult;
use Throwable;

class PlayersPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-users-group';

    protected static ?string $slug = 'players';

    protected static ?int $navigationSort = 30;

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        if (!GameQuery::canRunQuery($server->allocation)) {
            return false;
        }

        /** @var ?GameQuery $gameQuery */
        $gameQuery = $server->egg->gameQuery; // @phpstan-ignore property.notFound

        if (!$gameQuery || !$gameQuery->shows('players')) {
            return false;
        }

        return parent::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return trans('game-query::query.field.players');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    private function result(): ?QueryResult
    {
        return $this->server()->egg->gameQuery?->runQuery($this->server()); // @phpstan-ignore property.notFound
    }

    /**
     * A table fed by records() works on plain arrays, not objects: columns resolve by array
     * key and actions receive the array as $record. So the DTOs are flattened here.
     *
     * @return Collection<int, array{name: string, score: ?int, playtime: ?int}>
     */
    private function players(): Collection
    {
        return collect($this->result()?->playerList ?? [])
            ->map(fn (QueryPlayer $player) => [
                'name' => $player->name,
                'score' => $player->score,
                'playtime' => $player->playtime,
            ])
            ->values();
    }

    public function table(Table $table): Table
    {
        $players = $this->players();

        return $table
            ->records(fn () => $players)
            ->columns([
                TextColumn::make('name')
                    ->label(trans('game-query::query.player.name')),
                TextColumn::make('score')
                    ->label(trans('game-query::query.player.score'))
                    // hidden for protocols that do not report it - minecraft, for one
                    ->visible(fn () => $players->contains(fn (array $player) => $player['score'] !== null)),
                TextColumn::make('playtime')
                    ->label(trans('game-query::query.player.playtime'))
                    ->visible(fn () => $players->contains(fn (array $player) => $player['playtime'] !== null))
                    ->formatStateUsing(fn ($state) => $state === null ? null : gmdate($state >= 3600 ? 'H:i:s' : 'i:s', (int) $state)),
            ])
            ->recordActions($this->playerActions())
            ->emptyStateIcon('tabler-users-group')
            ->emptyStateHeading(trans('game-query::query.table.no_players'))
            ->emptyStateDescription(trans('game-query::query.table.no_players_description'));
    }

    /**
     * Actions come from the game query as command templates, so kick/ban/whitelist/op are the
     * same mechanism for every game and a new game needs an admin entry, not new code.
     *
     * @return Action[]
     */
    private function playerActions(): array
    {
        $server = $this->server();

        /** @var ?GameQuery $gameQuery */
        $gameQuery = $server->egg->gameQuery; // @phpstan-ignore property.notFound

        // sending a console command is exactly what control.console grants - no own permission
        if (!$gameQuery || !auth()->user()?->can('control.console', $server)) {
            return [];
        }

        return collect($gameQuery->player_actions ?? [])
            ->filter(fn ($action) => !empty($action['label']) && !empty($action['command']))
            ->map(fn ($action) => Action::make(str($action['label'])->slug()->toString())
                ->label($action['label'])
                ->icon($action['icon'] ?? null)
                ->requiresConfirmation((bool) ($action['confirm'] ?? false))
                ->action(fn (array $record) => $this->send($server, $action['command'], $record['name'])))
            ->values()
            ->all();
    }

    private function send(Server $server, string $template, string $player): void
    {
        try {
            app(DaemonServerRepository::class)
                ->setServer($server)
                ->send(str_replace('{player}', $player, $template));

            Notification::make()
                ->title(trans('game-query::query.notifications.action_sent'))
                ->success()
                ->send();
        } catch (Throwable) {
            Notification::make()
                ->title(trans('game-query::query.notifications.action_failed'))
                ->danger()
                ->send();
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
