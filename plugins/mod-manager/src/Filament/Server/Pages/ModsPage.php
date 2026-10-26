<?php

namespace Marschux\ModManager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Traits\Filament\BlockAccessInConflict;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Marschux\ModManager\Installer;
use Marschux\ModManager\Models\ModInstall;
use Marschux\ModManager\Models\ModProfile;
use Marschux\ModManager\Sources\Thunderstore;
use RuntimeException;
use Throwable;

/**
 * Mods for this server's game, as set up in the mod profile linked to its egg.
 *
 * Plain blade buttons and a blade modal in the view, like the access lists page: Filament page
 * actions did not render in this panel.
 */
class ModsPage extends Page
{
    use BlockAccessInConflict;

    protected string $view = 'mod-manager::pages.mods';

    protected static string|BackedEnum|null $navigationIcon = 'tabler-puzzle';

    protected static ?string $slug = 'mods';

    protected static ?int $navigationSort = 32;

    /** installed | browse */
    public string $tab = 'browse';

    public string $query = '';

    public string $category = '';

    public string $ordering = 'most-downloaded';

    public int $page = 1;

    public bool $hideClientSide = true;

    public bool $showDeprecated = false;

    /** @var array<int, array<string, mixed>> */
    public array $items = [];

    public int $pages = 1;

    /** @var array<string, mixed>|null the package the detail modal shows */
    public ?array $details = null;

    /**
     * What the confirm modal asks about: install => package id plus the dependencies coming
     * with it, remove => install id plus the dependencies nothing needs afterwards.
     *
     * @var array<string, mixed>|null
     */
    public ?array $confirm = null;

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return parent::canAccess()
            && user()?->can(SubuserPermission::FileRead, $server)
            && ModProfile::forServer($server) !== null;
    }

    public static function getNavigationLabel(): string
    {
        return trans('mod-manager::mods.navigation');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    /** the browse filters kept between visits, per server: a category id only means something in one game */
    private const REMEMBERED = ['query', 'category', 'ordering', 'page', 'hideClientSide', 'showDeprecated'];

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        foreach (session($this->sessionKey(), []) as $property => $value) {
            if (in_array($property, self::REMEMBERED, true)) {
                $this->{$property} = $value;
            }
        }

        // with mods on the server, what is there comes first
        if ($this->installed()->isNotEmpty()) {
            $this->tab = 'installed';
        }

        $this->fetch();
    }

    /** Every filter change searches right away, typing included (debounced in the view). */
    public function updated(string $property): void
    {
        if (in_array($property, ['query', 'category', 'ordering', 'hideClientSide', 'showDeprecated'], true)) {
            $this->search();
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['installed', 'browse'], true) ? $tab : 'browse';
    }

    /** A new search starts at the first page again. */
    public function search(): void
    {
        $this->goToPage(1);
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, min($page, $this->pages ?: 1));
        $this->fetch();
    }

    public function showDetails(string $id): void
    {
        try {
            $this->details = $this->source()->details($id);
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title(trans('mod-manager::mods.messages.details_failed'))->danger()->send();

            return;
        }

        $this->dispatch('open-modal', id: 'mod-details');
    }

    /** Straight away when nothing else comes along, else the modal lists the dependencies first. */
    public function install(string $id): void
    {
        $this->requirePermission(SubuserPermission::FileCreate);

        try {
            $plan = $this->installer()->plan($id);
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        if (count($plan) <= 1) {
            $this->runInstall($plan);

            return;
        }

        $this->confirm = ['type' => 'install', 'id' => $id, 'name' => end($plan)['name'], 'packages' => array_slice($plan, 0, -1)];
        $this->dispatch('close-modal', id: 'mod-details');
        $this->dispatch('open-modal', id: 'mod-confirm');
    }

    public function confirmInstall(): void
    {
        $this->requirePermission(SubuserPermission::FileCreate);

        if (($this->confirm['type'] ?? null) === 'install') {
            // planned again: the list in the modal may be minutes old
            $this->runInstall($this->installer()->plan($this->confirm['id']));
        }

        $this->closeConfirm();
    }

    /** Always asks first; the modal also offers to take dependencies nothing needs any more. */
    public function remove(int $installId): void
    {
        $this->requirePermission(SubuserPermission::FileDelete);

        $install = $this->findInstall($installId);

        $this->confirm = [
            'type' => 'remove',
            'id' => $install->id,
            'name' => $install->name,
            'packages' => $this->installer()->orphans($install)->map(fn (ModInstall $orphan) => ['name' => $orphan->name, 'version' => $orphan->version, 'icon' => $orphan->icon])->all(),
        ];
        $this->dispatch('open-modal', id: 'mod-confirm');
    }

    public function confirmRemove(bool $withDependencies): void
    {
        $this->requirePermission(SubuserPermission::FileDelete);

        if (($this->confirm['type'] ?? null) === 'remove') {
            try {
                $names = $this->installer()->uninstall($this->findInstall($this->confirm['id']), $withDependencies);

                Notification::make()->title(trans('mod-manager::mods.messages.removed', ['name' => implode(', ', $names)]))->body(trans('mod-manager::mods.messages.restart'))->success()->send();
            } catch (Throwable $exception) {
                $this->fail($exception);
            }
        }

        $this->closeConfirm();
    }

    public function closeConfirm(): void
    {
        $this->confirm = null;
        $this->dispatch('close-modal', id: 'mod-confirm');
    }

    /** Installs or updates the mod loader and starts this server through it from then on. */
    public function installLoader(): void
    {
        $this->requirePermission(SubuserPermission::FileCreate);
        // it also changes this server's start command
        $this->requirePermission(SubuserPermission::StartupUpdate);

        try {
            $loader = $this->installer()->installLoader();
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        Notification::make()->title(trans('mod-manager::mods.messages.installed', ['name' => "$loader->name $loader->version"]))->body(trans('mod-manager::mods.messages.restart'))->success()->send();
    }

    /** @return Collection<int, ModInstall> the mods, without the loader */
    public function installed(): Collection
    {
        $hidden = (array) $this->profile()->setting('hidden_packages', []);

        return $this->installer()->installs()->reject(fn (ModInstall $install) => in_array($install->package, $hidden, true))->values();
    }

    /** @return array<string, string> package => installed version, for the tiles */
    public function installedVersions(): array
    {
        return $this->installed()->pluck('version', 'package')->all();
    }

    /** @param  array<int, array<string, mixed>>  $plan */
    private function runInstall(array $plan): void
    {
        try {
            $this->installer()->install($plan);
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $names = implode(', ', array_map(fn (array $package) => "{$package['name']} {$package['version']}", $plan));

        Notification::make()->title(trans('mod-manager::mods.messages.installed', ['name' => $names]))->body(trans('mod-manager::mods.messages.restart'))->success()->send();
    }

    private function fail(Throwable $exception): void
    {
        // our own messages are meant for the user as they are, anything else goes to the log
        if (!$exception instanceof RuntimeException) {
            report($exception);
        }

        Notification::make()
            ->title(trans('mod-manager::mods.messages.failed'))
            ->body($exception instanceof RuntimeException ? $exception->getMessage() : null)
            ->danger()
            ->send();
    }

    private function requirePermission(SubuserPermission $permission): void
    {
        abort_unless(user()?->can($permission, $this->server()), 403);
    }

    private function findInstall(int $id): ModInstall
    {
        return ModInstall::query()->where('server_id', $this->server()->id)->findOrFail($id);
    }

    private function installer(): Installer
    {
        return new Installer($this->server(), $this->profile());
    }

    /** @return array<string, string> */
    public function categories(): array
    {
        try {
            return $this->source()->categories();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array{name: string, installed: bool, started: bool}|null null when the profile has no loader */
    public function loader(): ?array
    {
        $profile = $this->profile();
        $check = (string) $profile->setting('loader_check');

        if ($check === '') {
            return null;
        }

        try {
            $listing = app(DaemonFileRepository::class)->setServer($this->server())->getDirectory(dirname($check) === '.' ? '/' : dirname($check));
            $installed = array_is_list($listing) && in_array(basename($check), array_column($listing, 'name'), true);
        } catch (Throwable) {
            $installed = false;
        }

        $prefix = trim((string) $profile->setting('start_prefix'));

        return [
            'name' => (string) $profile->setting('loader_name', $check),
            'installed' => $installed,
            // started through the loader: this server's start command carries the prefix
            'started' => $prefix === '' || str_contains((string) $this->server()->startup, $prefix),
        ];
    }

    private function fetch(): void
    {
        try {
            $result = $this->source()->search(trim($this->query), $this->category ?: null, $this->ordering, $this->page, $this->hideClientSide, $this->showDeprecated);
        } catch (Throwable $exception) {
            report($exception);
            $this->items = [];

            Notification::make()->title(trans('mod-manager::mods.messages.search_failed'))->danger()->send();

            return;
        }

        $this->items = $result['items'];
        $this->pages = $result['pages'];

        session([$this->sessionKey() => array_combine(self::REMEMBERED, array_map(fn (string $property) => $this->{$property}, self::REMEMBERED))]);
    }

    private function sessionKey(): string
    {
        return 'mod-manager.browse.' . $this->server()->id;
    }

    private function source(): Thunderstore
    {
        $profile = $this->profile();

        return new Thunderstore((string) $profile->setting('community'), (array) $profile->setting('hidden_packages', []));
    }

    private function profile(): ModProfile
    {
        return once(fn () => ModProfile::forServer($this->server()));
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }
}
