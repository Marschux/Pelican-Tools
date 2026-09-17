{{--
    Layout is inline styles on purpose: the panel's css is built from the core views only, so a
    tailwind class used just here may simply not exist in it.
--}}
<x-filament-widgets::widget>
    <div wire:poll.15s style="display: grid; gap: 1.5rem;">
        @forelse ($nodes as $node)
            <x-filament::section>
                <x-slot name="heading">
                    <a href="{{ $node['url'] }}" style="font-weight: 600;">{{ $node['name'] }}</a>
                    <span style="opacity: .6; font-weight: 400; margin-left: .5rem;">{{ $node['fqdn'] }}</span>
                </x-slot>

                @if ($node['state'])
                    <x-filament::badge color="danger" style="margin-bottom: 1rem;">
                        {{ trans('server-resources::resources.node.' . $node['state']) }}
                    </x-filament::badge>
                @else
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 1rem; margin-bottom: 1.25rem;">
                        @foreach ($node['bars'] as $bar)
                            <div>
                                <div style="display: flex; justify-content: space-between; gap: .5rem; font-size: .875rem; margin-bottom: .25rem;">
                                    <span style="opacity: .7;">{{ $bar['label'] }}</span>
                                    <span style="font-weight: 600;">{{ $bar['text'] }}</span>
                                </div>
                                <div style="height: .5rem; border-radius: 9999px; background: rgba(127, 127, 127, .25); overflow: hidden;">
                                    <div style="height: 100%; width: {{ $bar['percent'] }}%; border-radius: 9999px; background: {{ $bar['percent'] >= 90 ? 'var(--danger-500, #ef4444)' : ($bar['percent'] >= 70 ? 'var(--warning-500, #f59e0b)' : 'var(--primary-500, #3b82f6)') }};"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($node['servers'] === [])
                    <div style="opacity: .6; font-size: .875rem;">{{ trans('server-resources::resources.node.no_servers') }}</div>
                @else
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; font-size: .875rem;">
                            <thead>
                                <tr style="text-align: left; opacity: .7;">
                                    <th style="padding: .5rem; width: 2.5rem;"></th>
                                    <th style="padding: .5rem;">{{ trans('server-resources::resources.server.name') }}</th>
                                    <th style="padding: .5rem;">{{ trans('server-resources::resources.server.egg') }}</th>
                                    <th style="padding: .5rem;">{{ trans('server-resources::resources.server.status') }}</th>
                                    <th style="padding: .5rem;">{{ trans('server-resources::resources.cpu') }}</th>
                                    <th style="padding: .5rem;">{{ trans('server-resources::resources.memory') }}</th>
                                    <th style="padding: .5rem;">{{ trans('server-resources::resources.disk') }}</th>
                                    <th style="padding: .5rem;">{{ trans('server-resources::resources.server.players') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($node['servers'] as $server)
                                    <tr style="border-top: 1px solid rgba(127, 127, 127, .2);">
                                        <td style="padding: .375rem .5rem;">
                                            @if ($server['icon'])
                                                <img src="{{ $server['icon'] }}" alt="" style="width: 2rem; height: 2rem; border-radius: .375rem; object-fit: cover;">
                                            @else
                                                <x-filament::icon icon="tabler-server" style="width: 1.5rem; height: 1.5rem; opacity: .5;" />
                                            @endif
                                        </td>
                                        <td style="padding: .375rem .5rem; white-space: nowrap;">
                                            <a href="{{ $server['url'] }}" style="font-weight: 600;">{{ $server['name'] }}</a>
                                        </td>
                                        <td style="padding: .375rem .5rem; white-space: nowrap;">{{ $server['egg'] ?? '-' }}</td>
                                        <td style="padding: .375rem .5rem;">
                                            @if ($server['status'])
                                                <x-filament::badge :color="$server['color']">{{ $server['status'] }}</x-filament::badge>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td style="padding: .375rem .5rem; white-space: nowrap;">{{ $server['cpu'] ?? '-' }}</td>
                                        <td style="padding: .375rem .5rem; white-space: nowrap;">{{ $server['memory'] ?? '-' }}</td>
                                        <td style="padding: .375rem .5rem; white-space: nowrap;">{{ $server['disk'] ?? '-' }}</td>
                                        <td style="padding: .375rem .5rem; white-space: nowrap;">{{ $server['players'] ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @empty
            <x-filament::section>{{ trans('server-resources::resources.node.none') }}</x-filament::section>
        @endforelse
    </div>
</x-filament-widgets::widget>
