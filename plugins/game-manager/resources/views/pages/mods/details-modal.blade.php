<x-filament::modal id="mod-details" width="5xl">
    <div wire:loading.flex wire:target="showDetails" style="justify-content:center;padding:3rem">
        <x-filament::loading-indicator style="width:2rem;height:2rem" />
    </div>
    {{-- the details of the mod before stay hidden while the next ones load --}}
    <div wire:loading.remove wire:target="showDetails" style="display:contents">
    @if ($details)
        <x-slot name="heading">
            <div wire:loading.remove wire:target="showDetails" style="display:flex;gap:1rem;align-items:center">
                @if ($details['icon'])
                    <img src="{{ $details['icon'] }}" alt="" style="{{ $icon }};width:4rem;height:4rem">
                @endif
                <div>
                    <div>{{ $details['name'] }} <span style="{{ $muted }}">{{ $details['version'] }}</span></div>
                    <div style="{{ $muted }};font-weight:400">
                        @if ($details['author'] !== '')
                            {{ trans('game-manager::mods.by', ['author' => $details['author']]) }} ·
                        @endif
                        ⬇ {{ number_format($details['downloads']) }} · ★ {{ $details['rating'] }}
                        @if ($details['size'])
                            · {{ \Illuminate\Support\Number::fileSize($details['size']) }}
                        @endif
                        {{-- not every source counts who depends on a package --}}
                        @if ($details['dependants'] !== null)
                            · {{ trans_choice('game-manager::mods.used_by', $details['dependants'], ['count' => number_format($details['dependants'])]) }}
                        @endif
                    </div>
                </div>
            </div>
        </x-slot>

        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
            @if (isset($versions[$details['id']]))
                <span class="mod-installed"><x-filament::icon icon="tabler-check" style="width:1rem;height:1rem" />{{ trans('game-manager::mods.is_installed') }}</span>
            @elseif ($canInstall && $details['versions'] !== [])
                <x-filament::button icon="tabler-download" wire:click="install({{ $js($details['id']) }})">{{ trans('game-manager::mods.install') }}</x-filament::button>
            @endif
            <x-filament::button color="gray" icon="tabler-external-link" tag="a" :href="$details['url']" target="_blank">{{ $this->sourceName($details['id']) }}</x-filament::button>
            @if ($details['website'])
                <x-filament::button color="gray" icon="tabler-world" tag="a" :href="$details['website']" target="_blank">{{ trans('game-manager::mods.website') }}</x-filament::button>
            @endif
        </div>

        <div style="display:grid;gap:1.5rem;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr))">
            <div>
                <div style="font-weight:600;margin-bottom:.5rem">{{ trans('game-manager::mods.dependencies') }}</div>
                @forelse ($details['dependencies'] as $dependency)
                    <div style="display:flex;gap:.5rem;align-items:center;padding:.25rem 0">
                        @if ($dependency['icon'])
                            <img src="{{ $dependency['icon'] }}" alt="" style="{{ $icon }};width:1.75rem;height:1.75rem">
                        @endif
                        <span style="flex:1">{{ $dependency['name'] }} <span style="{{ $muted }}">{{ $dependency['version'] }}</span></span>
                        @if ($dependency['hidden'])
                            <x-filament::badge color="gray" size="sm">{{ trans('game-manager::mods.loader_dependency') }}</x-filament::badge>
                        @elseif ($dependency['optional'] ?? false)
                            <x-filament::badge color="gray" size="sm">{{ trans('game-manager::mods.optional') }}</x-filament::badge>
                        @endif
                    </div>
                @empty
                    <span style="{{ $muted }}">{{ trans('game-manager::mods.no_dependencies') }}</span>
                @endforelse
            </div>
            <div>
                <div style="font-weight:600;margin-bottom:.5rem">{{ trans('game-manager::mods.versions') }}</div>
                {{-- one select instead of a long list; the button knows which version is picked --}}
                @php
                    $current = $versions[$details['id']] ?? null;
                @endphp
                @if ($details['versions'] === [])
                    {{-- the version filter left nothing: made for other game versions only --}}
                    <span style="{{ $muted }}">{{ trans('game-manager::mods.no_versions') }}</span>
                @else
                    <div x-data="{ picked: {{ \Illuminate\Support\Js::from($details['versions'][0]['number'] ?? '') }}, current: {{ \Illuminate\Support\Js::from($current) }} }" wire:key="versions-{{ md5($details['id']) }}" style="display:flex;gap:.5rem;align-items:center">
                        <x-filament::input.wrapper style="flex:1">
                            <x-filament::input.select x-model="picked">
                                @foreach ($details['versions'] as $version)
                                    <option value="{{ $version['number'] }}">
                                        {{ $version['number'] }} · {{ \Illuminate\Support\Carbon::parse($version['date'])->isoFormat('L') }} · ⬇ {{ \Illuminate\Support\Number::abbreviate($version['downloads']) }}{{ $version['number'] === $current ? ' · ' . trans('game-manager::mods.current') : '' }}
                                    </option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        <x-filament::button icon="tabler-download" x-show="{{ $canInstall ? 'picked !== current' : 'false' }}" x-on:click="$wire.install({{ \Illuminate\Support\Js::from($details['id']) }}, picked)">{{ trans('game-manager::mods.install') }}</x-filament::button>
                        <x-filament::badge color="success" icon="tabler-check" x-show="picked === current" x-cloak>{{ trans('game-manager::mods.current') }}</x-filament::badge>
                    </div>
                @endif
            </div>
        </div>

        <style>.mod-readme img { max-width: 100%; height: auto; }</style>
        <div x-data="{ show: 'readme' }" wire:key="details-{{ md5($details['id']) }}" style="border-top:1px solid rgba(127,127,127,.2);padding-top:1rem">
            @if ($details['changelog'])
                <x-filament::tabs style="margin-bottom:1rem">
                    <x-filament::tabs.item alpine-active="show === 'readme'" x-on:click="show = 'readme'">{{ trans('game-manager::mods.readme') }}</x-filament::tabs.item>
                    <x-filament::tabs.item alpine-active="show === 'changelog'" x-on:click="show = 'changelog'">{{ trans('game-manager::mods.changelog') }}</x-filament::tabs.item>
                </x-filament::tabs>
            @endif
            <div class="fi-prose mod-readme" style="max-width:none" x-show="show === 'readme'">{!! $details['readme'] !!}</div>
            @if ($details['changelog'])
                <div class="fi-prose mod-readme" style="max-width:none" x-show="show === 'changelog'" x-cloak>{!! $details['changelog'] !!}</div>
            @endif
        </div>
    @endif
    </div>
</x-filament::modal>
