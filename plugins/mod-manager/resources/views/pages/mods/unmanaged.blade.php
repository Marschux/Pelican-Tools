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
