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
use Filament\Support\Colors\Color;
use Illuminate\Database\Eloquent\Collection;
use App\Enums\NodeJwtScope;
use App\Services\Nodes\NodeJWTService;
use Carbon\CarbonImmutable;
use Marschux\ModManager\GameVersion;
use Marschux\ModManager\Installer;
use Marschux\ModManager\Models\ModInstall;
use Marschux\ModManager\Models\ModProfile;
use Marschux\ModManager\Profiles\Presets;
use Marschux\ModManager\Sources\GameBanana;
use Marschux\ModManager\Sources\GitHub;
use Marschux\ModManager\Sources\GoldSrc;
use Marschux\ModManager\Sources\Source1;
use Marschux\ModManager\Sources\Source2;
use Marschux\ModManager\Sources\Hangar;
use Marschux\ModManager\Sources\Modrinth;
use Marschux\ModManager\Sources\Thunderstore;
use Marschux\ModManager\Sources\Workshop;
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
    public string $tab = 'installed';

    public string $query = '';

    public string $category = '';

    public string $ordering = 'most-downloaded';

    public int $page = 1;

    public bool $showClientSide = false;

    public bool $showDeprecated = false;

    /** the source the browse tab shows, one of the profile's - see sourceKey() */
    public string $source = '';

    /** what the browse tab shows from Modrinth: plugin, datapack or resourcepack - see kinds() */
    public string $kind = 'plugin';

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
    private const REMEMBERED = ['source', 'kind', 'query', 'category', 'ordering', 'page', 'showClientSide', 'showDeprecated', 'showAll'];

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        foreach (session($this->sessionKey(), []) as $property => $value) {
            if (in_array($property, self::REMEMBERED, true)) {
                $this->{$property} = $value;
            }
        }

        // what is there comes first, with the loader's row - the listing waits for the browse tab,
        // a cold source API costs up to a second
        $this->tab = 'installed';
    }

    /** Every filter change searches right away, typing included (debounced in the view). */
    public function updated(string $property): void
    {
        // categories are the source's own
        if (in_array($property, ['source', 'kind'], true)) {
            $this->category = '';
        }

        if (in_array($property, ['source', 'kind', 'query', 'category', 'ordering', 'showClientSide', 'showDeprecated', 'showAll'], true)) {
            $this->search();
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['installed', 'browse', 'links'], true) ? $tab : 'browse';

        if ($this->tab === 'browse' && $this->items === []) {
            $this->fetch();
        }
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
            // from the installed tab it can be a package of another source than the one browsed
            $this->details = $this->sourceFor($id)?->details($id, $this->versionFilter());
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

        if (str_starts_with($id, Workshop::PREFIX) || str_starts_with($id, Workshop::COLLECTION)) {
            $this->dispatch('close-modal', id: 'mod-details');
            $this->addWorkshop(substr($id, strpos($id, ':') + 1));

            return;
        }

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
            'data' => $this->jarMods(),
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

        $updates = $this->installer()->updates();
        // a pinned version was picked on purpose, it is only updated one by one; installed() leaves the loader out
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

    /**
     * Where the browser sends an upload: straight to Wings like Pelican's file manager does, into
     * a folder of its own - so it never passes through the panel and PHP's upload limit.
     *
     * @return array{url: string, directory: string, token: string}
     */
    public function uploadTarget(): array
    {
        $this->requirePermission(SubuserPermission::FileCreate);

        $server = $this->server();
        $token = bin2hex(random_bytes(16));
        $jwt = app(NodeJWTService::class)
            ->setExpiresAt(CarbonImmutable::now()->addMinutes(15))
            ->setScopes(NodeJwtScope::FileUpload)
            ->setUser(user())
            ->setClaims(['server_uuid' => $server->uuid])
            ->handle($server->node, user()->id . $server->uuid);

        return [
            'url' => $server->node->getConnectionAddress() . '/upload/file?token=' . $jwt->toString(),
            'directory' => Installer::UPLOADS . "/$token",
            'token' => $token,
        ];
    }

    /** The browser's upload is on the server: the plugin goes where it belongs. */
    public function installUploaded(string $token, string $name): void
    {
        $this->requirePermission(SubuserPermission::FileCreate);

        $this->attempt(fn () => $this->installer()->installUpload($token, $name), trans('mod-manager::mods.messages.installed', ['name' => $name]));
    }

    /** The upload to Wings itself failed, the browser says why. */
    public function uploadFailed(string $reason): void
    {
        Notification::make()->title(trans('mod-manager::mods.messages.failed'))->body(mb_substr($reason, 0, 200))->danger()->send();
    }

    /** A config the page must not delete: AMX Mod X would not write it again. */
    public function keepsConfig(string $path): bool
    {
        return $this->goldsrc() && Installer::amxxCore($path);
    }

    /** Plugins are uploaded for the Counter-Strike games: AMX Mod X, SourceMod and CounterStrikeSharp have no site to browse. */
    public function canUpload(): bool
    {
        return in_array($this->profile()->setting('loader_type'), Presets::ARCHIVE_LOADERS, true);
    }

    /** GoldSrc: AMX Mod X's plugin list, its configs and the optional engine. */
    public function goldsrc(): bool
    {
        return $this->profile()->setting('loader_type') === 'goldsrc';
    }

    /** CS:GO: a map's switch puts it in and out of the mapgroup mg_modmanager. */
    public function mapgroups(): bool
    {
        return $this->installer()->mapgroups();
    }

    /** Counter-Strike 2: plugins also come from a GitHub release link. */
    public function source2(): bool
    {
        return $this->profile()->setting('loader_type') === 'source2';
    }

    /** A workshop map pasted in, by id or link. */
    public string $workshopLink = '';

    /** A workshop map or collection by id or link; a collection says how many of its maps came along. */
    private function addWorkshop(string $input): void
    {
        $this->attempt(
            fn () => $this->installer()->addWorkshop($input),
            fn (array $result) => $result['collection']
                ? trans('mod-manager::mods.workshop.collection_added', ['added' => $result['added'], 'skipped' => $result['skipped']])
                : trans('mod-manager::mods.workshop.added'),
        );
    }

    public function addWorkshopMap(): void
    {
        $this->requirePermission(SubuserPermission::FileCreate);

        $this->addWorkshop($this->workshopLink);
        $this->workshopLink = '';
    }

    public function hasLoginToken(): bool
    {
        return once(fn () => $this->installer()->hasLoginToken());
    }

    /** A GitHub release link pasted in: the asset to install, or the choice when there are several. */
    public string $githubLink = '';

    /** @var array<int, array{name: string, size: int}> */
    public array $githubAssets = [];

    public function installGitHub(?string $asset = null): void
    {
        $this->requirePermission(SubuserPermission::FileCreate);

        $repository = GitHub::repository($this->githubLink);

        if ($repository === null) {
            Notification::make()->title(trans('mod-manager::mods.github.bad_link'))->danger()->send();

            return;
        }

        if ($asset === null) {
            try {
                $assets = (new GitHub())->release($repository)['assets'];
            } catch (Throwable $exception) {
                $this->fail($exception);

                return;
            }

            // more than one zip in the release: the page lets the user pick
            if (count($assets) > 1) {
                $this->githubAssets = array_map(fn (array $asset) => ['name' => $asset['name'], 'size' => $asset['size']], $assets);

                return;
            }

            $asset = $assets[0]['name'];
        }

        $this->githubAssets = [];
        $this->attempt(fn () => $this->installer()->installGitHub($repository, $asset), trans('mod-manager::mods.messages.installed', ['name' => $asset]));
        $this->githubLink = '';
    }

    /** A plugin of AMX Mod X's plugins.ini on or off. */
    public function toggleLoaderPlugin(string $file, bool $enabled): void
    {
        $this->requirePermission(SubuserPermission::FileUpdate);

        $this->attempt(
            fn () => $this->installer()->setLoaderPlugin($file, $enabled),
            trans($enabled ? 'mod-manager::mods.messages.enabled' : 'mod-manager::mods.messages.disabled', ['name' => $file]),
        );
    }

    /** A plugin of AMX Mod X's plugins.ini deleted, file and line. */
    public function deleteLoaderPlugin(string $file): void
    {
        $this->requirePermission(SubuserPermission::FileDelete);

        $this->attempt(fn () => $this->installer()->deleteLoaderPlugin($file), trans('mod-manager::mods.messages.removed', ['name' => $file]));
    }

    /** @return array<int, array{file: string, enabled: bool, note: string, group: string, exists: bool}> */
    public function loaderPlugins(): array
    {
        return $this->installer()->loaderPlugins();
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

    /** Plugins rather than mods: single files from Modrinth or Hangar, nothing players need. */
    public function jarMods(): bool
    {
        return $this->installer()->jarMods();
    }

    /** @return array<string, string> what the browse tab can show from Modrinth, kind => name - empty unless there is a choice */
    public function kinds(): array
    {
        if ($this->sourceKey() !== 'modrinth' || !$this->jarMods()) {
            return [];
        }

        return collect(array_keys(Modrinth::KINDS))->mapWithKeys(fn (string $kind) => [$kind => trans("mod-manager::mods.kinds.$kind")])->all();
    }

    /** The site a package comes from, by its id. */
    public function sourceName(string $id): string
    {
        return match (true) {
            $id === GoldSrc::PACKAGE => 'AMX Mod X',
            $id === Source1::PACKAGE => 'SourceMod',
            $id === Source2::PACKAGE => 'CounterStrikeSharp',
            str_starts_with($id, GameBanana::PREFIX) => Presets::SOURCES['gamebanana'],
            Modrinth::kindOf($id) !== null => Presets::SOURCES['modrinth'],
            str_starts_with($id, Hangar::PREFIX) => Presets::SOURCES['hangar'],
            default => Presets::SOURCES['thunderstore'],
        };
    }

    /** The line below a name in the installed table: a mod's author, a plugin's source, a pack's kind. */
    public function origin(string $package): string
    {
        return match (true) {
            $package === GoldSrc::PACKAGE => trans('mod-manager::mods.by', ['author' => 'ReHLDS team, AlliedModders']),
            $package === GoldSrc::ENGINE => trans('mod-manager::mods.by', ['author' => 'ReHLDS team']),
            $package === Source1::PACKAGE => trans('mod-manager::mods.by', ['author' => 'AlliedModders']),
            $package === Source2::PACKAGE => trans('mod-manager::mods.by', ['author' => 'AlliedModders, roflmuffin']),
            Installer::uploaded($package), str_starts_with($package, Installer::MAP_UPLOAD) => trans('mod-manager::mods.upload.origin'),
            str_starts_with($package, GitHub::PREFIX) => trans('mod-manager::mods.by', ['author' => strtok(GitHub::parse($package)[0], '/')]),
            str_starts_with($package, Workshop::PREFIX) => 'Steam Workshop',
            str_starts_with($package, GameBanana::PREFIX), Installer::jarPackage($package) => $this->sourceName($package),
            default => trans('mod-manager::mods.by', ['author' => strtok($package, '-')]),
        };
    }

    /**
     * What a row is, for the type column: key of mods.types and its icon.
     *
     * @return array{0: string, 1: string}
     */
    public function type(string $package, bool $isLoader = false): array
    {
        return match (true) {
            $package === GoldSrc::ENGINE => ['engine', 'tabler-engine'],
            $isLoader => ['loader', 'tabler-plug'],
            Installer::mapPackage($package), str_starts_with($package, Workshop::PREFIX) => ['map', 'tabler-map'],
            Modrinth::kindOf($package) === 'datapack' => ['datapack', 'tabler-package'],
            Modrinth::kindOf($package) === 'resourcepack' => ['resourcepack', 'tabler-photo'],
            // jars of Paper and AMX Mod X's .amxx are plugins, Thunderstore packages mods
            Installer::uploaded($package), Installer::jarPackage($package), str_starts_with($package, GitHub::PREFIX) => ['plugin', 'tabler-puzzle'],
            default => ['mod', 'tabler-puzzle'],
        };
    }

    /** A type's badge colour: one hue each, none of the status colours (green, amber, red). */
    public function typeColor(string $type): array
    {
        return match ($type) {
            'loader' => Color::Sky,
            'engine' => Color::Orange,
            'map' => Color::Teal,
            'plugin' => Color::Violet,
            'datapack' => Color::Fuchsia,
            'resourcepack' => Color::Pink,
            default => Color::Indigo,
        };
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

    /** ReHLDS, ReGameDLL and ReAPI's row, null until they are in. */
    public function engineInstall(): ?ModInstall
    {
        $engine = $this->goldsrc() ? $this->installer()->installs()->firstWhere('package', GoldSrc::ENGINE) : null;

        if ($engine) {
            $this->installer()->fillSizes(new Collection([$engine]));
        }

        return $engine;
    }

    /** Installs or updates ReHLDS, ReGameDLL and ReAPI - Valve's files are put aside. */
    public function installEngine(): void
    {
        $this->requirePermission(SubuserPermission::FileCreate);
        $this->requirePermission(SubuserPermission::FileUpdate);

        try {
            $engine = $this->installer()->installEngine();
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->markRestart();

        Notification::make()->title(trans('mod-manager::mods.messages.installed', ['name' => "$engine->name $engine->version"]))->body(trans('mod-manager::mods.messages.restart'))->success()->send();
    }

    public function removeEngine(): void
    {
        $this->requirePermission(SubuserPermission::FileDelete);

        $this->confirm = ['type' => 'remove_engine', 'name' => 'ReHLDS + ReGameDLL + ReAPI', 'packages' => []];
        $this->dispatch('open-modal', id: 'mod-confirm');
    }

    public function confirmRemoveEngine(): void
    {
        $this->requirePermission(SubuserPermission::FileDelete);

        if (($this->confirm['type'] ?? null) === 'remove_engine') {
            $this->attempt(fn () => $this->installer()->uninstallEngine(), trans('mod-manager::mods.messages.removed', ['name' => $this->confirm['name']]));
        }

        $this->closeConfirm();
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
        // the GoldSrc engine has a row of its own, like the loader
        $hidden = [...(array) $this->profile()->setting('hidden_packages', []), GoldSrc::ENGINE];
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

    /** @return array<string, string> package => installed version, for the tiles and the details - the loader included */
    public function installedVersions(): array
    {
        return $this->installer()->installs()->pluck('version', 'package')->all();
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

    /** @param  string|callable(mixed): string  $success  a callable gets what $action returned */
    private function attempt(callable $action, string|callable $success, bool $restart = true): void
    {
        try {
            $result = $action();
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        if ($restart) {
            $this->markRestart();
        }

        Notification::make()->title(is_callable($success) ? $success($result) : $success)->body($restart ? trans('mod-manager::mods.messages.restart') : null)->success()->send();
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
            // started through the loader: liblist.gam loads Metamod, or this server's start command carries the prefix
            'started' => in_array($profile->setting('loader_type'), Presets::ARCHIVE_LOADERS, true)
                ? $installed && $this->installer()->loaderHooked()
                : $prefix === '' || str_contains((string) $this->server()->startup, $prefix),
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
    /** The Steam Web API key in the server's startup variable WORKSHOP_AUTHKEY, '' without. */
    private function steamKey(): string
    {
        return once(fn () => $this->installer()->steamKey());
    }

    /**
     * The browse tabs: one per source, one per kind where Modrinth offers several. Key "source" or
     * "source:kind", label what the tiles are and where they come from, e.g. "GameBanana Maps".
     *
     * @return array<string, string>
     */
    public function browseTabs(): array
    {
        $tabs = [];

        foreach ($this->sources() as $source => $name) {
            $whats = match (true) {
                $source === 'modrinth' && $this->jarMods() => collect(array_keys(Modrinth::KINDS))->mapWithKeys(fn (string $kind) => ["$source:$kind" => trans("mod-manager::mods.kinds.$kind")])->all(),
                $source === 'workshop' => [$source => trans('mod-manager::mods.tabs.maps'), "$source:collection" => trans('mod-manager::mods.tabs.collections')],
                $source === 'gamebanana' => [$source => trans('mod-manager::mods.tabs.maps')],
                $this->jarMods() => [$source => trans('mod-manager::mods.tabs.plugins')],
                default => [$source => trans('mod-manager::mods.tabs.mods')],
            };

            foreach ($whats as $key => $what) {
                $tabs[$key] = trans('mod-manager::mods.tabs.browse_from', ['source' => $name, 'what' => $what]);
            }
        }

        return $tabs;
    }

    /** Which browse tab is showing. */
    public function browseKey(): string
    {
        $source = (string) $this->sourceKey();

        return match (true) {
            $source === 'modrinth' && $this->jarMods() => "$source:$this->kind",
            $source === 'workshop' && $this->kind === 'collection' => 'workshop:collection',
            default => $source,
        };
    }

    /** Opens a browse tab; another source or kind has its own categories, so the search starts over. */
    public function browse(string $key): void
    {
        if (!isset($this->browseTabs()[$key])) {
            return;
        }

        $this->tab = 'browse';
        // the workshop's maps tab has no kind in its key
        [$source, $kind] = explode(':', $key) + [1 => $key === 'workshop' ? 'map' : $this->kind];

        if ($key !== $this->browseKey()) {
            $this->source = $source;
            $this->kind = $kind;
            $this->category = '';
            $this->search();
        } elseif ($this->items === []) {
            $this->fetch();
        }
    }

    public function sources(): array
    {
        return collect($this->profile()->sources())
            ->filter(fn (string $source) => isset(Presets::SOURCES[$source]))
            // the workshop is only browsed with a Steam Web API key; without one maps go in by id
            ->reject(fn (string $source) => $source === 'workshop' && $this->steamKey() === '')
            ->mapWithKeys(fn (string $source) => [$source => Presets::SOURCES[$source]])
            ->all();
    }

    /** The game version mods have to fit, null to show all. */
    private function versionFilter(): ?string
    {
        return $this->showAll ? null : ($this->gameVersion()['version'] ?? null);
    }

    private function source(): Thunderstore|Modrinth|Hangar|GameBanana|Workshop|null
    {
        $profile = $this->profile();

        return match ($this->sourceKey()) {
            'thunderstore' => new Thunderstore((string) $profile->setting('community'), (array) $profile->setting('hidden_packages', [])),
            'modrinth' => new Modrinth((array) $profile->setting('modrinth_loaders', []), $this->kinds() ? $this->kind : 'plugin'),
            'hangar' => new Hangar(),
            'gamebanana' => $this->gameBanana(),
            'workshop' => new Workshop($this->steamKey(), $this->kind === 'collection' ? 'collection' : 'map'),
            default => null,
        };
    }

    private function gameBanana(): GameBanana
    {
        return new GameBanana((int) $this->profile()->setting('gamebanana_game'), (int) $this->profile()->setting('gamebanana_category'));
    }

    /** The source a package belongs to, by its id. */
    private function sourceFor(string $id): Thunderstore|Modrinth|Hangar|GoldSrc|Source1|Source2|GameBanana|Workshop
    {
        $profile = $this->profile();

        return match (true) {
            $id === GoldSrc::PACKAGE, $id === GoldSrc::ENGINE => new GoldSrc(),
            $id === Source1::PACKAGE => new Source1(),
            $id === Source2::PACKAGE => new Source2(),
            str_starts_with($id, Workshop::PREFIX), str_starts_with($id, Workshop::COLLECTION) => new Workshop($this->steamKey()),
            str_starts_with($id, GameBanana::PREFIX) => $this->gameBanana(),
            Modrinth::kindOf($id) !== null => new Modrinth((array) $profile->setting('modrinth_loaders', []), (string) Modrinth::kindOf($id)),
            str_starts_with($id, Hangar::PREFIX) => new Hangar(),
            default => new Thunderstore((string) $profile->setting('community'), (array) $profile->setting('hidden_packages', [])),
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
