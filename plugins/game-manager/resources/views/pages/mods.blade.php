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
    $section = $onInstalled ? $this->section() : null;
    // what was put on the server by hand: plugins on Counter-Strike and Paper, mods elsewhere (Valheim)
    $unmanaged = match ($section) {
        $this->archiveLoader() || $this->jarMods() ? 'plugins' : 'mods' => $this->unmanaged(),
        'maps' => $this->unmanagedMaps(),
        default => [],
    };
    $configTab = $tab === 'configs' ? $this->configTab() : null;
    // the config tab lists them, the gears of the installed mods and plugins point into them
    $configs = $configTab === 'mods' || in_array($section, ['mods', 'plugins'], true) ? $this->configs() : [];
    $onAdd = in_array($tab, ['browse', 'upload', 'links'], true);
    // GoldSrc only: the plugins of AMX Mod X's plugins.ini - shown as long as it is on the server
    $loaderPlugins = $section === 'plugins' ? $this->loaderPlugins() : [];
    $relations = $onInstalled ? $this->relations() : [];
    // the loader's row is in the mods section only
    $loaderInstall = $section === 'mods' ? $this->loaderInstall() : null;
    // GoldSrc: ReHLDS, ReGameDLL and ReAPI, offered in a row of their own
    $canEngine = $section === 'mods' && $this->goldsrc();
    $engineInstall = $canEngine ? $this->engineInstall() : null;
    // and YaPB's bots, a Metamod plugin with a row of its own as well
    $canBots = $canEngine && $this->canBots();
    $botsInstall = $canEngine ? $this->botsInstall() : null;
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
        .mod-nav { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; }
        .mod-pills { display: flex; flex-wrap: wrap; gap: .4rem; padding-left: .75rem; border-left: 1px solid rgba(127,127,127,.3); }
        .mod-pill { display: inline-flex; align-items: center; gap: .3rem; padding: .3rem .65rem; border-radius: 9999px; font-size: .8rem; font-weight: 500; border: 1px solid rgba(127,127,127,.3); background: transparent; color: inherit; opacity: .75; cursor: pointer; transition: background .15s, opacity .15s; }
        .mod-pill:hover { opacity: 1; background: rgba(127,127,127,.12); }
        .mod-pill svg { width: 1rem; height: 1rem; }
        .mod-pill-on, .mod-pill-on:hover { opacity: 1; color: rgb(37,99,235); background: rgba(59,130,246,.12); border-color: rgba(59,130,246,.45); }
        .dark .mod-pill-on { color: rgb(96,165,250); }
        .mod-pill-missing { border: 1.5px dashed rgba(217,119,6,.8); color: rgb(180,83,9); opacity: 1; }
        .dark .mod-pill-missing { border-color: rgba(251,191,36,.7); color: rgb(251,191,36); }
        .mod-pill-missing.mod-pill-on { background: rgba(245,158,11,.14); }
    </style>
    @php
        // "add": one sub tab per source (and per kind where Modrinth offers several), then upload and links
        $addTabs = collect($this->browseTabs())->map(fn ($label, $key) => ['browse', $key, $label, 'tabler-search']);
        if ($canInstall && $this->canUpload()) {
            $addTabs->put('upload', ['upload', null, trans('game-manager::mods.tabs.upload_maps'), 'tabler-upload']);
        }
        if ($canInstall && $this->canLink()) {
            $addTabs->put('links', ['links', null, trans('game-manager::mods.tabs.links'), 'tabler-link']);
        }
        // "add" opens the sub tab used last
        $addFirst = $addTabs[$this->addTab] ?? $addTabs->first();
        $openAdd = fn (array $sub) => $sub[0] === 'browse' ? 'browse(' . $js($sub[1]) . ')' : "setTab('{$sub[0]}')";
    @endphp
    {{-- one line: the main tabs on the left, the pills of the active one right next to them --}}
    <div class="mod-nav">
    <x-filament::tabs style="margin:0">
        <x-filament::tabs.item icon="tabler-package" :active="$onInstalled" wire:click="setTab('installed')">
            {{ trans('game-manager::mods.tabs.installed') }}
        </x-filament::tabs.item>
        @if ($addTabs->isNotEmpty())
            <x-filament::tabs.item icon="tabler-plus" :active="$onAdd" wire:click="{{ $openAdd($addFirst) }}">
                {{ trans('game-manager::mods.tabs.add') }}
            </x-filament::tabs.item>
        @endif
        <x-filament::tabs.item icon="tabler-settings" :active="$tab === 'configs'" wire:click="setTab('configs')">
            {{ trans('game-manager::mods.config.heading') }}
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
            {{-- a list file not on the server yet is dashed, with a plus: saving it creates it --}}
            @php $missingLists = $this->missingLists(); @endphp
            @foreach ($this->listTabs() + ['mods' => trans('game-manager::mods.config.mod_configs')] as $key => $label)
                @php $missing = isset($missingLists[$key]); @endphp
                <button type="button" @class(['mod-pill', 'mod-pill-on' => $configTab === $key, 'mod-pill-missing' => $missing]) wire:click="setConfigTab({{ $js($key) }})" @if ($missing) title="{{ trans('game-manager::lists.messages.missing_file') }}" @endif><x-filament::icon :icon="$key === 'mods' ? 'tabler-puzzle' : ($missing ? 'tabler-plus' : 'tabler-list-check')" />{{ $label }}</button>
            @endforeach
        @endif
    </nav>
    {{-- on every tab, at the far right of the menu --}}
    <div style="margin-left:auto;display:flex;gap:.5rem">
        @if ($canRestart)
            {{-- amber like the restart note while a change waits for it --}}
            <x-filament::button size="sm" :color="$this->needsRestart() ? 'warning' : 'gray'" icon="tabler-refresh" :tooltip="trans('game-manager::mods.restart.header')" label-sr-only wire:click="restart" wire:confirm="{{ trans('game-manager::mods.restart.confirm') }}">{{ trans('game-manager::mods.restart.header') }}</x-filament::button>
        @endif
        <x-filament::button size="sm" color="gray" icon="tabler-users" x-on:click="$dispatch('open-modal', { id: 'mod-players' }); $wire.loadPlayerList()">{{ trans('game-manager::mods.players.button') }}</x-filament::button></div>
    </div>

    @if ($onInstalled)
        @php
            // mods: the loader, the engine and everything but plugins and maps; plugins: what was uploaded or linked, plus the loader's own
            $installed = $installed->filter(fn ($install) => $this->sectionOf($install->package) === $section)->values();
            // what a section does not show is empty already
            $empty = $installed->isEmpty() && !$unmanaged && !$loaderPlugins && !($section === 'mods' && ($loaderInstall || $loader));
        @endphp
        @include('game-manager::pages.mods.installed-table')

        @if ($empty)
            @include('game-manager::pages.mods.empty', ['icon' => $this->sections()[$section][2], 'text' => trans($this->sections()[$section][3]), 'action' => $addTabs->isNotEmpty() ? $openAdd($addFirst) : null])
        @endif

        @include('game-manager::pages.mods.unmanaged')
        @include('game-manager::pages.mods.loader-plugins')
    @elseif ($configTab && $configTab !== 'mods')
        {{-- a new key mounts a fresh editor, reading the other file --}}
        <x-filament::section>
            @livewire('game-manager.access-list-editor', ['server' => \Filament\Facades\Filament::getTenant(), 'file' => $configTab], key("list-$configTab"))
        </x-filament::section>
    @elseif ($tab === 'configs')
        @include('game-manager::pages.mods.configs')
        @unless ($configs)
            @include('game-manager::pages.mods.empty', ['icon' => 'tabler-settings-off', 'text' => trans('game-manager::mods.config.hint')])
        @endunless
    @elseif ($tab === 'upload')
        <x-filament::section>
            @include('game-manager::pages.mods.upload-button')
        </x-filament::section>
    @elseif ($tab === 'links')
        @include('game-manager::pages.mods.links')
    @else
        @include('game-manager::pages.mods.browse')
    @endif

    @include('game-manager::pages.mods.details-modal')
    <x-filament::modal id="mod-config" width="5xl" :close-by-clicking-away="false">
        <x-slot name="heading">{{ $configPath ? $configPath : '' }}</x-slot>
        <x-slot name="description">{{ trans('game-manager::mods.config.restart_note') }}</x-slot>

        <textarea wire:model="configText" @readonly(!$canUpdate) spellcheck="false" rows="24" style="width:100%;font-family:monospace;font-size:.8rem;line-height:1.4;padding:.75rem;border-radius:.5rem;border:1px solid rgba(127,127,127,.3);background:rgba(127,127,127,.06);color:inherit;resize:vertical"></textarea>

        <x-slot name="footerActions">
            @if ($canUpdate)
                <x-filament::button icon="tabler-device-floppy" wire:click="saveConfig">{{ trans('game-manager::mods.config.save') }}</x-filament::button>
            @endif
            <x-filament::button color="gray" x-on:click="close">{{ trans('game-manager::mods.cancel') }}</x-filament::button>
        </x-slot>
    </x-filament::modal>

    {{-- for players: the same mods on their side, to paste into chat or a server description --}}
    <x-filament::modal id="mod-players" width="3xl">
        <x-slot name="heading">{{ trans('game-manager::mods.players.heading') }}</x-slot>
        <x-slot name="description">{{ trans(match (true) {
            $this->goldsrc() => 'game-manager::mods.players.hint_goldsrc',
            $this->source2() => 'game-manager::mods.players.hint_source2',
            $this->jarMods() => 'game-manager::mods.players.hint_plugins',
            default => 'game-manager::mods.players.hint',
        }) }}</x-slot>

        <div x-data="{ copied: false }">
            <textarea readonly x-ref="list" rows="12" style="width:100%;font-family:monospace;font-size:.8rem;padding:.75rem;border-radius:.5rem;border:1px solid rgba(127,127,127,.3);background:rgba(127,127,127,.06);color:inherit">{{ $playerList }}</textarea>
            <div style="display:flex;gap:.5rem;align-items:center;margin-top:.5rem">
                <x-filament::button icon="tabler-copy" x-on:click="navigator.clipboard.writeText($refs.list.value); copied = true; setTimeout(() => copied = false, 2000)">{{ trans('game-manager::mods.players.copy') }}</x-filament::button>
                <span x-show="copied" x-cloak style="color:rgb(34,197,94);font-size:.875rem">{{ trans('game-manager::mods.players.copied') }}</span>
            </div>
        </div>
    </x-filament::modal>

    @include('game-manager::pages.mods.confirm-modal')
</x-filament-panels::page>
