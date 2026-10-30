{{--
    Plain blade components and inline styles: Filament page actions did not render in this panel,
    and only the utility classes Filament itself uses exist in its stylesheet.
--}}
@php
    // browsing only needs which versions are installed - the rest asks Wings and stays on its tab
    $onInstalled = $tab === 'installed';
    $loader = $onInstalled ? $this->loader() : null;
    $installed = $onInstalled ? $this->installed() : collect();
    $versions = $this->installedVersions();
    $updates = $onInstalled ? $this->updates() : [];
    // the installed tab shows one section at a time and only asks Wings for that one's files
    $section = $onInstalled ? (isset($this->sections()[$this->section]) ? $this->section : 'mods') : null;
    $unmanaged = $section === 'plugins' ? $this->unmanaged() : [];
    $configTab = $tab === 'configs' ? $this->configTab() : null;
    $configs = $configTab === 'mods' ? $this->configs() : [];
    $onAdd = in_array($tab, ['browse', 'upload', 'links'], true);
    // GoldSrc only: the plugins of AMX Mod X's plugins.ini - shown as long as it is on the server
    $loaderPlugins = $section === 'plugins' ? $this->loaderPlugins() : [];
    $relations = $tab === 'installed' ? $this->relations() : [];
    $loaderInstall = $tab === 'installed' ? $this->loaderInstall() : null;
    // GoldSrc: ReHLDS, ReGameDLL and ReAPI, offered in a row of their own
    $canEngine = $section === 'mods' && $this->goldsrc();
    $engineInstall = $canEngine ? $this->engineInstall() : null;
    // without a running loader no mod loads, switched on or not
    $loaderRuns = !$loader || ($loader['installed'] && $loader['started']);
    $card = 'display:flex;flex-direction:column;gap:.75rem;padding:1rem;border:1px solid rgba(127,127,127,.2);border-radius:.75rem';
    $icon = 'flex:none;border-radius:.5rem;object-fit:cover;background:rgba(127,127,127,.15)';
    $muted = 'font-size:.8rem;opacity:.7';
    $js = fn (string $value) => \Illuminate\Support\Js::from($value);
    // buttons a subuser may not press are left out, the actions check again
    $permission = \App\Enums\SubuserPermission::class;
    $canInstall = $this->may($permission::FileCreate);
    $canUpdate = $this->may($permission::FileUpdate);
    $canDelete = $this->may($permission::FileDelete);
    $canLoader = $this->may($permission::FileCreate, $permission::StartupUpdate);
    $canRemoveLoader = $this->may($permission::FileDelete, $permission::StartupUpdate);
    $canReadConfig = $this->may($permission::FileReadContent);
    $canRestart = $this->may($permission::ControlRestart);
@endphp

<x-filament-panels::page>
    {{-- "installed" next to buttons: as tall as they are, text in the middle - filament's badge keeps its own height --}}
    <style>
        /* the buttons and the filter in a section header share one height */
        .mod-head .fi-btn, .mod-head .fi-input-wrp { height: 2.25rem; box-sizing: border-box; }
        .mod-head .fi-input-wrp select { height: 100%; padding-block: 0; }
        .mod-installed { align-self: stretch; display: inline-flex; align-items: center; gap: .35rem; padding: 0 .75rem; border-radius: .5rem; font-size: .875rem; font-weight: 500; color: rgb(21,128,61); background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.35); }
        .dark .mod-installed { color: rgb(74,222,128); }
        .mod-slot { display: inline-flex; justify-content: center; width: 2.5rem; }
        {{-- the row actions: one square button each - gear in the text colour, switch green while on, bin red --}}
        .mod-action { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; border-radius: .5rem; border: 1px solid rgba(127,127,127,.35); background: transparent; color: inherit; cursor: pointer; transition: background .15s; }
        .mod-action:hover { background: rgba(127,127,127,.15); }
        .mod-action:disabled { opacity: .3; cursor: not-allowed; background: transparent; }
        .mod-action svg { width: 1.1rem; height: 1.1rem; }
        .mod-action.mod-on { color: rgb(22,163,74); border-color: rgba(34,197,94,.45); }
        .mod-action[aria-pressed="false"] { opacity: .6; }
        .mod-action.mod-danger { color: rgb(220,38,38); border-color: rgba(239,68,68,.45); }
        .dark .mod-action.mod-on { color: rgb(74,222,128); }
        .dark .mod-action.mod-danger { color: rgb(248,113,113); }
        .mod-action.mod-update { color: rgb(37,99,235); border-color: rgba(59,130,246,.45); background: rgba(59,130,246,.1); }
        .mod-action.mod-update:hover { background: rgba(59,130,246,.2); }
        .dark .mod-action.mod-update { color: rgb(96,165,250); }
        .mod-drop { display: flex; flex-direction: column; align-items: center; gap: .6rem; padding: 2.5rem 1rem; text-align: center; border: 2px dashed rgba(127,127,127,.35); border-radius: .75rem; cursor: pointer; transition: background .15s, border-color .15s; }
        .mod-drop:hover, .mod-drop-over { border-color: rgb(59,130,246); background: rgba(59,130,246,.08); }
        .mod-nav { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem 1rem; }
        .mod-pills { display: flex; flex-wrap: wrap; gap: .5rem; padding-left: 1rem; border-left: 1px solid rgba(127,127,127,.3); }
        .mod-pill { display: inline-flex; align-items: center; gap: .35rem; padding: .3rem .8rem; border-radius: 9999px; font-size: .8rem; font-weight: 500; border: 1px solid rgba(127,127,127,.3); background: transparent; color: inherit; opacity: .75; cursor: pointer; transition: background .15s, opacity .15s; }
        .mod-pill:hover { opacity: 1; background: rgba(127,127,127,.12); }
        .mod-pill svg { width: 1rem; height: 1rem; }
        .mod-pill-on, .mod-pill-on:hover { opacity: 1; color: rgb(37,99,235); background: rgba(59,130,246,.12); border-color: rgba(59,130,246,.45); }
        .dark .mod-pill-on { color: rgb(96,165,250); }
    </style>
    @php
        // "add": one sub tab per source (and per kind where Modrinth offers several), then upload and links
        $addTabs = collect($this->browseTabs())->map(fn ($label, $key) => ['browse', $key, $label, 'tabler-search']);
        if ($canInstall && $this->canUpload()) {
            $addTabs->put('upload', ['upload', null, trans('mod-manager::mods.tabs.upload_maps'), 'tabler-upload']);
        }
        if ($canInstall && $this->canLink()) {
            $addTabs->put('links', ['links', null, trans('mod-manager::mods.tabs.links'), 'tabler-link']);
        }
        // "add" opens the sub tab used last
        $addFirst = $addTabs[$this->addTab] ?? $addTabs->first();
        $openAdd = fn (array $sub) => $sub[0] === 'browse' ? 'browse(' . $js($sub[1]) . ')' : "setTab('{$sub[0]}')";
    @endphp
    {{-- one line: the main tabs on the left, the pills of the active one right next to them --}}
    <div class="mod-nav">
    <x-filament::tabs style="margin:0">
        <x-filament::tabs.item icon="tabler-package" :active="$onInstalled" wire:click="setTab('installed')">
            {{ trans('mod-manager::mods.tabs.installed') }}
        </x-filament::tabs.item>
        @if ($addTabs->isNotEmpty())
            <x-filament::tabs.item icon="tabler-plus" :active="$onAdd" wire:click="{{ $openAdd($addFirst) }}">
                {{ trans('mod-manager::mods.tabs.add') }}
            </x-filament::tabs.item>
        @endif
        <x-filament::tabs.item icon="tabler-settings" :active="$tab === 'configs'" wire:click="setTab('configs')">
            {{ trans('mod-manager::mods.config.heading') }}
        </x-filament::tabs.item>
    </x-filament::tabs>

    {{-- the second level: small pills, so it does not look like a second set of the tabs --}}
    <nav class="mod-pills">
        @if ($onInstalled)
            @foreach ($this->sections() as $key => [$label, $icon])
                <button type="button" @class(['mod-pill', 'mod-pill-on' => $section === $key]) wire:click="setSection('{{ $key }}')"><x-filament::icon :icon="$icon" />{{ trans($label) }}</button>
            @endforeach
        @elseif ($onAdd)
            @foreach ($addTabs as $sub)
                <button type="button" @class(['mod-pill', 'mod-pill-on' => $tab === $sub[0] && ($sub[0] !== 'browse' || $sub[1] === $this->browseKey())]) wire:click="{{ $openAdd($sub) }}"><x-filament::icon :icon="$sub[3]" />{{ $sub[2] }}</button>
            @endforeach
        @else
            {{-- the egg's access lists (bans, whitelists, server.cfg), then what the mods wrote --}}
            @foreach ($this->listTabs() + ['mods' => trans('mod-manager::mods.config.mod_configs')] as $key => $label)
                <button type="button" @class(['mod-pill', 'mod-pill-on' => $configTab === $key]) wire:click="setConfigTab({{ $js($key) }})"><x-filament::icon :icon="$key === 'mods' ? 'tabler-puzzle' : 'tabler-list-check'" />{{ $label }}</button>
            @endforeach
        @endif
    </nav>
    {{-- on every tab, at the far right of the menu --}}
    <div style="margin-left:auto;display:flex;gap:.5rem">
        @if ($canRestart)
            {{-- amber like the restart note while a change waits for it --}}
            <x-filament::button size="sm" :color="$this->needsRestart() ? 'warning' : 'gray'" icon="tabler-refresh" :tooltip="trans('mod-manager::mods.restart.header')" label-sr-only wire:click="restart" wire:confirm="{{ trans('mod-manager::mods.restart.confirm') }}">{{ trans('mod-manager::mods.restart.header') }}</x-filament::button>
        @endif
        <x-filament::button size="sm" color="gray" icon="tabler-users" x-on:click="$dispatch('open-modal', { id: 'mod-players' })">{{ trans('mod-manager::mods.players.button') }}</x-filament::button></div>
    </div>

    @if ($onInstalled)
        @php
            // mods: the loader, the engine and everything but plugins and maps; plugins: what was uploaded or linked, plus the loader's own
            $sectionOf = fn ($install) => match ($this->type($install->package)[0]) { 'plugin' => 'plugins', 'map' => 'maps', default => 'mods' };
            $installed = $installed->filter(fn ($install) => $sectionOf($install) === $section)->values();
            $empty = match ($section) {
                'mods' => $installed->isEmpty() && !$loaderInstall && !$loader,
                'plugins' => $installed->isEmpty() && !$unmanaged && !$loaderPlugins,
                default => $installed->isEmpty(),
            };
        @endphp
        @include('mod-manager::pages.mods.installed-table')

        @if ($empty)
            <x-filament::section>
                <div style="display:flex;flex-direction:column;align-items:center;gap:.75rem;padding:2rem 0;text-align:center">
                    <x-filament::icon :icon="['plugins' => 'tabler-plug-off', 'maps' => 'tabler-map-off'][$section] ?? 'tabler-package-off'" style="width:2.5rem;height:2.5rem;opacity:.5" />
                    <span style="opacity:.7">{{ trans(['plugins' => 'mod-manager::mods.nothing_plugins', 'maps' => 'mod-manager::mods.nothing_maps'][$section] ?? 'mod-manager::mods.nothing_installed') }}</span>
                    @if ($addTabs->isNotEmpty())
                        <x-filament::button color="gray" icon="tabler-plus" wire:click="{{ $openAdd($addFirst) }}">{{ trans('mod-manager::mods.tabs.add') }}</x-filament::button>
                    @endif
                </div>
            </x-filament::section>
        @endif

        @include('mod-manager::pages.mods.unmanaged')
        @include('mod-manager::pages.mods.loader-plugins')
    @elseif ($configTab && $configTab !== 'mods')
        {{-- a new key mounts a fresh editor, reading the other file --}}
        <x-filament::section>
            @livewire('mod-manager.access-list-editor', ['server' => \Filament\Facades\Filament::getTenant(), 'file' => $configTab], key("list-$configTab"))
        </x-filament::section>
    @elseif ($tab === 'configs')
        @include('mod-manager::pages.mods.configs')
        @unless ($configs)
            <x-filament::section>
                <div style="display:flex;flex-direction:column;align-items:center;gap:.75rem;padding:2rem 0;text-align:center">
                    <x-filament::icon icon="tabler-settings-off" style="width:2.5rem;height:2.5rem;opacity:.5" />
                    <span style="opacity:.7">{{ trans('mod-manager::mods.config.hint') }}</span>
                </div>
            </x-filament::section>
        @endunless
    @elseif ($tab === 'upload')
        <x-filament::section>
            @include('mod-manager::pages.mods.upload-button')
        </x-filament::section>
    @elseif ($tab === 'links')
        @include('mod-manager::pages.mods.links')
    @else
        @include('mod-manager::pages.mods.browse')
    @endif

    @include('mod-manager::pages.mods.details-modal')
    <x-filament::modal id="mod-config" width="5xl" :close-by-clicking-away="false">
        <x-slot name="heading">{{ $configPath ? $configPath : '' }}</x-slot>
        <x-slot name="description">{{ trans('mod-manager::mods.config.restart_note') }}</x-slot>

        <textarea wire:model="configText" @readonly(!$canUpdate) spellcheck="false" rows="24" style="width:100%;font-family:monospace;font-size:.8rem;line-height:1.4;padding:.75rem;border-radius:.5rem;border:1px solid rgba(127,127,127,.3);background:rgba(127,127,127,.06);color:inherit;resize:vertical"></textarea>

        <x-slot name="footerActions">
            @if ($canUpdate)
                <x-filament::button icon="tabler-device-floppy" wire:click="saveConfig">{{ trans('mod-manager::mods.config.save') }}</x-filament::button>
            @endif
            <x-filament::button color="gray" x-on:click="close">{{ trans('mod-manager::mods.cancel') }}</x-filament::button>
        </x-slot>
    </x-filament::modal>

    {{-- for players: the same mods on their side, to paste into chat or a server description --}}
    <x-filament::modal id="mod-players" width="3xl">
        <x-slot name="heading">{{ trans('mod-manager::mods.players.heading') }}</x-slot>
        <x-slot name="description">{{ trans(match (true) {
            $this->goldsrc() => 'mod-manager::mods.players.hint_goldsrc',
            $this->source2() => 'mod-manager::mods.players.hint_source2',
            $this->jarMods() => 'mod-manager::mods.players.hint_plugins',
            default => 'mod-manager::mods.players.hint',
        }) }}</x-slot>

        @php
            $playerList = $this->playerList();
        @endphp
        <div x-data="{ copied: false }">
            <textarea readonly x-ref="list" rows="12" style="width:100%;font-family:monospace;font-size:.8rem;padding:.75rem;border-radius:.5rem;border:1px solid rgba(127,127,127,.3);background:rgba(127,127,127,.06);color:inherit">{{ $playerList }}</textarea>
            <div style="display:flex;gap:.5rem;align-items:center;margin-top:.5rem">
                <x-filament::button icon="tabler-copy" x-on:click="navigator.clipboard.writeText($refs.list.value); copied = true; setTimeout(() => copied = false, 2000)">{{ trans('mod-manager::mods.players.copy') }}</x-filament::button>
                <span x-show="copied" x-cloak style="color:rgb(34,197,94);font-size:.875rem">{{ trans('mod-manager::mods.players.copied') }}</span>
            </div>
        </div>
    </x-filament::modal>

    @include('mod-manager::pages.mods.confirm-modal')
</x-filament-panels::page>
