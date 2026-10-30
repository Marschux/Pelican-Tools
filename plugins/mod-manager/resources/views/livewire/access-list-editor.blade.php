{{--
    The buttons are plain blade components rather than Filament actions: page and table actions
    did not render at all in this panel, and a list editor whose only button is invisible is worthless.
--}}
<form wire:submit="save" style="display:grid;gap:1.5rem">
    @if ($missing)
        <div style="display:flex;gap:.5rem;align-items:center;font-size:.875rem;opacity:.75">
            <x-filament::icon icon="tabler-file-plus" style="width:1.1rem;height:1.1rem" />
            {{ trans('mod-manager::lists.messages.missing_file') }}
        </div>
    @endif

    {{ $this->form }}

    <div style="display:flex;flex-wrap:wrap;gap:.75rem">
        <x-filament::button type="submit" icon="tabler-device-floppy">
            {{ trans('mod-manager::lists.page.save') }}
        </x-filament::button>

        <x-filament::button type="button" color="gray" icon="tabler-reload" wire:click="reload">
            {{ trans('mod-manager::lists.page.reload') }}
        </x-filament::button>
    </div>
</form>
