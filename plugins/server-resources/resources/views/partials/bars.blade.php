{{--
    The resource bars and the small facts line under them, shared by the panel host and the node
    cards. Inline styles: the panel's css is built from the core views only.

    $bars  - [['icon', 'label', 'text', 'percent']]
    $facts - [['icon', 'text']]
    $toggle - optional server count: puts the collapse button for the server list at the right end
              of the facts line; needs the caller's alpine "open"
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

@if ($facts !== [] || isset($toggle))
    <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem 1.5rem; margin-top: .875rem; font-size: .8125rem;">
        <div style="display: flex; flex-wrap: wrap; gap: .5rem 1.5rem; opacity: .7;">
            @foreach ($facts as $fact)
                <span style="display: flex; align-items: center; gap: .375rem;">
                    <x-filament::icon :icon="$fact['icon']" style="width: 1rem; height: 1rem;" />
                    {{ $fact['text'] }}
                </span>
            @endforeach
        </div>

        @isset($toggle)
            <button type="button" x-on:click="open = !open" style="display: flex; align-items: center; gap: .375rem; font-weight: 600;">
                {{ trans('server-resources::resources.node.servers', ['count' => $toggle]) }}
                <x-filament::icon icon="tabler-chevron-down" x-bind:style="open ? 'transition: transform .15s' : 'transform: rotate(90deg); transition: transform .15s'" style="width: 1rem; height: 1rem;" />
            </button>
        @endisset
    </div>
@endif
