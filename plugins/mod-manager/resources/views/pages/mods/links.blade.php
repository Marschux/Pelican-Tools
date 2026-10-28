{{-- what goes in by link: plugins from GitHub releases, on Counter-Strike 2 also workshop maps --}}
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
        {{-- a plugin from a GitHub release, pasted as a link; several files in it - pick one --}}
        @if ($canInstall && $this->canUpload())
            <form wire:submit="installGitHub" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:1rem">
                <x-filament::input.wrapper prefix-icon="tabler-brand-github" style="flex:1 1 20rem">
                    <x-filament::input type="text" wire:model="githubLink" :placeholder="trans('mod-manager::mods.github.placeholder')" />
                </x-filament::input.wrapper>
                <x-filament::button type="submit" icon="tabler-download" wire:loading.attr="disabled" wire:target="installGitHub">{{ trans('mod-manager::mods.install') }}</x-filament::button>
            </form>
            @if ($githubAssets)
                <div style="display:flex;flex-direction:column;gap:.5rem;margin-bottom:1rem">
                    <span style="font-size:.875rem;{{ $muted }}">{{ trans('mod-manager::mods.github.pick') }}</span>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                        @foreach ($githubAssets as $asset)
                            <x-filament::button size="sm" color="gray" icon="tabler-file-zip" wire:click="installGitHub({{ $js($asset['name']) }})" wire:loading.attr="disabled">
                                {{ $asset['name'] }} ({{ \Illuminate\Support\Number::fileSize($asset['size'], 1) }})
                            </x-filament::button>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif
</x-filament::section>
