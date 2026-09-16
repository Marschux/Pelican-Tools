{{-- a plain blade button: Filament actions did not render in this panel's own pages --}}
<form wire:submit="save" class="grid gap-6">
    {{ $this->form }}

    <div>
        <x-filament::button type="submit" icon="tabler-device-floppy">
            {{ trans('server-navigation::navigation.save') }}
        </x-filament::button>
    </div>
</form>
