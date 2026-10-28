<?php

namespace Marschux\ModManager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Marschux\ModManager\Filament\Server\Pages\Concerns\AddsPlugins;
use Marschux\ModManager\Filament\Server\Pages\Concerns\BrowsesSources;
use Marschux\ModManager\Filament\Server\Pages\Concerns\EditsConfigs;
use Marschux\ModManager\Filament\Server\Pages\Concerns\HandlesRestart;
use Marschux\ModManager\Filament\Server\Pages\Concerns\InstallsMods;
use Marschux\ModManager\Filament\Server\Pages\Concerns\ManagesLoader;
use Marschux\ModManager\Filament\Server\Pages\Concerns\ShowsInstalled;
use Marschux\ModManager\GameVersion;
use Marschux\ModManager\Installer;
use Marschux\ModManager\Models\ModProfile;
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
    use BrowsesSources;
    use InstallsMods;
    use ManagesLoader;
    use AddsPlugins;
    use EditsConfigs;
    use ShowsInstalled;
    use HandlesRestart;

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

    /** the update check asks every source - the page shows first and runs it right after (wire:init) */
    public bool $updatesChecked = false;

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

    /** A GitHub release link pasted in: the asset to install, or the choice when there are several. */
    public string $githubLink = '';

    /** @var array<int, array{name: string, size: int}> */
    public array $githubAssets = [];

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

    /** Plugins rather than mods: single files from Modrinth or Hangar, nothing players need. */
    public function jarMods(): bool
    {
        return $this->installer()->jarMods();
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

    private function installer(): Installer
    {
        return new Installer($this->server(), $this->profile());
    }

    /** @return array{version: string, from: string}|null */
    public function gameVersion(): ?array
    {
        return once(fn () => GameVersion::of($this->server(), $this->profile()));
    }

    /** @return array<string, string> the profile's sources, key => name, for the switch above the search */
    /** The Steam Web API key in the server's startup variable WORKSHOP_AUTHKEY, '' without. */
    private function steamKey(): string
    {
        return once(fn () => $this->installer()->steamKey());
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
