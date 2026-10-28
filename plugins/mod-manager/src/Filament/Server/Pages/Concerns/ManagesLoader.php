<?php

namespace Marschux\ModManager\Filament\Server\Pages\Concerns;

use App\Enums\SubuserPermission;
use App\Repositories\Daemon\DaemonFileRepository;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Marschux\ModManager\Models\ModInstall;
use Marschux\ModManager\Profiles\Presets;
use Marschux\ModManager\Sources\GoldSrc;
use Throwable;

/** The loader and, on GoldSrc, the engine: installing and removing them. Part of ModsPage. */
trait ManagesLoader
{
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

    /** The loader's own row in the installed table, null until it is installed through this page. */
    public function loaderInstall(): ?ModInstall
    {
        $loader = $this->installer()->installs()->firstWhere('package', (string) $this->profile()->setting('loader_package'));

        if ($loader) {
            $this->installer()->fillSizes(new Collection([$loader]));
        }

        return $loader;
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
}
