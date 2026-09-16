{{--
    Filament's stats overview view with one extra class, "game-query-tiles": the console page css
    in GameQueryPlugin has to tell these tiles apart from the core overview, which uses the very
    same widget class.
--}}
<x-filament-widgets::widget
    :attributes="
        (new \Filament\Support\View\ComponentAttributeBag)
            ->merge([
                'wire:poll.' . $this->getPollingInterval() => $this->getPollingInterval() ? true : null,
            ], escape: false)
            ->class([
                'fi-wi-stats-overview',
                'game-query-tiles',
            ])
    "
>
    {{ $this->content }}
</x-filament-widgets::widget>
