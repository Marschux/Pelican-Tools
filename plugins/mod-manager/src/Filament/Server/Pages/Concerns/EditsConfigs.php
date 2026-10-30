<?php

namespace Marschux\ModManager\Filament\Server\Pages\Concerns;

use App\Enums\SubuserPermission;
use Marschux\ModManager\Installer;
use Marschux\ModManager\Lists\ListDefinition;
use Marschux\ModManager\Models\AccessList;
use Throwable;

/** The configs tab: the access lists of the egg and the configs mods wrote. Part of ModsPage. */
trait EditsConfigs
{
    /** @return array<string, string> key => label, the egg's access list files - each a pill of the configs tab */
    public function listTabs(): array
    {
        if (!$this->may(SubuserPermission::FileReadContent)) {
            return [];
        }

        return once(fn () => array_map(fn (ListDefinition $definition) => $definition->label, AccessList::definitionsFor($this->server())));
    }

    /** The pill shown: the one picked if this server still has it, else the first list, else the mods' configs. */
    public function configTab(): string
    {
        return isset($this->listTabs()[$this->configTab]) || $this->configTab === 'mods'
            ? $this->configTab
            : (array_key_first($this->listTabs()) ?? 'mods');
    }

    public function setConfigTab(string $key): void
    {
        $this->tab = 'configs';
        $this->configTab = $key;
        $this->configTab = $this->configTab();
        $this->remember();
    }

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
