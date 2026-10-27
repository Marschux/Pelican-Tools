<?php

namespace Marschux\ModManager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Traits\Filament\BlockAccessInConflict;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Marschux\ModManager\GameVersion;
use Marschux\ModManager\Installer;
use Marschux\ModManager\Models\ModInstall;
use Marschux\ModManager\Models\ModProfile;
use Marschux\ModManager\Profiles\Presets;
use Marschux\ModManager\Sources\Hangar;
use Marschux\ModManager\Sources\Modrinth;
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

    public bool $showClientSide = false;

    public bool $showDeprecated = false;

    /** the source the browse tab shows, one of the profile's - see sourceKey() */
    public string $source = '';

    /** also mods made for other game versions than the server runs */
    public bool $showAll = false;

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

    /** the box in the remove modal: also delete the configs the mod wrote */
    public bool $removeConfigs = false;

    /** @var string[] the optional dependencies ticked in the install modal */
    public array $optionalPicked = [];

    /** the config open in the editor */
    public ?string $configPath = null;

    public string $configText = '';

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
    private const REMEMBERED = ['source', 'query', 'category', 'ordering', 'page', 'showClientSide', 'showDeprecated', 'showAll'];

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
        // categories are the source's own
        if ($property === 'source') {
            $this->category = '';
        }

        if (in_array($property, ['source', 'query', 'category', 'ordering', 'showClientSide', 'showDeprecated', 'showAll'], true)) {
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
            $this->details = $this->source()?->details($id, $this->versionFilter());
        } catch (Throwable $exception) {
            report($exception);
            $this->details = null;
            // the modal opened with the click already
            $this->dispatch('close-modal', id: 'mod-details');

            Notification::make()->title(trans('mod-manager::mods.messages.details_failed'))->danger()->send();
        }
    }

    /** Straight away when nothing else comes along, else the modal lists the dependencies first. */
    public function install(string $id, ?string $version = null): void
    {
        $this->requirePermission(SubuserPermission::FileCreate);

        // the loader is hidden from the plan, a version of it picked in the details goes its own way
        if ($id === (string) $this->profile()->setting('loader_package')) {
            $this->installLoader($version);

            return;
        }

        try {
            $plan = $this->installer()->plan($id, $version);
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        // what the mod can also work with, unless it is there already - offered, never ticked
        $optional = array_diff_key(end($plan)['optional'] ?? [], array_flip($this->installer()->installs()->pluck('package')->all()));

        if (count($plan) <= 1 && $optional === []) {
            $this->runInstall($plan);

            return;
        }

        $this->optionalPicked = [];
        $this->confirm = ['type' => 'install', 'id' => $id, 'version' => $version, 'name' => end($plan)['name'], 'packages' => array_slice($plan, 0, -1), 'optional' => $optional];
        $this->dispatch('close-modal', id: 'mod-details');
        $this->dispatch('open-modal', id: 'mod-confirm');
    }

    public function confirmInstall(): void
    {
        $this->requirePermission(SubuserPermission::FileCreate);

        if (($this->confirm['type'] ?? null) === 'install') {
            // planned again: the list in the modal may be minutes old
            try {
                $plan = $this->installer()->plan($this->confirm['id'], $this->confirm['version'] ?? null, $this->optionalPicked);
            } catch (Throwable $exception) {
                $plan = [];
                $this->fail($exception);
            }

            $this->runInstall($plan);
        }

        $this->closeConfirm();
    }

    /** Always asks first; the modal also offers to take dependencies nothing needs any more. */
    public function remove(int $installId): void
    {
        $this->requirePermission(SubuserPermission::FileDelete);

        $install = $this->findInstall($installId);

        $this->removeConfigs = false;
        $this->confirm = [
            'type' => 'remove',
            'id' => $install->id,
            'name' => $install->name,
            'packages' => [],
            // mods that stop loading without it
            'needed_by' => $this->installed()->filter(fn (ModInstall $other) => $other->enabled && in_array($install->package, $other->requires, true))->pluck('name')->all(),
            // only shown - what gets deleted is worked out again on confirm
            'configs' => $this->installer()->runtimeConfigs(new Collection([$install])),
            // plugins keep data folders rather than configs
            'data' => $this->installer()->jarMods(),
        ];
        $this->dispatch('open-modal', id: 'mod-confirm');
    }

    public function confirmRemove(): void
    {
        $this->requirePermission(SubuserPermission::FileDelete);

        if (($this->confirm['type'] ?? null) === 'remove') {
            try {
                $names = $this->installer()->uninstall($this->findInstall($this->confirm['id']), $this->removeConfigs);

                $this->markRestart();

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
    public function installLoader(?string $version = null): void
    {
        $this->requirePermission(SubuserPermission::FileCreate);
        // it also changes this server's start command
        $this->requirePermission(SubuserPermission::StartupUpdate);

        try {
            $loader = $this->installer()->installLoader($version);
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->markRestart();

        Notification::make()->title(trans('mod-manager::mods.messages.installed', ['name' => "$loader->name $loader->version"]))->body(trans('mod-manager::mods.messages.restart'))->success()->send();
    }

    public function updateMod(int $installId): void
    {
        $this->requirePermission(SubuserPermission::FileUpdate);

        $install = $this->findInstall($installId);

        $this->attempt(fn () => $this->installer()->update($install), trans('mod-manager::mods.messages.updated', ['name' => $install->name]));
    }

    /** Every mod with a newer version, the loader not included - it has its own button. */
    public function updateAll(): void
    {
        $this->requirePermission(SubuserPermission::FileUpdate);

        $updates = array_intersect_key($this->installer()->updates(), $this->installedVersions());
        // a pinned version was picked on purpose, it is only updated one by one
        $installs = $this->installed()->filter(fn (ModInstall $install) => isset($updates[$install->package]) && !$install->pinned);

        $this->attempt(
            fn () => $installs->each(fn (ModInstall $install) => $this->installer()->update($install)),
            trans('mod-manager::mods.messages.updated', ['name' => $installs->pluck('name')->implode(', ')]),
        );
    }

    public function toggle(int $installId): void
    {
        $this->requirePermission(SubuserPermission::FileUpdate);

        $install = $this->findInstall($installId);
        $enabled = !$install->enabled;

        $this->attempt(
            fn () => $this->installer()->setEnabled($install, $enabled),
            trans($enabled ? 'mod-manager::mods.messages.enabled' : 'mod-manager::mods.messages.disabled', ['name' => $install->name]),
        );
    }

    public function adopt(string $path): void
    {
        $this->requirePermission(SubuserPermission::FileUpdate);

        $this->attempt(fn () => $this->installer()->adopt($path), trans('mod-manager::mods.messages.adopted', ['name' => basename($path)]), false);
    }

    public function removeUnmanaged(string $path): void
    {
        $this->requirePermission(SubuserPermission::FileDelete);

        $this->attempt(fn () => $this->installer()->removeUnmanaged($path), trans('mod-manager::mods.messages.removed', ['name' => basename($path)]));
    }

    public function openConfig(string $path): void
    {
        $this->requirePermission(SubuserPermission::FileReadContent);

        try {
            $this->configText = $this->installer()->readConfig($path);
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->configPath = $path;
        $this->dispatch('open-modal', id: 'mod-config');
    }

    public function saveConfig(): void
    {
        $this->requirePermission(SubuserPermission::FileUpdate);

        if ($this->configPath === null) {
            return;
        }

        $this->attempt(fn () => $this->installer()->writeConfig($this->configPath, $this->configText), trans('mod-manager::mods.messages.config_saved', ['file' => $this->configPath]));
        $this->dispatch('close-modal', id: 'mod-config');
    }

    public function deleteConfig(string $path): void
    {
        $this->requirePermission(SubuserPermission::FileDelete);

        $this->attempt(fn () => $this->installer()->deleteConfig($path), trans('mod-manager::mods.messages.config_deleted', ['file' => $path]));
    }

    public function restart(): void
    {
        $this->requirePermission(SubuserPermission::ControlRestart);

        try {
            app(DaemonServerRepository::class)->setServer($this->server())->power('restart');
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        session()->forget($this->restartKey());

        Notification::make()->title(trans('mod-manager::mods.messages.restarting'))->success()->send();
    }

    public function dismissRestart(): void
    {
        session()->forget($this->restartKey());
    }

    /** Something changed since the last restart from this page. */
    public function needsRestart(): bool
    {
        return (bool) session($this->restartKey(), false);
    }

    /** For the view: a button the user may not press is not shown, the action checks again. */
    public function may(SubuserPermission ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!user()?->can($permission, $this->server())) {
                return false;
            }
        }

        return true;
    }

    /** @return string[] */
    public function configs(): array
    {
        return once(fn () => $this->installer()->configs());
    }

    public function configFor(string $modName): ?string
    {
        return Installer::configFor($modName, $this->configs());
    }

    public function playerList(): string
    {
        return $this->installer()->playerList();
    }

    /** @return array<string, string> package => newer version, worked out once per request */
    public function updates(): array
    {
        return once(fn () => $this->installer()->updates());
    }

    /** @return array<int, array<string, mixed>> */
    public function unmanaged(): array
    {
        return $this->installer()->unmanaged();
    }

    public function removeLoader(): void
    {
        $this->requirePermission(SubuserPermission::FileDelete);

        $this->confirm = [
            'type' => 'remove_loader',
            'name' => (string) $this->profile()->setting('loader_name', 'Loader'),
            'mods' => $this->installed()->count(),
            'packages' => [],
        ];
        $this->dispatch('open-modal', id: 'mod-confirm');
    }

    public function confirmRemoveLoader(): void
    {
        $this->requirePermission(SubuserPermission::FileDelete);
        // it also changes this server's start command back
        $this->requirePermission(SubuserPermission::StartupUpdate);

        if (($this->confirm['type'] ?? null) === 'remove_loader') {
            $this->attempt(fn () => $this->installer()->uninstallLoader(), trans('mod-manager::mods.messages.removed', ['name' => $this->confirm['name']]));
        }

        $this->closeConfirm();
    }

    /** The loader's own row in the installed table, null until it is installed through this page. */
    public function loaderInstall(): ?ModInstall
    {
        $loader = $this->installer()->installs()->firstWhere('package', (string) $this->profile()->setting('loader_package'));

        if ($loader) {
            $this->installer()->fillSizes(new Collection([$loader]));
        }

        return $loader;
    }

    /** @return Collection<int, ModInstall> the mods, without the loader */
    public function installed(): Collection
    {
        $hidden = (array) $this->profile()->setting('hidden_packages', []);
        $installs = $this->installer()->installs()->reject(fn (ModInstall $install) => in_array($install->package, $hidden, true))->values();

        $this->installer()->fillSizes($installs);

        return $installs;
    }

    /**
     * Who needs whom, in names: what a mod requires and which mods require it. The loader is
     * left out - every mod needs it.
     *
     * @return array<string, array{requires: string[], missing: string[], needed_by: string[]}> package => names
     */
    public function relations(): array
    {
        $installs = $this->installed();
        $names = $installs->pluck('name', 'package')->all();
        $hidden = (array) $this->profile()->setting('hidden_packages', []);
        $relations = [];

        foreach ($installs as $install) {
            $relations[$install->package]['requires'] = array_values(array_map(
                fn (string $package) => $names[$package] ?? str_replace('_', ' ', substr($package, strpos($package, '-') + 1)),
                array_diff($install->requires, $hidden),
            ));
            // required, but not there or switched off - the mod will not load
            $relations[$install->package]['missing'] = array_values(array_map(
                fn (string $package) => $names[$package] ?? str_replace('_', ' ', substr($package, strpos($package, '-') + 1)),
                array_filter(array_diff($install->requires, $hidden), fn (string $package) => !$installs->contains(fn (ModInstall $other) => $other->package === $package && $other->enabled)),
            ));
            $relations[$install->package]['needed_by'] = $installs
                ->filter(fn (ModInstall $other) => in_array($install->package, $other->requires, true))
                ->pluck('name')
                ->all();
        }

        return $relations;
    }

    /** @return array<string, string> package => installed version, for the tiles */
    public function installedVersions(): array
    {
        return $this->installed()->pluck('version', 'package')->all();
    }

    /** @param  array<int, array<string, mixed>>  $plan */
    private function runInstall(array $plan): void
    {
        // nothing to do, e.g. a hidden package - no "installed" and no restart for that
        if ($plan === []) {
            return;
        }

        try {
            $this->installer()->install($plan);
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $names = implode(', ', array_map(fn (array $package) => "{$package['name']} {$package['version']}", $plan));
        $this->markRestart();

        Notification::make()->title(trans('mod-manager::mods.messages.installed', ['name' => $names]))->body(trans('mod-manager::mods.messages.restart'))->success()->send();
    }

    private function attempt(callable $action, string $success, bool $restart = true): void
    {
        try {
            $action();
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        if ($restart) {
            $this->markRestart();
        }

        Notification::make()->title($success)->body($restart ? trans('mod-manager::mods.messages.restart') : null)->success()->send();
    }

    private function markRestart(): void
    {
        session([$this->restartKey() => true]);
    }

    private function restartKey(): string
    {
        return 'mod-manager.restart.' . $this->server()->id;
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
            return $this->source()?->categories() ?? [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array{name: string, installed: bool, started: bool, update: ?string}|null null when the profile has no loader */
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
            'update' => $this->updates()[(string) $profile->setting('loader_package')] ?? null,
        ];
    }

    /** @return array{version: string, from: string}|null */
    public function gameVersion(): ?array
    {
        return once(fn () => GameVersion::of($this->server(), $this->profile()));
    }

    private function fetch(): void
    {
        if ($this->source() === null) {
            $this->items = [];

            return;
        }

        try {
            $result = $this->source()->search(trim($this->query), $this->category ?: null, $this->ordering, $this->page, $this->showClientSide, $this->showDeprecated, $this->versionFilter());
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

    /** The source the browse tab shows: the one picked, else the profile's first. */
    public function sourceKey(): ?string
    {
        $sources = array_keys($this->sources());

        return in_array($this->source, $sources, true) ? $this->source : ($sources[0] ?? null);
    }

    /** @return array<string, string> the profile's sources, key => name, for the switch above the search */
    public function sources(): array
    {
        return collect($this->profile()->sources())
            ->filter(fn (string $source) => isset(Presets::SOURCES[$source]))
            ->mapWithKeys(fn (string $source) => [$source => Presets::SOURCES[$source]])
            ->all();
    }

    /** The game version mods have to fit, null to show all. */
    private function versionFilter(): ?string
    {
        return $this->showAll ? null : ($this->gameVersion()['version'] ?? null);
    }

    private function source(): Thunderstore|Modrinth|Hangar|null
    {
        $profile = $this->profile();

        return match ($this->sourceKey()) {
            'thunderstore' => new Thunderstore((string) $profile->setting('community'), (array) $profile->setting('hidden_packages', [])),
            'modrinth' => new Modrinth((array) $profile->setting('modrinth_loaders', [])),
            'hangar' => new Hangar(),
            default => null,
        };
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
