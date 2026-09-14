<?php

namespace Marschux\GameQuery\Filament\Server\Pages;

use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Url;
use Marschux\GameQuery\Players\PlayerActionException;
use Marschux\GameQuery\Players\PlayerManager;
use Throwable;

/**
 * Player management for the game behind this server: who is online, and the lists that decide
 * who may join, who is banned and who is an admin.
 *
 * The tabs are plain links rather than Filament's Tabs component: that one addresses its tabs by
 * a numeric index, which would tie the page to an implementation detail. A link reloads the page
 * with ?activeTab=, and the value is checked against the readable tabs on the way in.
 */
class PlayersPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-users-group';

    protected static ?string $slug = 'players';

    protected static ?int $navigationSort = 30;

    #[Url]
    public ?string $activeTab = null;

    private ?PlayerManager $manager = null;

    private ?string $error = null;

    /** @var ?array<int, array<string, mixed>> */
    private ?array $cachedRows = null;

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return parent::canAccess() && PlayerManager::for($server) !== null;
    }

    public static function getNavigationLabel(): string
    {
        return trans('game-query::query.players.navigation');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    /**
     * The tab switches sit above the table, next to "add", rather than in the page header: the
     * table renders them reliably, and they belong next to the thing they switch anyway.
     *
     * @return Action[]
     */
    private function tabActions(): array
    {
        $current = $this->tab();

        return array_map(fn (string $tab) => Action::make("tab_$tab")
            ->label(trans("game-query::query.players.tabs.$tab"))
            ->color($tab === $current ? 'primary' : 'gray')
            ->url(static::getUrl(['activeTab' => $tab])), $this->readableTabs());
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->rows())
            ->columns([
                TextColumn::make('name')
                    ->label(fn () => trans('game-query::query.players.columns.' . $this->manager()->nameLabel($this->tab()))),
                TextColumn::make('uuid')
                    ->label(trans('game-query::query.players.columns.uuid'))
                    ->visible(fn () => $this->anyRowHas('uuid')),
                TextColumn::make('reason')
                    ->label(trans('game-query::query.players.columns.reason'))
                    ->visible(fn () => $this->anyRowHas('reason')),
            ])
            ->headerActions([
                ...$this->tabActions(),
                Action::make('add')
                    ->label(trans('game-query::query.players.actions.add'))
                    ->icon('tabler-plus')
                    ->visible(fn () => $this->manager()->canAdd($this->tab()))
                    ->schema([
                        TextInput::make('value')
                            ->label(fn () => trans('game-query::query.players.form.' . $this->manager()->inputLabel($this->tab())))
                            ->hint(fn () => $this->manager()->inputLabel($this->tab()) === 'id'
                                ? trans('game-query::query.players.form.id_hint')
                                : null)
                            ->required(),
                    ])
                    ->action(fn (array $data) => $this->run(
                        fn () => $this->manager()->canAdd($this->tab()),
                        fn () => $this->manager()->add($this->tab(), (string) ($data['value'] ?? '')),
                    )),
            ])
            ->recordActions([
                Action::make('kick')
                    ->label(trans('game-query::query.players.actions.kick'))
                    ->icon('tabler-door-exit')
                    ->requiresConfirmation()
                    ->visible(fn () => $this->tab() === 'online' && $this->manager()->canKick())
                    ->action(fn (array $record) => $this->run(
                        fn () => $this->manager()->canKick(),
                        fn () => $this->manager()->kick($record),
                    )),
                Action::make('ban')
                    ->label(trans('game-query::query.players.actions.ban'))
                    ->icon('tabler-hammer')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn () => $this->tab() === 'online' && $this->manager()->canBan())
                    ->action(fn (array $record) => $this->run(
                        fn () => $this->manager()->canBan(),
                        fn () => $this->manager()->ban($record),
                    )),
                Action::make('remove')
                    ->label(fn () => trans('game-query::query.players.actions.' . (str_contains($this->tab(), 'ban') ? 'unban' : 'remove')))
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn () => $this->tab() !== 'online' && $this->manager()->canWrite($this->tab()))
                    ->action(fn (array $record) => $this->run(
                        fn () => $this->manager()->canWrite($this->tab()),
                        fn () => $this->manager()->remove($this->tab(), $record),
                    )),
            ])
            ->emptyStateIcon('tabler-users-group')
            ->emptyStateHeading(fn () => $this->error ?? trans('game-query::query.players.table.empty'))
            ->emptyStateDescription('');
    }

    private function manager(): PlayerManager
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $this->manager ??= PlayerManager::for($server);
    }

    /** @return string[] */
    private function readableTabs(): array
    {
        return array_values(array_filter(
            $this->manager()->tabs(),
            fn (string $tab) => $this->manager()->canRead($tab),
        ));
    }

    /**
     * The tab to show. Never trusts $activeTab: it comes straight from the url, so a tab the
     * viewer may not read - or one this game does not have - falls back to the first readable one.
     */
    private function tab(): string
    {
        $tabs = $this->readableTabs();

        return in_array($this->activeTab, $tabs, true) ? $this->activeTab : ($tabs[0] ?? 'online');
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        if ($this->cachedRows !== null) {
            return $this->cachedRows;
        }

        try {
            $this->error = null;

            return $this->cachedRows = array_values($this->manager()->rows($this->tab()));
        } catch (PlayerActionException $exception) {
            $this->error = $exception->getMessage();
        } catch (Throwable $exception) {
            report($exception);

            $this->error = trans('game-query::query.players.table.failed');
        }

        return $this->cachedRows = [];
    }

    private function anyRowHas(string $field): bool
    {
        foreach ($this->rows() as $row) {
            if (($row[$field] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Permissions are checked here again rather than trusting the visible()/authorize() that
     * hid the button: those run when the page renders, this runs when the action fires.
     *
     * @param  Closure(): bool  $allowed
     * @param  Closure(): string  $do
     */
    private function run(Closure $allowed, Closure $do): void
    {
        abort_unless($allowed(), 403);

        try {
            Notification::make()->title($do())->success()->send();
        } catch (PlayerActionException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title(trans('game-query::query.players.messages.failed'))->danger()->send();
        }

        $this->cachedRows = null;
        $this->resetTable();
    }
}
