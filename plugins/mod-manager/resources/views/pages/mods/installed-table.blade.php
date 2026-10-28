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
                            <span x-show="!busy">{{ trans($this->source2() ? 'mod-manager::mods.upload.button' : 'mod-manager::mods.upload.button_maps') }}</span>
                            <span x-show="busy" x-cloak>{{ trans('mod-manager::mods.upload.busy') }}</span>
                            <input type="file" accept="{{ $this->source2() ? '.zip,.dll' : ($this->goldsrc() ? '.amxx,.zip' : '.smx,.zip') . ',.bsp,.rar,.7z' }}" style="display:none" x-on:change="send($event.target.files[0]); $event.target.value = ''">
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

        {{-- a table, scrolling sideways when the page gets narrow --}}
        {{-- icon buttons have a negative margin, so the last column keeps some room on the right, else the edge alone makes it scroll --}}
        <div style="overflow-x:auto;overflow-y:hidden">
            {{-- fixed column widths, the same for every game; below 60rem the table scrolls --}}
            <table style="width:100%;min-width:60rem;table-layout:fixed;border-collapse:collapse;font-size:.875rem">
                <colgroup>
                    <col style="width:22%">
                    <col style="width:9%">
                    <col style="width:10%">
                    <col style="width:7%">
                    <col style="width:20%">
                    <col style="width:12%">
                    <col style="width:20%">
                </colgroup>
                <thead>
                    <tr style="text-align:left;{{ $muted }}">
                        <th style="padding:.4rem .5rem .4rem 0;font-weight:500">{{ trans('mod-manager::mods.columns.mod') }}</th>
                        <th style="padding:.4rem .5rem;font-weight:500">{{ trans('mod-manager::mods.columns.type') }}</th>
                        <th style="padding:.4rem .5rem;font-weight:500">{{ trans('mod-manager::mods.columns.version') }}</th>
                        <th style="padding:.4rem .5rem;font-weight:500;text-align:right">{{ trans('mod-manager::mods.columns.size') }}</th>
                        <th style="padding:.4rem .5rem;font-weight:500">{{ trans('mod-manager::mods.columns.dependencies') }}</th>
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
                                <x-filament::badge :color="$this->typeColor('loader')" size="sm" icon="tabler-plug" style="width:max-content">{{ trans('mod-manager::mods.types.loader') }}</x-filament::badge>
                            </td>
                            <td style="padding:.6rem .5rem">–</td>
                            <td style="padding:.6rem .5rem;text-align:right">–</td>
                            <td style="padding:.6rem .5rem;{{ $muted }}">–</td>
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
                                    <x-filament::badge :color="$this->typeColor('engine')" size="sm" icon="tabler-engine" style="width:max-content">{{ trans('mod-manager::mods.types.engine') }}</x-filament::badge>
                                </td>
                                <td style="padding:.6rem .5rem">–</td>
                                <td style="padding:.6rem .5rem;text-align:right">–</td>
                                <td style="padding:.6rem .5rem;{{ $muted }}">–</td>
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
                            $isMap = $isWorkshop || \Marschux\ModManager\Installer::mapPackage($install->package);
                            $cycle = $isWorkshop ? 'workshop' : ($this->mapgroups() ? 'mapgroup' : 'mapcycle');
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
                                {{-- one badge per row, a colour per type: what kind of thing it is --}}
                                <x-filament::badge :color="$this->typeColor($type)" size="sm" :icon="$typeIcon" style="width:max-content">{{ trans("mod-manager::mods.types.$type") }}</x-filament::badge>
                            </td>
                            <td style="padding:.6rem .5rem;overflow-wrap:anywhere">
                                {{-- a loader's or engine's version is several: one per line --}}
                                @if ($isWorkshop)
                                    {{-- the game fetches the newest itself; the field holds the map's name for the vote plugin --}}
                                    {{ trans('mod-manager::mods.version_latest') }}
                                @else
                                    {!! implode('<br>', array_map('e', explode(' / ', $install->version))) !!}
                                @endif
                            </td>
                            <td style="padding:.6rem .5rem;white-space:nowrap;text-align:right">
                                {{ $install->size !== null ? \Illuminate\Support\Number::fileSize($install->size, 1) : '–' }}
                            </td>
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
                            <td style="padding:.6rem .5rem;min-width:8rem">
                                <div style="display:flex;gap:.25rem;flex-wrap:wrap">
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
