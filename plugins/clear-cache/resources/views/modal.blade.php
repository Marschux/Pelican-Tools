<div>
    {{-- looks like Filament's own confirm modals: icon, heading and text centred, two buttons side by side --}}
    <x-filament::modal
        :id="\Marschux\ClearCache\Livewire\ClearCacheModal::ID"
        icon="tabler-refresh"
        alignment="center"
        teleport="body"
        :heading="trans('clear-cache::clear-cache.button')"
        :description="trans('clear-cache::clear-cache.confirm')"
    >
        <x-slot name="footer">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: '{{ \Marschux\ClearCache\Livewire\ClearCacheModal::ID }}' })">
                    {{ trans('filament-actions::modal.actions.cancel.label') }}
                </x-filament::button>
                <x-filament::button wire:click="clear" wire:loading.attr="disabled" wire:target="clear">
                    {{ trans('clear-cache::clear-cache.button') }}
                </x-filament::button>
            </div>
        </x-slot>
    </x-filament::modal>
</div>
