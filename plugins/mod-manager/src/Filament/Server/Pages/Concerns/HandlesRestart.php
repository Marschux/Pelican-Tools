<?php

namespace Marschux\ModManager\Filament\Server\Pages\Concerns;

use App\Enums\SubuserPermission;
use App\Repositories\Daemon\DaemonServerRepository;
use Filament\Notifications\Notification;
use Throwable;

/** The note that changes need a restart, and the restart button. Part of ModsPage. */
trait HandlesRestart
{
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

    /** Something changed since the server last started - from this page, the console or anywhere else. */
    public function needsRestart(): bool
    {
        $marked = session($this->restartKey());

        if ($marked === null) {
            return false;
        }

        // started again since: the server's start time moved past the one noted with the change
        // (30s leeway, Pelican caches the uptime for 15s)
        $started = $this->startedAt();

        if ($started !== null && $started > (int) $marked + 30) {
            session()->forget($this->restartKey());

            return false;
        }

        return true;
    }

    private function markRestart(): void
    {
        // a server that is off notes 0: any later start counts
        session([$this->restartKey() => $this->startedAt() ?? 0]);
    }

    /** When the running server started (unix time), null while it is off or Wings does not answer. */
    private function startedAt(): ?int
    {
        try {
            $uptime = (int) ($this->server()->retrieveResources()['uptime'] ?? 0);
        } catch (Throwable) {
            return null;
        }

        return $uptime > 0 ? time() - intdiv($uptime, 1000) : null;
    }

    private function restartKey(): string
    {
        return 'mod-manager.restart.' . $this->server()->id;
    }
}
