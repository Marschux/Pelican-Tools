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

    /** Something changed since the last restart from this page. */
    public function needsRestart(): bool
    {
        return (bool) session($this->restartKey(), false);
    }

    private function markRestart(): void
    {
        session([$this->restartKey() => true]);
    }

    private function restartKey(): string
    {
        return 'mod-manager.restart.' . $this->server()->id;
    }
}
