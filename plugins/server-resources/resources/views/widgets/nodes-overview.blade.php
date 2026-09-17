{{--
    Layout is inline styles on purpose: the panel's css is built from the core views only, so a
    tailwind class used just here may simply not exist in it.
--}}
<x-filament-widgets::widget>
    <div wire:poll.15s style="display: grid; gap: 1.5rem;">
        @forelse ($nodes as $node)
            <x-filament::section>
                <x-slot name="heading">
                    <x-filament::icon icon="tabler-server-2" style="width: 1.25rem; height: 1.25rem; display: inline-block; vertical-align: -.25rem; margin-right: .375rem;" />
                    <a href="{{ $node['url'] }}" style="font-weight: 600;">{{ $node['name'] }}</a>
                    <span style="opacity: .6; font-weight: 400; margin-left: .5rem;">{{ $node['fqdn'] }}</span>
                </x-slot>

                {{-- open/closed of the server list lives in alpine and is kept per node across reloads;
                     wire:ignore.self keeps the 15s poll from resetting it --}}
                <div x-data="{ open: $persist(true).as('server-resources.node.{{ $node['id'] }}.open') }" wire:ignore.self>
                @if ($node['state'])
                    <x-filament::badge color="danger" icon="tabler-alert-triangle">
                        {{ trans('server-resources::resources.node.' . $node['state']) }}
                    </x-filament::badge>
                @endif

                @include('server-resources::partials.bars', ['bars' => $node['bars'], 'facts' => $node['facts']] + ($node['servers'] === [] ? [] : ['toggle' => count($node['servers'])]))

                @if ($node['servers'] === [])
                    <div style="opacity: .6; font-size: .875rem; margin-top: 1.25rem;">{{ trans('server-resources::resources.node.no_servers') }}</div>
                @else
                    <div x-show="open" style="overflow-x: auto; margin-top: 1rem;">
                        <table style="width: 100%; border-collapse: collapse; font-size: .875rem;">
                            <thead>
                                <tr style="text-align: left; opacity: .7;">
                                    <th style="padding: .5rem; width: 1%; white-space: nowrap;"><span style="display: inline-flex; align-items: center; gap: .375rem;" title="{{ trans('server-resources::resources.server.external_id') }}"><x-filament::icon icon="tabler-hash" style="width: 1rem; height: 1rem;" /></span></th>
                                    <th style="padding: .5rem; width: 2.5rem;"></th>
                                    <th style="padding: .5rem;"><span style="display: inline-flex; align-items: center; gap: .375rem;"><x-filament::icon icon="tabler-server" style="width: 1rem; height: 1rem;" />{{ trans('server-resources::resources.server.name') }}</span></th>
                                    <th style="padding: .5rem;"><span style="display: inline-flex; align-items: center; gap: .375rem;"><x-filament::icon icon="tabler-egg" style="width: 1rem; height: 1rem;" />{{ trans('server-resources::resources.server.egg') }}</span></th>
                                    <th style="padding: .5rem; white-space: nowrap;"><span style="display: inline-flex; align-items: center; gap: .375rem;"><x-filament::icon icon="tabler-plug-connected" style="width: 1rem; height: 1rem;" />{{ trans('server-resources::resources.server.port') }}</span></th>
                                    <th style="padding: .5rem;"><span style="display: inline-flex; align-items: center; gap: .375rem;"><x-filament::icon icon="tabler-heart-rate-monitor" style="width: 1rem; height: 1rem;" />{{ trans('server-resources::resources.server.status') }}</span></th>
                                    <th style="padding: .5rem;"><span style="display: inline-flex; align-items: center; gap: .375rem;"><x-filament::icon icon="tabler-cpu" style="width: 1rem; height: 1rem;" />{{ trans('server-resources::resources.cpu') }}</span></th>
                                    <th style="padding: .5rem;"><span style="display: inline-flex; align-items: center; gap: .375rem;"><x-filament::icon icon="tabler-device-desktop-analytics" style="width: 1rem; height: 1rem;" />{{ trans('server-resources::resources.memory') }}</span></th>
                                    <th style="padding: .5rem;"><span style="display: inline-flex; align-items: center; gap: .375rem;"><x-filament::icon icon="tabler-device-sd-card" style="width: 1rem; height: 1rem;" />{{ trans('server-resources::resources.disk') }}</span></th>
                                    <th style="padding: .5rem;"><span style="display: inline-flex; align-items: center; gap: .375rem;"><x-filament::icon icon="tabler-users" style="width: 1rem; height: 1rem;" />{{ trans('server-resources::resources.server.players') }}</span></th>
                                    <th style="padding: .5rem;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($node['servers'] as $server)
                                    <tr style="border-top: 1px solid rgba(127, 127, 127, .2);">
                                        <td style="padding: .375rem .5rem; width: 1%; white-space: nowrap; opacity: .7;">{{ $server['external_id'] ?: '-' }}</td>
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
                                        <td style="padding: .375rem .5rem; white-space: nowrap;">
                                            @if ($server['egg_url'])
                                                <a href="{{ $server['egg_url'] }}">{{ $server['egg'] }}</a>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td style="padding: .375rem .5rem; white-space: nowrap;">
                                            @if ($server['port'])
                                                {{-- a plain span, not x-filament::badge: only the panel's registered colors work there, and
                                                 purple is not one of them --}}
                                            <a href="{{ $server['port_url'] }}"><span style="display: inline-flex; align-items: center; border-radius: .375rem; padding: .125rem .5rem; font-size: .75rem; font-weight: 500; line-height: 1.25rem; color: rgb(192 132 252); background-color: rgb(168 85 247 / .15); box-shadow: inset 0 0 0 1px rgb(168 85 247 / .3);">{{ $server['port'] }}</span></a>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td style="padding: .375rem .5rem;">
                                            @if ($server['status'])
                                                <a href="{{ $server['console_url'] }}"><x-filament::badge :color="$server['color']">{{ $server['status'] }}</x-filament::badge></a>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td style="padding: .375rem .5rem; white-space: nowrap;">{{ $server['cpu'] ?? '-' }}</td>
                                        <td style="padding: .375rem .5rem; white-space: nowrap;">{{ $server['memory'] ?? '-' }}</td>
                                        <td style="padding: .375rem .5rem; white-space: nowrap;">{{ $server['disk'] ?? '-' }}</td>
                                        <td style="padding: .375rem .5rem; white-space: nowrap;">
                                            @if ($server['players'] !== null)
                                                <x-filament::badge color="info">{{ $server['players'] }}</x-filament::badge>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td style="padding: .375rem .5rem; white-space: nowrap; text-align: right;">
                                            {{-- plain icon buttons: filament actions did not render in this panel's own views --}}
                                            <div style="display: inline-flex; align-items: center; gap: .25rem;">
                                            @foreach ($server['power'] as $action => $button)
                                                <x-filament::icon-button
                                                    :icon="$button['icon']"
                                                    :color="$button['color']"
                                                    :disabled="!$button['enabled']"
                                                    :label="trans('server-resources::resources.power.' . $action)"
                                                    :tooltip="trans('server-resources::resources.power.' . $action)"
                                                    wire:click="power({{ $server['id'] }}, '{{ $action }}')"
                                                    wire:loading.attr="disabled"
                                                />
                                            @endforeach
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                </div>
            </x-filament::section>
        @empty
            <x-filament::section>{{ trans('server-resources::resources.node.none') }}</x-filament::section>
        @endforelse
    </div>
</x-filament-widgets::widget>
