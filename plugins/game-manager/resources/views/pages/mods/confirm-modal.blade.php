{{-- asks before installing dependencies along and before removing --}}
<x-filament::modal id="mod-confirm" width="lg" :close-by-clicking-away="false">
    @if ($confirm)
        <x-slot name="heading">
            {{ trans($confirm['type'] === 'install' ? 'game-manager::mods.confirm.install_heading' : 'game-manager::mods.confirm.remove_heading', ['name' => $confirm['name']]) }}
        </x-slot>

        @if ($confirm['type'] === 'remove_loader')
            <div style="font-size:.875rem">{{ trans_choice('game-manager::mods.confirm.loader_body', $confirm['mods'], ['name' => $confirm['name']]) }}</div>
        @endif

        @if (in_array($confirm['type'], ['remove_engine', 'remove_bots'], true))
            <div style="font-size:.875rem">{{ trans($confirm['type'] === 'remove_engine' ? 'game-manager::mods.engine.remove_body' : 'game-manager::mods.bots.remove_body') }}</div>
        @endif

        @if ($confirm['packages'])
            <div style="{{ $muted }};font-size:.875rem">
                {{ trans('game-manager::mods.confirm.install_body', ['name' => $confirm['name']]) }}
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
                <div style="{{ $muted }};font-size:.875rem;margin-bottom:.25rem">{{ trans('game-manager::mods.confirm.optional', ['name' => $confirm['name']]) }}</div>
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
                {{ trans_choice('game-manager::mods.confirm.needed_by', count($confirm['needed_by']), ['names' => implode(', ', $confirm['needed_by'])]) }}
            </div>
        @endif

        @if (($confirm['type'] === 'remove') && ($confirm['configs'] ?? []))
            <div>
                <label style="display:flex;gap:.5rem;align-items:center;font-size:.875rem;cursor:pointer">
                    <x-filament::input.checkbox wire:model="removeConfigs" />
                    {{ trans(($confirm['data'] ?? false) ? 'game-manager::mods.confirm.remove_data' : 'game-manager::mods.confirm.remove_configs') }}
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
                <x-filament::button icon="tabler-download" wire:click="confirmInstall">{{ trans('game-manager::mods.confirm.install_all') }}</x-filament::button>
            @elseif ($confirm['type'] === 'remove_loader')
                <x-filament::button color="danger" icon="tabler-trash" wire:click="confirmRemoveLoader">{{ trans('game-manager::mods.remove') }}</x-filament::button>
            @elseif ($confirm['type'] === 'remove_engine')
                <x-filament::button color="danger" icon="tabler-trash" wire:click="confirmRemoveEngine">{{ trans('game-manager::mods.remove') }}</x-filament::button>
            @elseif ($confirm['type'] === 'remove_bots')
                <x-filament::button color="danger" icon="tabler-trash" wire:click="confirmRemoveBots">{{ trans('game-manager::mods.remove') }}</x-filament::button>
            @else
                <x-filament::button color="danger" icon="tabler-trash" wire:click="confirmRemove">{{ trans('game-manager::mods.remove') }}</x-filament::button>
            @endif
            <x-filament::button color="gray" wire:click="closeConfirm">{{ trans('game-manager::mods.cancel') }}</x-filament::button>
        </x-slot>
    @endif
</x-filament::modal>
