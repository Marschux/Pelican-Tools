{{-- a section with nothing to list: an icon, what to know, and where to go from here --}}
<x-filament::section>
    <div style="display:flex;flex-direction:column;align-items:center;gap:.75rem;padding:2rem 1rem;text-align:center">
        <x-filament::icon :icon="$icon" style="width:2.5rem;height:2.5rem;opacity:.5" />
        <span style="opacity:.7;max-width:40rem">{{ $text }}</span>
        @if ($action ?? null)
            <x-filament::button color="gray" icon="tabler-plus" wire:click="{{ $action }}">{{ trans('game-manager::mods.tabs.add') }}</x-filament::button>
        @endif
    </div>
</x-filament::section>
