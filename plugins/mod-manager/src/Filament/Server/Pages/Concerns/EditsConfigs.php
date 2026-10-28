<?php

namespace Marschux\ModManager\Filament\Server\Pages\Concerns;

use App\Enums\SubuserPermission;
use Marschux\ModManager\Installer;
use Throwable;

/** The config editor. Part of ModsPage. */
trait EditsConfigs
{
    /** A config the page must not delete: AMX Mod X would not write it again. */
    public function keepsConfig(string $path): bool
    {
        return $this->goldsrc() && Installer::amxxCore($path);
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

    /** @return string[] */
    public function configs(): array
    {
        return once(fn () => $this->installer()->configs());
    }

    public function configFor(string $modName): ?string
    {
        return Installer::configFor($modName, $this->configs());
    }
}
