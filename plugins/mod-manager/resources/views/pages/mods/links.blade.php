{{-- what goes in by link: Counter-Strike 2's workshop maps --}}
@php
    $hasWorkshopMaps = $this->installer()->installs()->contains(fn ($install) => str_starts_with($install->package, \Marschux\ModManager\Sources\Workshop::PREFIX));
@endphp
<x-filament::section>
        {{-- Counter-Strike 2: a workshop map by id or link; without a map vote plugin only the server's start map is played --}}
        @if ($this->source2())
            <form wire:submit="addWorkshopMap" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.5rem">
                <x-filament::input.wrapper prefix-icon="tabler-map" style="flex:1 1 20rem">
                    <x-filament::input type="text" wire:model="workshopLink" :placeholder="trans('mod-manager::mods.workshop.placeholder')" />
                </x-filament::input.wrapper>
                <x-filament::button type="submit" color="gray" icon="tabler-plus" wire:loading.attr="disabled" wire:target="addWorkshopMap">{{ trans('mod-manager::mods.workshop.add_map') }}</x-filament::button>
            </form>
            @if (!$this->hasLoginToken() && $hasWorkshopMaps)
                <div style="margin-bottom:.5rem;font-size:.875rem;color:rgb(234,179,8)">{{ trans('mod-manager::mods.workshop.token_hint') }}</div>
            @endif
        @endif
</x-filament::section>
