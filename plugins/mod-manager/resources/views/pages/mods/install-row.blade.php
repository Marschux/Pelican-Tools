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
                {{-- a click unpins it: updates are offered for it again, nothing is installed --}}
                @if ($canUpdate)
                    <button type="button" wire:click="unpin({{ $install->id }})" title="{{ trans('mod-manager::mods.unpin') }}" style="cursor:pointer">
                        <x-filament::badge color="gray" size="sm" icon="tabler-pin">{{ trans('mod-manager::mods.pinned') }}</x-filament::badge>
                    </button>
                @else
                    <x-filament::badge color="gray" size="sm" icon="tabler-pin">{{ trans('mod-manager::mods.pinned') }}</x-filament::badge>
                @endif
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
