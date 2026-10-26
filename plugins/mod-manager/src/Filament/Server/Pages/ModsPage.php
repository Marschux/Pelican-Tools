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
use Marschux\ModManager\Models\ModProfile;
use Marschux\ModManager\Sources\Thunderstore;
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

    /** @var array<int, array<string, mixed>> */
    public array $items = [];

    public int $pages = 1;

    /** @var array<string, mixed>|null the package the detail modal shows */
    public ?array $details = null;

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

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->search();
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

    /** Step 2 of the plan: until then the button only says so. */
    public function install(string $id): void
    {
        Notification::make()->title(trans('mod-manager::mods.messages.not_yet'))->info()->send();
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
            $result = $this->source()->search(trim($this->query), $this->category ?: null, $this->ordering, $this->page);
        } catch (Throwable $exception) {
            report($exception);
            $this->items = [];

            Notification::make()->title(trans('mod-manager::mods.messages.search_failed'))->danger()->send();

            return;
        }

        $this->items = $result['items'];
        $this->pages = $result['pages'];
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
