{{-- Filament's own header markup, with a restart button where its header actions would sit --}}
<header class="fi-header" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
    <h1 class="fi-header-heading">{{ $heading }}</h1>
    @if ($canRestart)
        <x-filament::button size="sm" color="gray" icon="tabler-refresh" wire:click="restart" wire:confirm="{{ trans('mod-manager::mods.restart.confirm') }}">
            {{ trans('mod-manager::mods.restart.header') }}
        </x-filament::button>
    @endif
</header>
