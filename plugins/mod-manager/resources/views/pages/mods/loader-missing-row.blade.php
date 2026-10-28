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
