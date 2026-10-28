<?php

namespace Marschux\ServerNavigation\Livewire;

use App\Models\Server;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Marschux\ServerNavigation\Navigation\Entries;

/**
 * At the end of the server's sidebar: a pencil for admins and the server's owner that turns the
 * sidebar into a list to drag around - the order, and what goes under "more".
 */
class SidebarEditor extends Component
{
    // kept from the page it was put on: an update request of this component has no tenant
    #[Locked]
    public ?int $serverId = null;

    public bool $editing = false;

    public function mount(): void
    {
        $server = Filament::getTenant();
        $this->serverId = $server instanceof Server ? $server->id : null;
    }

    public function edit(): void
    {
        $this->editing = $this->server() !== null;
    }

    public function cancel(): void
    {
        $this->editing = false;
    }

    /** @param  string[]  $order */
    public function save(array $order): void
    {
        $server = $this->server();

        if ($server === null) {
            return;
        }

        Entries::save($server, $order);

        // the sidebar is built with the page, only a reload shows the new one
        $this->js('window.location.reload()');
    }

    public function render(): View
    {
        $server = $this->server();

        // an update request of this component has no tenant, the pages' canAccess() needs one
        if ($server && $this->editing) {
            Filament::setTenant($server);
        }

        return view('server-navigation::sidebar-editor', [
            'may' => $server !== null,
            'order' => $this->editing && $server ? Entries::order($server) : [],
            'labels' => $this->editing ? Entries::all() : [],
        ]);
    }

    /** The server, when the user may arrange its sidebar: admins and the owner, not subusers. */
    private function server(): ?Server
    {
        $server = $this->serverId ? Server::find($this->serverId) : null;
        $user = auth()->user();

        return $server && $user && ($user->isRootAdmin() || $server->owner_id === $user->id) ? $server : null;
    }
}
