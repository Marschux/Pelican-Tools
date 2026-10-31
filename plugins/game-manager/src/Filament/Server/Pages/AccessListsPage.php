<?php

namespace Marschux\GameManager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Marschux\GameManager\Lists\ListDefinition;
use Marschux\GameManager\Models\ModProfile;

/**
 * The access lists of a game without mods (TeamSpeak): a profile holding only lists. A game with
 * mods has them in the Configs tab of the Mods & Configs page instead.
 */
class AccessListsPage extends Page
{
    use BlockAccessInConflict;

    protected string $view = 'game-manager::pages.access-lists';

    protected static string|BackedEnum|null $navigationIcon = 'tabler-list-check';

    // not "access-lists": that is the old plugin's page, installed alongside until it is removed
    protected static ?string $slug = 'lists';

    protected static ?int $navigationSort = 31;

    /** the file shown, "<list id>:<index>" */
    public string $file = '';

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return parent::canAccess()
            && user()?->can(SubuserPermission::FileReadContent, $server)
            && !ModProfile::forServer($server)?->hasMods()
            && ModProfile::listsFor($server) !== [];
    }

    public static function getNavigationLabel(): string
    {
        return trans('game-manager::lists.page.navigation');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->file = (string) array_key_first($this->fileTabs());
    }

    /** @return array<string, string> key => label, one tab per file */
    public function fileTabs(): array
    {
        return array_map(fn (ListDefinition $definition) => $definition->label, ModProfile::listsFor($this->server()));
    }

    public function switchFile(string $key): void
    {
        // called straight from the browser, so only a file this server actually has
        if (array_key_exists($key, $this->fileTabs())) {
            $this->file = $key;
        }
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }
}
