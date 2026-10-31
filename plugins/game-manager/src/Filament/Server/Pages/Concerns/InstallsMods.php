<?php

namespace Marschux\GameManager\Filament\Server\Pages\Concerns;

use App\Enums\SubuserPermission;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Marschux\GameManager\Models\ModInstall;
use Marschux\GameManager\Sources\Workshop;
use Throwable;

/** Installing, updating, switching and removing mods, with the confirmation before. Part of ModsPage. */
trait InstallsMods
{
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
        if ($id === $this->profile()->loaderPackage()) {
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

                Notification::make()->title(trans('game-manager::mods.messages.removed', ['name' => implode(', ', $names)]))->body(trans('game-manager::mods.messages.restart'))->success()->send();
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

    public function updateMod(int $installId): void
    {
        $this->requirePermission(SubuserPermission::FileUpdate);

        $install = $this->findInstall($installId);

        $this->attempt(fn () => $this->installer()->update($install), trans('game-manager::mods.messages.updated', ['name' => $install->name]));
    }

    /** Lets a pinned mod take updates again, without installing anything now. */
    public function unpin(int $installId): void
    {
        $this->requirePermission(SubuserPermission::FileUpdate);

        $this->findInstall($installId)->update(['pinned' => false]);
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
            trans('game-manager::mods.messages.updated', ['name' => $installs->pluck('name')->implode(', ')]),
        );
    }

    public function toggle(int $installId): void
    {
        $this->requirePermission(SubuserPermission::FileUpdate);

        $install = $this->findInstall($installId);
        $enabled = !$install->enabled;

        $this->attempt(
            fn () => $this->installer()->setEnabled($install, $enabled),
            trans($enabled ? 'game-manager::mods.messages.enabled' : 'game-manager::mods.messages.disabled', ['name' => $install->name]),
        );
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

        Notification::make()->title(trans('game-manager::mods.messages.installed', ['name' => $names]))->body(trans('game-manager::mods.messages.restart'))->success()->send();
    }

    private function findInstall(int $id): ModInstall
    {
        return ModInstall::query()->where('server_id', $this->server()->id)->findOrFail($id);
    }
}
