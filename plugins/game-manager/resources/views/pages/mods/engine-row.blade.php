{{-- GoldSrc: ReHLDS, ReGameDLL and ReAPI are offered, never needed --}}
<tr wire:key="engine-row" x-show="!type || type === 'engine'" style="border-top:1px solid rgba(127,127,127,.15);vertical-align:middle">
    <td style="padding:.6rem .5rem .6rem 0">
        <div style="display:flex;gap:.75rem;align-items:center;min-width:12rem">
            <div style="{{ $icon }};width:2.25rem;height:2.25rem;display:flex;align-items:center;justify-content:center">
                <x-filament::icon icon="tabler-engine" style="width:1.25rem;height:1.25rem;opacity:.6" />
            </div>
            <div style="min-width:0;max-width:22rem">
                <div style="font-weight:600">ReHLDS + ReGameDLL + ReAPI</div>
                <div style="{{ $muted }}">{{ trans('game-manager::mods.engine.hint') }}</div>
            </div>
        </div>
    </td>
    <td style="padding:.6rem .5rem">
        <x-filament::badge :color="$this->typeColor('engine')" size="sm" icon="tabler-engine" style="width:max-content">{{ trans('game-manager::mods.types.engine') }}</x-filament::badge>
    </td>
    <td style="padding:.6rem .5rem">–</td>
    <td style="padding:.6rem .5rem;text-align:right">–</td>
    <td style="padding:.6rem .5rem;{{ $muted }}">–</td>
    <td style="padding:.6rem .5rem;min-width:8rem">
        <x-filament::badge color="gray" size="sm">{{ trans('game-manager::mods.engine.optional') }}</x-filament::badge>
    </td>
    <td style="padding:.6rem .5rem">
        <div style="display:flex;gap:.25rem;align-items:center;justify-content:flex-end">
            @if ($canInstall && $canUpdate)
                <x-filament::button size="xs" icon="tabler-download" wire:click="installEngine" wire:loading.attr="disabled" style="white-space:nowrap">{{ trans('game-manager::mods.install') }}</x-filament::button>
            @endif
        </div>
    </td>
</tr>
