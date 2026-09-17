{{--
    The resource bars and the small facts line under them, shared by the panel host and the node
    cards. Inline styles: the panel's css is built from the core views only.

    $bars  - [['icon', 'label', 'text', 'percent']]
    $facts - [['icon', 'text']]
--}}
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 1rem;">
    @foreach ($bars as $bar)
        <div>
            <div style="display: flex; justify-content: space-between; align-items: center; gap: .5rem; font-size: .875rem; margin-bottom: .25rem;">
                <span style="display: flex; align-items: center; gap: .375rem; opacity: .7;">
                    <x-filament::icon :icon="$bar['icon']" style="width: 1rem; height: 1rem;" />
                    {{ $bar['label'] }}
                </span>
                <span style="font-weight: 600;">{{ $bar['text'] }}</span>
            </div>
            <div style="height: .5rem; border-radius: 9999px; background: rgba(127, 127, 127, .25); overflow: hidden;">
                <div style="height: 100%; width: {{ $bar['percent'] }}%; border-radius: 9999px; background: {{ $bar['percent'] >= 90 ? 'var(--danger-500, #ef4444)' : ($bar['percent'] >= 70 ? 'var(--warning-500, #f59e0b)' : 'var(--primary-500, #3b82f6)') }};"></div>
            </div>
        </div>
    @endforeach
</div>

@if ($facts !== [])
    <div style="display: flex; flex-wrap: wrap; gap: .5rem 1.5rem; margin-top: .875rem; font-size: .8125rem; opacity: .7;">
        @foreach ($facts as $fact)
            <span style="display: flex; align-items: center; gap: .375rem;">
                <x-filament::icon :icon="$fact['icon']" style="width: 1rem; height: 1rem;" />
                {{ $fact['text'] }}
            </span>
        @endforeach
    </div>
@endif
