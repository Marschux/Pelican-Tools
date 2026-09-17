<x-filament-widgets::widget>
    <div wire:poll.10s>
        <x-filament::section>
            <x-slot name="heading">
                <span style="display: flex; align-items: center; gap: .5rem;">
                    <x-filament::icon icon="tabler-layout-dashboard" style="width: 1.25rem; height: 1.25rem;" />
                    {{ trans('server-resources::resources.host.heading') }}
                </span>
            </x-slot>

            @include('server-resources::partials.bars', ['bars' => $bars, 'facts' => $facts])
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
