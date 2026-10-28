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
    // GoldSrc maps and jar plugins never have any - the column only costs width there
    $hasRelations = collect($relations)->contains(fn (array $relation) => $relation['requires'] || $relation['needed_by']);
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
    </x-filament::tabs>

    @if ($tab === 'installed')
        @if ($installed->isNotEmpty() || $loaderInstall || $loader)
            @php
                $updatable = $installed->filter(fn ($install) => isset($updates[$install->package]) && !$install->pinned)->count();
                // filter by type, in the browser, loader and engine rows count too
                $types = collect([$loader ? 'loader' : null, $engineInstall || $canEngine ? 'engine' : null])
                    ->concat($installed->map(fn ($install) => $this->type($install->package)[0])->sort())
                    ->filter()->unique()->values();
            @endphp
            {{-- the section brings an x-data of its own, so the filter state lives around it --}}
            <div x-data="{ type: '' }" style="display:contents">
            <x-filament::section>
                <x-slot name="afterHeader">
                    <div class="mod-head" style="display:flex;gap:.5rem">
                        @if ($canInstall && $this->canUpload())
                            {{--
                                picking the file sends it straight to Wings, like Pelican's file manager - not through
                                the panel, so PHP's upload limit does not apply - and then the page installs it
                            --}}
                            <div x-data="{
                                busy: false,
                                async send(file) {
                                    if (!file) return;
                                    this.busy = true;
                                    try {
                                        const target = await $wire.uploadTarget();
                                        const url = new URL(target.url);
                                        url.searchParams.append('directory', target.directory);
                                        const body = new FormData();
                                        body.append('files', file);
                                        const response = await fetch(url, { method: 'POST', body });
                                        if (!response.ok) {
                                            const answer = await response.json().catch(() => ({}));
                                            throw new Error(answer.error || `HTTP ${response.status}`);
                                        }
                                        await $wire.installUploaded(target.token, file.name);
                                    } catch (error) {
                                        await $wire.uploadFailed(String(error?.message ?? error));
                                    } finally {
                                        this.busy = false;
                                    }
                                },
                            }">
                                <x-filament::button size="sm" color="gray" icon="tabler-upload" tag="label" style="cursor:pointer" x-bind:class="busy && 'opacity-50 pointer-events-none'">
                                    <span x-show="!busy">{{ trans('mod-manager::mods.upload.button') }}</span>
                                    <span x-show="busy" x-cloak>{{ trans('mod-manager::mods.upload.busy') }}</span>
                                    <input type="file" accept="{{ $this->source2() ? '.zip,.dll' : '.amxx,.zip' }}" style="display:none" x-on:change="send($event.target.files[0]); $event.target.value = ''">
                                </x-filament::button>
                            </div>
                        @endif
                        <x-filament::button size="sm" color="gray" icon="tabler-users" x-on:click="$dispatch('open-modal', { id: 'mod-players' })">{{ trans('mod-manager::mods.players.button') }}</x-filament::button>
                        @if ($updatable && $canUpdate)
                            <x-filament::button size="sm" icon="tabler-refresh" wire:click="updateAll">{{ trans('mod-manager::mods.update_all', ['count' => $updatable]) }}</x-filament::button>
                        @endif
                    </div>
                </x-slot>
                {{-- the type filter sits left, next to the heading --}}
                <x-slot name="heading">
                    <div class="mod-head" style="display:flex;align-items:center;gap:1rem;font-weight:normal">
                        <span style="font-weight:600">{{ trans('mod-manager::mods.tabs.installed') }} ({{ $installed->count() }})</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select x-model="type" :aria-label="trans('mod-manager::mods.columns.type')">
                                <option value="">{{ trans('mod-manager::mods.all_types') }}</option>
                                @foreach ($types as $key)
                                    <option value="{{ $key }}">{{ trans("mod-manager::mods.types.$key") }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                </x-slot>

                {{-- Counter-Strike 2: a workshop map by id or link; without RockTheVote only the start map is played --}}
                @if ($canInstall && $this->source2())
                    <form wire:submit="addWorkshopMap" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.5rem">
                        <x-filament::input.wrapper prefix-icon="tabler-map" style="flex:1 1 20rem">
                            <x-filament::input type="text" wire:model="workshopLink" :placeholder="trans('mod-manager::mods.workshop.placeholder')" />
                        </x-filament::input.wrapper>
                        <x-filament::button type="submit" color="gray" icon="tabler-plus" wire:loading.attr="disabled" wire:target="addWorkshopMap">{{ trans('mod-manager::mods.workshop.add_map') }}</x-filament::button>
                    </form>
                    @if (!$this->hasMapVote() && $installed->contains(fn ($install) => str_starts_with($install->package, \Marschux\ModManager\Sources\Workshop::PREFIX)))
                        <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin-bottom:.5rem;font-size:.875rem;color:rgb(234,179,8)">
                            <span style="flex:1 1 20rem">{{ trans('mod-manager::mods.workshop.vote_hint') }}</span>
                            <x-filament::button size="sm" icon="tabler-download" wire:click="setUpMapVote" wire:loading.attr="disabled">{{ trans('mod-manager::mods.workshop.vote_setup') }}</x-filament::button>
                        </div>
                    @endif
                @endif
                {{-- Counter-Strike 2: a plugin from a GitHub release, pasted as a link; several zips in it - pick one --}}
                @if ($canInstall && $this->source2())
                    <form wire:submit="installGitHub" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:1rem">
                        <x-filament::input.wrapper prefix-icon="tabler-brand-github" style="flex:1 1 20rem">
                            <x-filament::input type="text" wire:model="githubLink" :placeholder="trans('mod-manager::mods.github.placeholder')" />
                        </x-filament::input.wrapper>
                        <x-filament::button type="submit" icon="tabler-download" wire:loading.attr="disabled" wire:target="installGitHub">{{ trans('mod-manager::mods.install') }}</x-filament::button>
                    </form>
                    @if ($githubAssets)
                        <div style="display:flex;flex-direction:column;gap:.5rem;margin-bottom:1rem">
                            <span style="font-size:.875rem;{{ $muted }}">{{ trans('mod-manager::mods.github.pick') }}</span>
                            <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                                @foreach ($githubAssets as $asset)
                                    <x-filament::button size="sm" color="gray" icon="tabler-file-zip" wire:click="installGitHub({{ $js($asset['name']) }})" wire:loading.attr="disabled">
                                        {{ $asset['name'] }} ({{ \Illuminate\Support\Number::fileSize($asset['size'], 1) }})
                                    </x-filament::button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif
                {{-- a table, scrolling sideways when the page gets narrow --}}
                {{-- icon buttons have a negative margin, so the last column keeps some room on the right, else the edge alone makes it scroll --}}
                <div style="overflow-x:auto;overflow-y:hidden">
                    <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                        <thead>
                            <tr style="text-align:left;{{ $muted }}">
                                <th style="padding:.4rem .5rem .4rem 0;font-weight:500">{{ trans('mod-manager::mods.columns.mod') }}</th>
                                <th style="padding:.4rem .5rem;font-weight:500">{{ trans('mod-manager::mods.columns.type') }}</th>
                                <th style="padding:.4rem .5rem;font-weight:500">{{ trans('mod-manager::mods.columns.version') }}</th>
                                <th style="padding:.4rem .5rem;font-weight:500;text-align:right">{{ trans('mod-manager::mods.columns.size') }}</th>
                                @if ($hasRelations)
                                <th style="padding:.4rem .5rem;font-weight:500">{{ trans('mod-manager::mods.columns.dependencies') }}</th>
                                @endif
                                <th style="padding:.4rem .5rem;font-weight:500">{{ trans('mod-manager::mods.columns.status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @if ($loader && !$loaderInstall)
                                {{-- the loader before it was installed through this page: what is wrong and the button to fix it --}}
                                <tr wire:key="loader-row" x-show="!type || type === 'loader'" style="border-top:1px solid rgba(127,127,127,.15);vertical-align:middle">
                                    <td style="padding:.6rem .5rem .6rem 0">
                                        <div style="display:flex;gap:.75rem;align-items:center;min-width:12rem">
                                            <div style="{{ $icon }};width:2.25rem;height:2.25rem;display:flex;align-items:center;justify-content:center">
                                                <x-filament::icon icon="tabler-plug" style="width:1.25rem;height:1.25rem;opacity:.6" />
                                            </div>
                                            <div style="min-width:0">
                                                <div style="font-weight:600">{{ $loader['name'] }}</div>
                                                <div style="{{ $muted }}">{{ trans('mod-manager::mods.loader.hint', ['loader' => $loader['name']]) }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="padding:.6rem .5rem">
                                        <x-filament::badge color="info" size="sm" icon="tabler-plug" style="width:max-content">{{ trans('mod-manager::mods.types.loader') }}</x-filament::badge>
                                    </td>
                                    <td style="padding:.6rem .5rem">–</td>
                                    <td style="padding:.6rem .5rem;text-align:right">–</td>
                                    @if ($hasRelations)
                                    <td style="padding:.6rem .5rem;{{ $muted }}">–</td>
                                    @endif
                                    <td style="padding:.6rem .5rem;min-width:8rem">
                                        <div style="display:flex;gap:.25rem;flex-wrap:wrap">
                                                @if ($loader['installed'])
                                                    <x-filament::badge color="warning" size="sm" icon="tabler-alert-triangle">{{ trans('mod-manager::mods.loader.not_started') }}</x-filament::badge>
                                                @else
                                                    <x-filament::badge color="danger" size="sm" icon="tabler-x">{{ trans('mod-manager::mods.loader.missing') }}</x-filament::badge>
                                                @endif
                                        </div>
                                    </td>
                                    <td style="padding:.6rem .5rem">
                                        <div style="display:flex;gap:.25rem;align-items:center;justify-content:flex-end">
                                                @if ($canLoader)
                                                    <x-filament::button size="xs" :icon="$loader['installed'] ? 'tabler-tool' : 'tabler-download'" wire:click="installLoader" wire:loading.attr="disabled" style="white-space:nowrap">
                                                        {{ trans($loader['installed'] ? 'mod-manager::mods.loader.repair' : 'mod-manager::mods.install') }}
                                                    </x-filament::button>
                                                @endif
                                        </div>
                                    </td>
                                </tr>
                            @endif
                            {{-- what the profile offers comes first: loader, then engine, installed or not --}}
                            @foreach (collect([$loaderInstall, $engineInstall ?? ($canEngine ? 'engine' : null)])->filter()->concat($installed) as $install)
                                @if ($install === 'engine')
                                    {{-- GoldSrc: ReHLDS, ReGameDLL and ReAPI are offered, never needed --}}
                                    <tr wire:key="engine-row" x-show="!type || type === 'engine'" style="border-top:1px solid rgba(127,127,127,.15);vertical-align:middle">
                                        <td style="padding:.6rem .5rem .6rem 0">
                                            <div style="display:flex;gap:.75rem;align-items:center;min-width:12rem">
                                                <div style="{{ $icon }};width:2.25rem;height:2.25rem;display:flex;align-items:center;justify-content:center">
                                                    <x-filament::icon icon="tabler-engine" style="width:1.25rem;height:1.25rem;opacity:.6" />
                                                </div>
                                                <div style="min-width:0;max-width:22rem">
                                                    <div style="font-weight:600">ReHLDS + ReGameDLL + ReAPI</div>
                                                    <div style="{{ $muted }}">{{ trans('mod-manager::mods.engine.hint') }}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="padding:.6rem .5rem">
                                            <x-filament::badge color="info" size="sm" icon="tabler-engine" style="width:max-content">{{ trans('mod-manager::mods.types.engine') }}</x-filament::badge>
                                        </td>
                                        <td style="padding:.6rem .5rem">–</td>
                                        <td style="padding:.6rem .5rem;text-align:right">–</td>
                                        @if ($hasRelations)
                                        <td style="padding:.6rem .5rem;{{ $muted }}">–</td>
                                        @endif
                                        <td style="padding:.6rem .5rem;min-width:8rem">
                                            <x-filament::badge color="gray" size="sm">{{ trans('mod-manager::mods.engine.optional') }}</x-filament::badge>
                                        </td>
                                        <td style="padding:.6rem .5rem">
                                            <div style="display:flex;gap:.25rem;align-items:center;justify-content:flex-end">
                                                @if ($canInstall && $canUpdate)
                                                    <x-filament::button size="xs" icon="tabler-download" wire:click="installEngine" wire:loading.attr="disabled" style="white-space:nowrap">{{ trans('mod-manager::mods.install') }}</x-filament::button>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                    @continue
                                @endif
                                @php
                                    // the loader: no switch and no remove, without it no mod runs
                                    $isLoader = $install->is($loaderInstall);
                                    // the engine: no switch and no config, removing it brings Valve's files back
                                    $isEngine = $engineInstall && $install->is($engineInstall);
                                    $relation = $relations[$install->package] ?? ['requires' => [], 'missing' => [], 'needed_by' => []];
                                    // only plugins and mods write configs, packs do not
                                    // maps from GameBanana have none either
                                    // Counter-Strike 2's workshop maps: nothing on disk, on means in RockTheVote's maplist.txt
                                    $isWorkshop = str_starts_with($install->package, \Marschux\ModManager\Sources\Workshop::PREFIX);
                                    $workshopId = $isWorkshop ? substr($install->package, strlen(\Marschux\ModManager\Sources\Workshop::PREFIX)) : null;
                                    $isMap = $isWorkshop || str_starts_with($install->package, \Marschux\ModManager\Sources\GameBanana::PREFIX);
                                    $cycle = $isWorkshop ? 'workshop' : 'mapcycle';
                                    $hasConfigs = \Marschux\ModManager\Installer::kind($install->package) === 'plugin' && !$isMap && !$isEngine;
                                    $config = $hasConfigs ? \Marschux\ModManager\Installer::configFor($install->name, $configs) : null;
                                    // the GoldSrc loader's settings are AMX Mod X's main config
                                    if ($isLoader && $this->canUpload()) {
                                        $config = collect($configs)->first(fn ($path) => str_ends_with($path, $this->goldsrc() ? '/amxx.cfg' : '/core.json'));
                                    }
                                    [$type, $typeIcon] = $this->type($install->package, $isLoader);
                                @endphp
                                <tr wire:key="install-{{ $install->id }}" x-show="!type || type === {{ $js($type) }}" style="border-top:1px solid rgba(127,127,127,.15);vertical-align:middle">
                                    <td style="padding:.6rem .5rem .6rem 0">
                                        <div style="display:flex;gap:.75rem;align-items:center;min-width:12rem;{{ $install->enabled ? '' : 'opacity:.5' }}">
                                            @if ($install->icon)
                                                <img src="{{ $install->icon }}" alt="" loading="lazy" style="{{ $icon }};width:2.25rem;height:2.25rem">
                                            @elseif ($isLoader || $isEngine)
                                                <div style="{{ $icon }};width:2.25rem;height:2.25rem;display:flex;align-items:center;justify-content:center">
                                                    <x-filament::icon :icon="$typeIcon" style="width:1.25rem;height:1.25rem;opacity:.6" />
                                                </div>
                                            @else
                                                <div style="{{ $icon }};width:2.25rem;height:2.25rem"></div>
                                            @endif
                                            <div style="min-width:0">
                                                @if (\Marschux\ModManager\Installer::uploaded($install->package))
                                                    {{-- an upload has no page to show --}}
                                                    <div style="font-weight:600">{{ $install->name }}</div>
                                                @else
                                                    <div style="font-weight:600;cursor:pointer" x-on:click="$dispatch('open-modal', { id: 'mod-details' }); $wire.showDetails({{ $js($install->package) }})">{{ $install->name }}</div>
                                                @endif
                                                <div style="{{ $muted }}">{{ $this->origin($install->package) }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="padding:.6rem .5rem">
                                        {{-- one badge per row, the same colour for all: what kind of thing it is --}}
                                        <x-filament::badge color="info" size="sm" :icon="$typeIcon" style="width:max-content">{{ trans("mod-manager::mods.types.$type") }}</x-filament::badge>
                                    </td>
                                    <td style="padding:.6rem .5rem;white-space:nowrap">
                                        {{-- a loader's or engine's version is several: one per line --}}
                                        {!! implode('<br>', array_map('e', explode(' / ', $install->version))) !!}
                                    </td>
                                    <td style="padding:.6rem .5rem;white-space:nowrap;text-align:right">
                                        {{ $install->size !== null ? \Illuminate\Support\Number::fileSize($install->size, 1) : '–' }}
                                    </td>
                                    @if ($hasRelations)
                                    <td style="padding:.6rem .5rem;{{ $muted }};min-width:10rem">
                                        @if ($relation['requires'])
                                            <div>{{ trans('mod-manager::mods.requires', ['names' => implode(', ', $relation['requires'])]) }}</div>
                                        @endif
                                        @if ($relation['needed_by'])
                                            <div>{{ trans('mod-manager::mods.needed_by', ['names' => implode(', ', $relation['needed_by'])]) }}</div>
                                        @endif
                                        @if (!$relation['requires'] && !$relation['needed_by'])
                                            –
                                        @endif
                                    </td>
                                    @endif
                                    <td style="padding:.6rem .5rem;min-width:8rem">
                                        <div style="display:flex;gap:.25rem;flex-wrap:wrap">
                                            @if ($isWorkshop && $workshopId === $this->startMap())
                                                <x-filament::badge color="info" size="sm" icon="tabler-flag">{{ trans('mod-manager::mods.workshop.start') }}</x-filament::badge>
                                            @endif
                                            @if ($install->pinned)
                                                <x-filament::badge color="gray" size="sm" icon="tabler-pin">{{ trans('mod-manager::mods.pinned') }}</x-filament::badge>
                                            @endif
                                            @if (!$install->enabled)
                                                <x-filament::badge color="gray" size="sm" icon="tabler-power">{{ trans($isMap ? "mod-manager::mods.$cycle.out" : 'mod-manager::mods.off') }}</x-filament::badge>
                                            @elseif ($isLoader && !$loaderRuns)
                                                @if ($loader['installed'])
                                                    <x-filament::badge color="warning" size="sm" icon="tabler-alert-triangle">{{ trans('mod-manager::mods.loader.not_started') }}</x-filament::badge>
                                                @else
                                                    <x-filament::badge color="danger" size="sm" icon="tabler-x">{{ trans('mod-manager::mods.loader.missing') }}</x-filament::badge>
                                                @endif
                                            {{-- maps and the engine are loaded by the game itself --}}
                                            @elseif (!$isLoader && !$isMap && !$isEngine && !$loaderRuns)
                                                <x-filament::badge color="warning" size="sm" icon="tabler-alert-triangle" :tooltip="trans('mod-manager::mods.loader.hint', ['loader' => $loader['name']])">{{ trans('mod-manager::mods.not_loaded') }}</x-filament::badge>
                                            @elseif ($relation['missing'])
                                                <x-filament::badge color="warning" size="sm" icon="tabler-alert-triangle">{{ trans('mod-manager::mods.missing', ['names' => implode(', ', $relation['missing'])]) }}</x-filament::badge>
                                            @else
                                                {{-- an update waiting changes nothing about it running - the button on the right offers it --}}
                                                <x-filament::badge color="success" size="sm" icon="tabler-check">{{ trans('mod-manager::mods.active') }}</x-filament::badge>
                                            @endif
                                        </div>
                                    </td>
                                    <td style="padding:.6rem .5rem">
                                        <div style="display:flex;gap:.25rem;align-items:center;justify-content:flex-end">
                                            @if ($isLoader && !$loaderRuns)
                                                @if ($canLoader)
                                                    <x-filament::button size="xs" :icon="$loader['installed'] ? 'tabler-tool' : 'tabler-download'" wire:click="installLoader" wire:loading.attr="disabled" style="white-space:nowrap">
                                                        {{ trans($loader['installed'] ? 'mod-manager::mods.loader.repair' : 'mod-manager::mods.install') }}
                                                    </x-filament::button>
                                                @endif
                                            @endif
                                            {{-- one fixed slot per action, so updates, switches, gears and bins line up from row to row --}}
                                            <span class="mod-slot">
                                                @if (isset($updates[$install->package]) && !($isLoader && !$loaderRuns))
                                                    @php
                                                        $updateLabel = trans('mod-manager::mods.update_from_to', ['from' => $install->version, 'to' => $updates[$install->package]]);
                                                    @endphp
                                                    {{-- grey while the version is pinned, still there to update on purpose --}}
                                                    <button type="button" @class(['mod-action', 'mod-update' => !$install->pinned]) wire:click="{{ $isLoader ? 'installLoader' : ($isEngine ? 'installEngine' : 'updateMod(' . $install->id . ')') }}" wire:loading.attr="disabled" @disabled(!($isLoader ? $canLoader : $canUpdate)) title="{{ $updateLabel }}" aria-label="{{ $updateLabel }}">
                                                        <x-filament::icon icon="tabler-arrow-up" />
                                                    </button>
                                                @elseif ($isWorkshop && $this->may(\App\Enums\SubuserPermission::StartupUpdate))
                                                    {{-- where the server starts (WORKSHOP_MAP): one map at a time --}}
                                                    <button type="button" @class(['mod-action', 'mod-update' => $workshopId === $this->startMap()]) wire:click="setStartMap({{ $install->id }})" @disabled($workshopId === $this->startMap()) title="{{ trans('mod-manager::mods.workshop.make_start') }}" aria-label="{{ trans('mod-manager::mods.workshop.make_start') }}">
                                                        <x-filament::icon icon="tabler-flag" />
                                                    </button>
                                                @endif
                                            </span>
                                            <span class="mod-slot">
                                                @php
                                                    // a map's switch puts it in and out of mapcycle.txt, or RockTheVote's maplist
                                                    $switchLabel = $isMap
                                                        ? trans($install->enabled ? "mod-manager::mods.$cycle.remove" : "mod-manager::mods.$cycle.add")
                                                        : trans($install->enabled ? 'mod-manager::mods.disable' : 'mod-manager::mods.enable');
                                                @endphp
                                                @if (!$isLoader && !$isEngine && $canUpdate)
                                                    <button type="button" @class(['mod-action', 'mod-on' => $install->enabled]) wire:click="toggle({{ $install->id }})" title="{{ $switchLabel }}" aria-label="{{ $switchLabel }}" aria-pressed="{{ $install->enabled ? 'true' : 'false' }}">
                                                        <x-filament::icon :icon="$install->enabled ? 'tabler-toggle-right' : 'tabler-toggle-left'" />
                                                    </button>
                                                @endif
                                            </span>
                                            <span class="mod-slot">
                                                @if ($config && $canReadConfig)
                                                    <button type="button" class="mod-action" wire:click="openConfig({{ $js($config) }})" title="{{ $config }}" aria-label="{{ trans('mod-manager::mods.config.edit', ['file' => $config]) }}">
                                                        <x-filament::icon icon="tabler-settings" />
                                                    </button>
                                                @elseif ($canReadConfig && $hasConfigs)
                                                    {{-- greyed out rather than gone, so it is clear where the config will be --}}
                                                    <button type="button" class="mod-action" disabled title="{{ trans('mod-manager::mods.config.none_yet') }}" aria-label="{{ trans('mod-manager::mods.config.none_yet') }}">
                                                        <x-filament::icon icon="tabler-settings" />
                                                    </button>
                                                @endif
                                            </span>
                                            <span class="mod-slot">
                                                @if ($isLoader ? $canRemoveLoader : $canDelete)
                                                    <button type="button" class="mod-action mod-danger" wire:click="{{ $isLoader ? 'removeLoader' : ($isEngine ? 'removeEngine' : 'remove(' . $install->id . ')') }}" title="{{ trans('mod-manager::mods.remove') }}" aria-label="{{ trans('mod-manager::mods.remove') }}">
                                                        <x-filament::icon icon="tabler-trash" />
                                                    </button>
                                                @endif
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
            </div>
        @endif

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

        @if ($unmanaged)
            <x-filament::section>
                <x-slot name="heading">{{ trans('mod-manager::mods.unmanaged.heading') }}</x-slot>
                <x-slot name="description">{{ trans($this->goldsrc() ? 'mod-manager::mods.unmanaged.hint_amxx' : 'mod-manager::mods.unmanaged.hint') }}</x-slot>

                @foreach ($unmanaged as $entry)
                    <div wire:key="unmanaged-{{ md5($entry['path']) }}" style="display:flex;gap:.75rem;align-items:center;padding:.5rem 0;{{ $loop->first ? '' : 'border-top:1px solid rgba(127,127,127,.15)' }}">
                        <div style="flex:1;min-width:0">
                            <div style="font-weight:600">{{ $entry['name'] }} <span style="{{ $muted }};font-weight:400">{{ $entry['version'] }}</span></div>
                            <div style="{{ $muted }};font-family:monospace">{{ $entry['path'] }}</div>
                        </div>
                        @if ($entry['package'] && $canUpdate)
                            <x-filament::button size="sm" color="gray" icon="tabler-download" wire:click="adopt({{ $js($entry['path']) }})">{{ trans('mod-manager::mods.unmanaged.adopt') }}</x-filament::button>
                        @endif
                        @if ($canDelete)
                        <x-filament::icon-button color="danger" icon="tabler-trash" wire:click="removeUnmanaged({{ $js($entry['path']) }})" wire:confirm="{{ trans('mod-manager::mods.unmanaged.confirm_delete', ['path' => $entry['path']]) }}" :label="trans('mod-manager::mods.remove')" />
                        @endif
                    </div>
                @endforeach
            </x-filament::section>
        @endif
        @if ($amxx)
            {{-- AMX Mod X's own plugins are part of the loader, the installed table stays for what was added --}}
            <x-filament::section collapsible collapsed>
                <x-slot name="heading">{{ trans('mod-manager::mods.amxx.heading') }} ({{ collect($amxx)->where('enabled', true)->count() }}/{{ count($amxx) }})</x-slot>
                <x-slot name="description">
                    {{ trans('mod-manager::mods.amxx.hint') }}
                    {{-- the loader removed leaves plugins.ini behind, switched on or not none of it runs --}}
                    @if (!$loaderRuns)
                        <span style="display:block;margin-top:.25rem;color:rgb(234,179,8)">{{ trans('mod-manager::mods.loader.hint', ['loader' => $loader['name']]) }}</span>
                    @endif
                </x-slot>

                @foreach (collect($amxx)->groupBy('group') as $group => $plugins)
                    @if ($group !== '')
                        <div style="{{ $muted }};font-weight:600;margin:{{ $loop->first ? '0' : '.75rem' }} 0 .25rem">{{ $group }}</div>
                    @endif
                    @foreach ($plugins as $plugin)
                        <div wire:key="amxx-{{ md5($plugin['file']) }}" style="display:flex;gap:.75rem;align-items:center;padding:.3rem 0;border-top:1px solid rgba(127,127,127,.15)">
                            <span class="mod-slot">
                                @if ($canUpdate)
                                    <button type="button" @class(['mod-action', 'mod-on' => $plugin['enabled']]) wire:click="toggleAmxx({{ $js($plugin['file']) }}, {{ $plugin['enabled'] ? 'false' : 'true' }})" title="{{ trans($plugin['enabled'] ? 'mod-manager::mods.disable' : 'mod-manager::mods.enable') }}" aria-label="{{ trans($plugin['enabled'] ? 'mod-manager::mods.disable' : 'mod-manager::mods.enable') }}" aria-pressed="{{ $plugin['enabled'] ? 'true' : 'false' }}">
                                        <x-filament::icon :icon="$plugin['enabled'] ? 'tabler-toggle-right' : 'tabler-toggle-left'" />
                                    </button>
                                @endif
                            </span>
                            <span style="font-family:monospace;font-size:.875rem;min-width:11rem;{{ $plugin['enabled'] ? '' : 'opacity:.5' }}">{{ $plugin['file'] }}</span>
                            <span style="{{ $muted }};flex:1">{{ $plugin['note'] }}</span>
                            @php
                                $pluginConfig = \Marschux\ModManager\Installer::configFor(substr($plugin['file'], 0, -5), $configs);
                            @endphp
                            @if ($pluginConfig && $canReadConfig)
                                <button type="button" class="mod-action" wire:click="openConfig({{ $js($pluginConfig) }})" title="{{ $pluginConfig }}" aria-label="{{ trans('mod-manager::mods.config.edit', ['file' => $pluginConfig]) }}">
                                    <x-filament::icon icon="tabler-settings" />
                                </button>
                            @endif
                            @if (!$plugin['exists'])
                                <x-filament::badge color="warning" size="sm" icon="tabler-alert-triangle">{{ trans('mod-manager::mods.amxx.missing') }}</x-filament::badge>
                            @endif
                            @if ($canDelete)
                                <button type="button" class="mod-action mod-danger" wire:click="deleteAmxx({{ $js($plugin['file']) }})" wire:confirm="{{ trans('mod-manager::mods.amxx.confirm_delete', ['file' => $plugin['file']]) }}" title="{{ trans('mod-manager::mods.remove') }}" aria-label="{{ trans('mod-manager::mods.remove') }}">
                                    <x-filament::icon icon="tabler-trash" />
                                </button>
                            @endif
                        </div>
                    @endforeach
                @endforeach
            </x-filament::section>
        @endif

        @if ($configs)
            <x-filament::section collapsible collapsed>
                <x-slot name="heading">{{ trans('mod-manager::mods.config.heading') }} ({{ count($configs) }})</x-slot>
                <x-slot name="description">{{ trans('mod-manager::mods.config.hint') }}</x-slot>

                @foreach ($configs as $config)
                    <div wire:key="config-{{ md5($config) }}" style="display:flex;gap:.75rem;align-items:center;padding:.4rem 0;{{ $loop->first ? '' : 'border-top:1px solid rgba(127,127,127,.15)' }}">
                        <span style="flex:1;font-family:monospace;font-size:.875rem">{{ $config }}</span>
                        {{-- the same square buttons as the rows of the installed table, side by side --}}
                        <span style="display:inline-flex;gap:.25rem">
                            @if ($canReadConfig)
                                <button type="button" class="mod-action" wire:click="openConfig({{ $js($config) }})" title="{{ trans('mod-manager::mods.config.open') }}" aria-label="{{ trans('mod-manager::mods.config.edit', ['file' => $config]) }}">
                                    <x-filament::icon icon="tabler-pencil" />
                                </button>
                            @endif
                            @if ($canDelete && $this->keepsConfig($config))
                                {{-- AMX Mod X does not write these anew: deleting asks with a warning, not the usual "fresh one on the next start" --}}
                                <button type="button" class="mod-action mod-danger" wire:click="deleteConfig({{ $js($config) }})" wire:confirm="{{ trans('mod-manager::mods.config.confirm_delete_core', ['file' => $config]) }}" title="{{ trans('mod-manager::mods.config.delete') }}" aria-label="{{ trans('mod-manager::mods.config.delete') }}">
                                    <x-filament::icon icon="tabler-trash" />
                                </button>
                            @elseif ($canDelete)
                                <button type="button" class="mod-action mod-danger" wire:click="deleteConfig({{ $js($config) }})" wire:confirm="{{ trans('mod-manager::mods.config.confirm_delete', ['file' => $config]) }}" title="{{ trans('mod-manager::mods.config.delete_hint') }}" aria-label="{{ trans('mod-manager::mods.config.delete') }}">
                                    <x-filament::icon icon="tabler-trash" />
                                </button>
                            @endif
                        </span>
                    </div>
                @endforeach
            </x-filament::section>
        @endif
    @else
        @php
            $gameVersion = $this->gameVersion();
        @endphp
        @if ($gameVersion)
            <span style="{{ $muted }}">{{ trans('mod-manager::mods.game_version', $gameVersion) }}</span>
        @endif
        {{-- every change searches right away, typing waits for a short pause --}}
        <form wire:submit="search" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
            <x-filament::input.wrapper prefix-icon="tabler-search" style="flex:1 1 16rem">
                <x-filament::input type="search" wire:model.live.debounce.500ms="query" :placeholder="trans($this->sourceKey() === 'gamebanana' ? 'mod-manager::mods.search_maps' : 'mod-manager::mods.search_placeholder')" />
            </x-filament::input.wrapper>
            <x-filament::input.wrapper style="flex:0 1 14rem">
                <x-filament::input.select wire:model.live="category">
                    <option value="">{{ trans('mod-manager::mods.all_categories') }}</option>
                    @foreach ($this->categories() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
            <x-filament::input.wrapper style="flex:0 1 14rem">
                <x-filament::input.select wire:model.live="ordering">
                    @foreach (\Marschux\ModManager\Sources\Thunderstore::ORDERINGS as $order)
                        <option value="{{ $order }}">{{ trans($order === 'most-downloaded' && $this->sourceKey() === 'gamebanana' ? 'mod-manager::mods.orderings.most-viewed' : "mod-manager::mods.orderings.$order") }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
            {{-- thunderstore tags client-only and deprecated packages, other sources do not --}}
            @if ($this->sourceKey() === 'thunderstore')
                <label style="display:flex;gap:.5rem;align-items:center;font-size:.875rem;cursor:pointer">
                    <x-filament::input.checkbox wire:model.live="showClientSide" />
                    {{ trans('mod-manager::mods.show_client_side') }}
                </label>
                <label style="display:flex;gap:.5rem;align-items:center;font-size:.875rem;cursor:pointer">
                    <x-filament::input.checkbox wire:model.live="showDeprecated" />
                    {{ trans('mod-manager::mods.show_deprecated') }}
                </label>
            @endif
            @if ($gameVersion)
                <label style="display:flex;gap:.5rem;align-items:center;font-size:.875rem;cursor:pointer">
                    <x-filament::input.checkbox wire:model.live="showAll" />
                    {{ trans('mod-manager::mods.show_all_versions', ['version' => $gameVersion['version']]) }}
                </label>
            @endif
        </form>

        {{-- while the next page loads the tiles stay and only fade, so nothing jumps --}}
        <style>.mod-loading { opacity: .4; pointer-events: none; transition: opacity .15s; }</style>

        {{-- three tiles side by side, fewer when the page gets narrow; every row as tall as the tallest, so all tiles are the same size --}}
        <div wire:loading.class="mod-loading" wire:target="search,goToPage,source,kind,query,category,ordering,showClientSide,showDeprecated,showAll" style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(max(16rem,calc((100% - 2rem) / 3)),1fr));grid-auto-rows:1fr">
            @forelse ($items as $item)
                <div wire:key="item-{{ md5($item['id']) }}" style="{{ $card }}">
                    <div style="display:flex;gap:.75rem;align-items:center;cursor:pointer" x-on:click="$dispatch('open-modal', { id: 'mod-details' }); $wire.showDetails({{ $js($item['id']) }})">
                        @if ($item['icon'])
                            <img src="{{ $item['icon'] }}" alt="" loading="lazy" style="{{ $icon }};width:4rem;height:4rem">
                        @else
                            <div style="{{ $icon }};width:4rem;height:4rem"></div>
                        @endif
                        <div style="flex:1;min-width:0">
                            <div style="font-weight:600;font-size:1.05rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $item['name'] }}</div>
                            <div style="{{ $muted }}">{{ trans('mod-manager::mods.by', ['author' => $item['author']]) }}</div>
                            <div style="{{ $muted }};display:flex;gap:.75rem">
                                @if (isset($item['views']))
                                    {{-- a source that lists no downloads --}}
                                    <span title="{{ trans('mod-manager::mods.views') }}">👁 {{ \Illuminate\Support\Number::abbreviate($item['views']) }}</span>
                                @else
                                    <span title="{{ trans('mod-manager::mods.downloads') }}">⬇ {{ \Illuminate\Support\Number::abbreviate($item['downloads']) }}</span>
                                @endif
                                <span title="{{ trans('mod-manager::mods.rating') }}">★ {{ $item['rating'] }}</span>
                                @if ($item['updated'])
                                    <span>{{ \Illuminate\Support\Carbon::parse($item['updated'])->diffForHumans() }}</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div style="font-size:.875rem;opacity:.8;flex:1;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden">{{ $item['summary'] }}</div>

                    @if ($item['categories'] || $item['deprecated'] || $item['side'])
                        <div style="display:flex;gap:.25rem;flex-wrap:wrap">
                            @if ($item['side'] === 'server')
                                <x-filament::badge color="success" size="sm" icon="tabler-server">{{ trans('mod-manager::mods.side.server') }}</x-filament::badge>
                            @elseif ($item['side'] === 'client')
                                <x-filament::badge color="warning" size="sm" icon="tabler-device-desktop">{{ trans('mod-manager::mods.side.client') }}</x-filament::badge>
                            @endif
                            @if ($item['deprecated'])
                                <x-filament::badge color="danger" size="sm">{{ trans('mod-manager::mods.deprecated') }}</x-filament::badge>
                            @endif
                            @foreach (array_slice(array_diff($item['categories'], ['Server-side', 'Client-side']), 0, 3) as $category)
                                <x-filament::badge color="gray" size="sm">{{ $category }}</x-filament::badge>
                            @endforeach
                        </div>
                    @endif

                    <div style="display:flex;gap:.5rem;align-items:center">
                        @if (isset($versions[$item['id']]))
                            <span class="mod-installed"><x-filament::icon icon="tabler-check" style="width:1rem;height:1rem" />{{ trans('mod-manager::mods.is_installed') }}</span>
                        @elseif ($canInstall)
                            <x-filament::button size="sm" icon="tabler-download" wire:click="install({{ $js($item['id']) }})">{{ trans('mod-manager::mods.install') }}</x-filament::button>
                        @endif
                        <x-filament::button size="sm" color="gray" icon="tabler-info-circle" x-on:click="$dispatch('open-modal', { id: 'mod-details' }); $wire.showDetails({{ $js($item['id']) }})">{{ trans('mod-manager::mods.details') }}</x-filament::button>
                    </div>
                </div>
            @empty
                <span style="opacity:.7">{{ trans('mod-manager::mods.no_results') }}</span>
            @endforelse
        </div>

        @if ($pages > 1)
            {{-- first, last and two around the current page, gaps as dots --}}
            @php
                $shown = collect([1, $pages, ...range(max(1, $page - 2), min($pages, $page + 2))])->unique()->sort()->values();
            @endphp
            <div style="display:flex;justify-content:center;align-items:center;gap:.25rem;flex-wrap:wrap" wire:loading.class="mod-loading" wire:target="search,goToPage,source,kind,query,category,ordering,showClientSide,showDeprecated,showAll">
                <x-filament::icon-button icon="tabler-chevron-left" color="gray" :disabled="$page <= 1" wire:click="goToPage({{ $page - 1 }})" :label="trans('mod-manager::mods.previous')" />
                @foreach ($shown as $index => $number)
                    @if ($index > 0 && $number - $shown[$index - 1] > 1)
                        <span style="opacity:.5;padding:0 .25rem">…</span>
                    @endif
                    <x-filament::button size="sm" :color="$number === $page ? 'primary' : 'gray'" :outlined="$number !== $page" wire:click="goToPage({{ $number }})">{{ $number }}</x-filament::button>
                @endforeach
                <x-filament::icon-button icon="tabler-chevron-right" color="gray" :disabled="$page >= $pages" wire:click="goToPage({{ $page + 1 }})" :label="trans('mod-manager::mods.next')" />
            </div>
        @endif
    @endif

    <x-filament::modal id="mod-details" width="5xl">
        <div wire:loading.flex wire:target="showDetails" style="justify-content:center;padding:3rem">
            <x-filament::loading-indicator style="width:2rem;height:2rem" />
        </div>
        {{-- the details of the mod before stay hidden while the next ones load --}}
        <div wire:loading.remove wire:target="showDetails" style="display:contents">
        @if ($details)
            <x-slot name="heading">
                <div wire:loading.remove wire:target="showDetails" style="display:flex;gap:1rem;align-items:center">
                    @if ($details['icon'])
                        <img src="{{ $details['icon'] }}" alt="" style="{{ $icon }};width:4rem;height:4rem">
                    @endif
                    <div>
                        <div>{{ $details['name'] }} <span style="{{ $muted }}">{{ $details['version'] }}</span></div>
                        <div style="{{ $muted }};font-weight:400">
                            {{ trans('mod-manager::mods.by', ['author' => $details['author']]) }} ·
                            ⬇ {{ number_format($details['downloads']) }} · ★ {{ $details['rating'] }}
                            @if ($details['size'])
                                · {{ \Illuminate\Support\Number::fileSize($details['size']) }}
                            @endif
                            {{-- not every source counts who depends on a package --}}
                            @if ($details['dependants'] !== null)
                                · {{ trans_choice('mod-manager::mods.used_by', $details['dependants'], ['count' => number_format($details['dependants'])]) }}
                            @endif
                        </div>
                    </div>
                </div>
            </x-slot>

            <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
                @if (isset($versions[$details['id']]))
                    <span class="mod-installed"><x-filament::icon icon="tabler-check" style="width:1rem;height:1rem" />{{ trans('mod-manager::mods.is_installed') }}</span>
                @elseif ($canInstall && $details['versions'] !== [])
                    <x-filament::button icon="tabler-download" wire:click="install({{ $js($details['id']) }})">{{ trans('mod-manager::mods.install') }}</x-filament::button>
                @endif
                <x-filament::button color="gray" icon="tabler-external-link" tag="a" :href="$details['url']" target="_blank">{{ $this->sourceName($details['id']) }}</x-filament::button>
                @if ($details['website'])
                    <x-filament::button color="gray" icon="tabler-world" tag="a" :href="$details['website']" target="_blank">{{ trans('mod-manager::mods.website') }}</x-filament::button>
                @endif
            </div>

            <div style="display:grid;gap:1.5rem;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr))">
                <div>
                    <div style="font-weight:600;margin-bottom:.5rem">{{ trans('mod-manager::mods.dependencies') }}</div>
                    @forelse ($details['dependencies'] as $dependency)
                        <div style="display:flex;gap:.5rem;align-items:center;padding:.25rem 0">
                            @if ($dependency['icon'])
                                <img src="{{ $dependency['icon'] }}" alt="" style="{{ $icon }};width:1.75rem;height:1.75rem">
                            @endif
                            <span style="flex:1">{{ $dependency['name'] }} <span style="{{ $muted }}">{{ $dependency['version'] }}</span></span>
                            @if ($dependency['hidden'])
                                <x-filament::badge color="gray" size="sm">{{ trans('mod-manager::mods.loader_dependency') }}</x-filament::badge>
                            @elseif ($dependency['optional'] ?? false)
                                <x-filament::badge color="gray" size="sm">{{ trans('mod-manager::mods.optional') }}</x-filament::badge>
                            @endif
                        </div>
                    @empty
                        <span style="{{ $muted }}">{{ trans('mod-manager::mods.no_dependencies') }}</span>
                    @endforelse
                </div>
                <div>
                    <div style="font-weight:600;margin-bottom:.5rem">{{ trans('mod-manager::mods.versions') }}</div>
                    {{-- one select instead of a long list; the button knows which version is picked --}}
                    @php
                        $current = $versions[$details['id']] ?? null;
                    @endphp
                    @if ($details['versions'] === [])
                        {{-- the version filter left nothing: made for other game versions only --}}
                        <span style="{{ $muted }}">{{ trans('mod-manager::mods.no_versions') }}</span>
                    @else
                        <div x-data="{ picked: {{ \Illuminate\Support\Js::from($details['versions'][0]['number'] ?? '') }}, current: {{ \Illuminate\Support\Js::from($current) }} }" wire:key="versions-{{ md5($details['id']) }}" style="display:flex;gap:.5rem;align-items:center">
                            <x-filament::input.wrapper style="flex:1">
                                <x-filament::input.select x-model="picked">
                                    @foreach ($details['versions'] as $version)
                                        <option value="{{ $version['number'] }}">
                                            {{ $version['number'] }} · {{ \Illuminate\Support\Carbon::parse($version['date'])->isoFormat('L') }} · ⬇ {{ \Illuminate\Support\Number::abbreviate($version['downloads']) }}{{ $version['number'] === $current ? ' · ' . trans('mod-manager::mods.current') : '' }}
                                        </option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                            <x-filament::button icon="tabler-download" x-show="{{ $canInstall ? 'picked !== current' : 'false' }}" x-on:click="$wire.install({{ \Illuminate\Support\Js::from($details['id']) }}, picked)">{{ trans('mod-manager::mods.install') }}</x-filament::button>
                            <x-filament::badge color="success" icon="tabler-check" x-show="picked === current" x-cloak>{{ trans('mod-manager::mods.current') }}</x-filament::badge>
                        </div>
                    @endif
                </div>
            </div>

            <style>.mod-readme img { max-width: 100%; height: auto; }</style>
            <div x-data="{ show: 'readme' }" wire:key="details-{{ md5($details['id']) }}" style="border-top:1px solid rgba(127,127,127,.2);padding-top:1rem">
                @if ($details['changelog'])
                    <x-filament::tabs style="margin-bottom:1rem">
                        <x-filament::tabs.item alpine-active="show === 'readme'" x-on:click="show = 'readme'">{{ trans('mod-manager::mods.readme') }}</x-filament::tabs.item>
                        <x-filament::tabs.item alpine-active="show === 'changelog'" x-on:click="show = 'changelog'">{{ trans('mod-manager::mods.changelog') }}</x-filament::tabs.item>
                    </x-filament::tabs>
                @endif
                <div class="fi-prose mod-readme" style="max-width:none" x-show="show === 'readme'">{!! $details['readme'] !!}</div>
                @if ($details['changelog'])
                    <div class="fi-prose mod-readme" style="max-width:none" x-show="show === 'changelog'" x-cloak>{!! $details['changelog'] !!}</div>
                @endif
            </div>
        @endif
        </div>
    </x-filament::modal>
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

    {{-- asks before installing dependencies along and before removing --}}
    <x-filament::modal id="mod-confirm" width="lg" :close-by-clicking-away="false">
        @if ($confirm)
            <x-slot name="heading">
                {{ trans($confirm['type'] === 'install' ? 'mod-manager::mods.confirm.install_heading' : 'mod-manager::mods.confirm.remove_heading', ['name' => $confirm['name']]) }}
            </x-slot>

            @if ($confirm['type'] === 'remove_loader')
                <div style="font-size:.875rem">{{ trans_choice('mod-manager::mods.confirm.loader_body', $confirm['mods'], ['name' => $confirm['name']]) }}</div>
            @endif

            @if ($confirm['type'] === 'remove_engine')
                <div style="font-size:.875rem">{{ trans('mod-manager::mods.engine.remove_body') }}</div>
            @endif

            @if ($confirm['packages'])
                <div style="{{ $muted }};font-size:.875rem">
                    {{ trans('mod-manager::mods.confirm.install_body', ['name' => $confirm['name']]) }}
                </div>
                <div>
                    @foreach ($confirm['packages'] as $package)
                        <div style="display:flex;gap:.5rem;align-items:center;padding:.25rem 0">
                            @if ($package['icon'] ?? null)
                                <img src="{{ $package['icon'] }}" alt="" style="{{ $icon }};width:1.75rem;height:1.75rem">
                            @endif
                            <span>{{ $package['name'] }} <span style="{{ $muted }}">{{ $package['version'] }}</span></span>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($confirm['optional'] ?? [])
                <div>
                    <div style="{{ $muted }};font-size:.875rem;margin-bottom:.25rem">{{ trans('mod-manager::mods.confirm.optional', ['name' => $confirm['name']]) }}</div>
                    @foreach ($confirm['optional'] as $optionalId => $optionalName)
                        <label wire:key="optional-{{ md5($optionalId) }}" style="display:flex;gap:.5rem;align-items:center;font-size:.875rem;cursor:pointer;padding:.15rem 0">
                            <x-filament::input.checkbox wire:model="optionalPicked" value="{{ $optionalId }}" />
                            {{ $optionalName }}
                        </label>
                    @endforeach
                </div>
            @endif

            @if (($confirm['type'] === 'remove') && ($confirm['needed_by'] ?? []))
                <div style="font-size:.875rem;color:rgb(234,179,8)">
                    {{ trans_choice('mod-manager::mods.confirm.needed_by', count($confirm['needed_by']), ['names' => implode(', ', $confirm['needed_by'])]) }}
                </div>
            @endif

            @if (($confirm['type'] === 'remove') && ($confirm['configs'] ?? []))
                <div>
                    <label style="display:flex;gap:.5rem;align-items:center;font-size:.875rem;cursor:pointer">
                        <x-filament::input.checkbox wire:model="removeConfigs" />
                        {{ trans(($confirm['data'] ?? false) ? 'mod-manager::mods.confirm.remove_data' : 'mod-manager::mods.confirm.remove_configs') }}
                    </label>
                    <div style="{{ $muted }};font-family:monospace;margin:.35rem 0 0 1.5rem;max-height:8rem;overflow-y:auto">
                        @foreach ($confirm['configs'] as $path)
                            <div>{{ $path }}</div>
                        @endforeach
                    </div>
                </div>
            @endif

            <x-slot name="footerActions">
                @if ($confirm['type'] === 'install')
                    <x-filament::button icon="tabler-download" wire:click="confirmInstall">{{ trans('mod-manager::mods.confirm.install_all') }}</x-filament::button>
                @elseif ($confirm['type'] === 'remove_loader')
                    <x-filament::button color="danger" icon="tabler-trash" wire:click="confirmRemoveLoader">{{ trans('mod-manager::mods.remove') }}</x-filament::button>
                @elseif ($confirm['type'] === 'remove_engine')
                    <x-filament::button color="danger" icon="tabler-trash" wire:click="confirmRemoveEngine">{{ trans('mod-manager::mods.remove') }}</x-filament::button>
                @else
                    <x-filament::button color="danger" icon="tabler-trash" wire:click="confirmRemove">{{ trans('mod-manager::mods.remove') }}</x-filament::button>
                @endif
                <x-filament::button color="gray" wire:click="closeConfirm">{{ trans('mod-manager::mods.cancel') }}</x-filament::button>
            </x-slot>
        @endif
    </x-filament::modal>
</x-filament-panels::page>
