<x-filament-panels::page>
    <x-filament::tabs>
        @foreach ($this->fileTabs() as $key => $label)
            <x-filament::tabs.item :active="$file === $key" wire:click="switchFile('{{ $key }}')">
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{-- a new key mounts a fresh editor, reading the other file --}}
    <x-filament::section>
        @livewire('mod-manager.access-list-editor', ['server' => \Filament\Facades\Filament::getTenant(), 'file' => $file], key("list-$file"))
    </x-filament::section>
</x-filament-panels::page>
