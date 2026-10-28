<x-filament-widgets::widget>
    <div wire:poll.10s>
        <x-filament::section>
            <x-slot name="heading">
                <span style="display: flex; align-items: center; gap: .5rem;">
                    <x-filament::icon icon="tabler-layout-dashboard" style="width: 1.25rem; height: 1.25rem;" />
                    {{ trans('server-resources::resources.host.heading') }}
                    <span style="opacity: .6; font-weight: 400;">{{ parse_url((string) config('app.url'), PHP_URL_HOST) }}</span>
                    @if ($update)
                        <x-filament::badge color="warning" icon="tabler-download" style="margin-left: .5rem;">{{ trans('server-resources::resources.update', ['version' => $update]) }}</x-filament::badge>
                    @endif
                </span>
            </x-slot>

            @include('server-resources::partials.bars', ['bars' => $bars, 'facts' => $facts])
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
