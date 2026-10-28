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
    $unmanaged = $tab === 'installed' ? $this->unmanaged() : [];
    $configs = $tab === 'installed' ? $this->configs() : [];
    // GoldSrc only: the plugins of AMX Mod X's plugins.ini - shown as long as it is on the server
    $amxx = $tab === 'installed' ? $this->amxxPlugins() : [];
    $relations = $tab === 'installed' ? $this->relations() : [];
    $loaderInstall = $tab === 'installed' ? $this->loaderInstall() : null;
    // GoldSrc: ReHLDS, ReGameDLL and ReAPI, offered in a row of their own
    $canEngine = $tab === 'installed' && $this->goldsrc();
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
    </style>
    @if ($this->needsRestart())
        <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;padding:.75rem 1rem;border-radius:.75rem;border:1px solid rgba(234,179,8,.5);background:rgba(234,179,8,.08)">
            <x-filament::icon icon="tabler-refresh-alert" style="width:1.25rem;height:1.25rem;color:rgb(234,179,8)" />
            <span style="flex:1">{{ trans('mod-manager::mods.restart.hint') }}</span>
            @if ($canRestart)
                <x-filament::button size="sm" color="warning" icon="tabler-refresh" wire:click="restart">{{ trans('mod-manager::mods.restart.button') }}</x-filament::button>
            @endif
            <x-filament::icon-button color="gray" icon="tabler-x" wire:click="dismissRestart" :label="trans('mod-manager::mods.restart.dismiss')" />
        </div>
    @endif

    <x-filament::tabs>
        <x-filament::tabs.item icon="tabler-package" :active="$tab === 'installed'" wire:click="setTab('installed')">
            {{ trans('mod-manager::mods.tabs.installed') }}
        </x-filament::tabs.item>
        {{-- one tab per source, and per kind where Modrinth offers several --}}
        @foreach ($this->browseTabs() as $key => $label)
            <x-filament::tabs.item icon="tabler-search" :active="$tab === 'browse' && $key === $this->browseKey()" wire:click="browse({{ $js($key) }})">
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach
        @if ($canInstall && $this->canUpload())
            <x-filament::tabs.item icon="tabler-link" :active="$tab === 'links'" wire:click="setTab('links')">
                {{ trans('mod-manager::mods.tabs.links') }}
            </x-filament::tabs.item>
        @endif
    </x-filament::tabs>

    @if ($tab === 'installed')
        @include('mod-manager::pages.mods.installed-table')

        @if ($installed->isEmpty() && !$unmanaged && !$amxx)
            <x-filament::section>
                <div style="display:flex;flex-direction:column;align-items:center;gap:.75rem;padding:2rem 0;text-align:center">
                    <x-filament::icon icon="tabler-package-off" style="width:2.5rem;height:2.5rem;opacity:.5" />
                    <span style="opacity:.7">{{ trans('mod-manager::mods.nothing_installed') }}</span>
                    {{-- to the first browse tab; Counter-Strike 2 has none, its plugins are uploaded --}}
                    @if ($browseTab = array_key_first($this->browseTabs()))
                        <x-filament::button color="gray" icon="tabler-search" wire:click="browse({{ $js($browseTab) }})">{{ $this->browseTabs()[$browseTab] }}</x-filament::button>
                    @endif
                </div>
            </x-filament::section>
        @endif

        @include('mod-manager::pages.mods.unmanaged')
        @include('mod-manager::pages.mods.loader-plugins')

        @include('mod-manager::pages.mods.configs')
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
            $tab !== 'installed' => 'mod-manager::mods.players.hint',
            $this->goldsrc() => 'mod-manager::mods.players.hint_goldsrc',
            $this->source2() => 'mod-manager::mods.players.hint_source2',
            $this->jarMods() => 'mod-manager::mods.players.hint_plugins',
            default => 'mod-manager::mods.players.hint',
        }) }}</x-slot>

        @php
            $playerList = $tab === 'installed' ? $this->playerList() : '';
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
