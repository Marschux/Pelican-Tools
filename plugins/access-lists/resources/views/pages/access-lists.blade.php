{{--
    The save button and the file tabs are plain blade components in this view rather than Filament
    page actions: page and table actions did not render at all in this panel, and a list editor
    whose only button is invisible is worthless.
--}}
<x-filament-panels::page>
    <x-filament::tabs>
        @foreach ($this->fileTabs() as $key => $label)
            <x-filament::tabs.item
                :active="($this->data['file'] ?? null) === $key"
                wire:click="switchFile(@js($key))"
            >
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    <form wire:submit="save" class="grid gap-6">
        {{ $this->form }}

        <div class="flex flex-wrap gap-3">
            <x-filament::button type="submit" icon="tabler-device-floppy">
                {{ trans('access-lists::lists.page.save') }}
            </x-filament::button>

            <x-filament::button type="button" color="gray" icon="tabler-reload" wire:click="reload">
                {{ trans('access-lists::lists.page.reload') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
