{{--
    Plain blade components and inline styles: Filament page actions did not render in this panel,
    and only the utility classes Filament itself uses exist in its stylesheet.
--}}
@php
    $loader = $this->loader();
    $card = 'display:flex;flex-direction:column;gap:.75rem;padding:1rem;border:1px solid rgba(127,127,127,.2);border-radius:.75rem';
    $icon = 'flex:none;border-radius:.5rem;object-fit:cover;background:rgba(127,127,127,.15)';
    $muted = 'font-size:.8rem;opacity:.7';
    $js = fn (string $value) => \Illuminate\Support\Js::from($value);
@endphp

<x-filament-panels::page>
    {{-- the mod loader: shown only, installing it is step 3 --}}
    @if ($loader)
        <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;padding:.75rem 1rem;border-radius:.75rem;border:1px solid rgba(127,127,127,.2)">
            <span style="font-weight:600">{{ $loader['name'] }}</span>
            @if ($loader['installed'] && $loader['started'])
                <x-filament::badge color="success" icon="tabler-check">{{ trans('mod-manager::mods.loader.ready') }}</x-filament::badge>
            @elseif ($loader['installed'])
                <x-filament::badge color="warning" icon="tabler-alert-triangle">{{ trans('mod-manager::mods.loader.not_started') }}</x-filament::badge>
            @else
                <x-filament::badge color="danger" icon="tabler-x">{{ trans('mod-manager::mods.loader.missing') }}</x-filament::badge>
            @endif
            <span style="{{ $muted }};flex:1">{{ trans('mod-manager::mods.loader.hint', ['loader' => $loader['name']]) }}</span>
            <x-filament::button size="sm" color="gray" icon="tabler-download" disabled>
                {{ trans($loader['installed'] ? 'mod-manager::mods.update' : 'mod-manager::mods.install') }}
            </x-filament::button>
        </div>
    @endif

    <x-filament::tabs>
        <x-filament::tabs.item icon="tabler-package" :active="$tab === 'installed'" wire:click="setTab('installed')">
            {{ trans('mod-manager::mods.tabs.installed') }}
        </x-filament::tabs.item>
        <x-filament::tabs.item icon="tabler-search" :active="$tab === 'browse'" wire:click="setTab('browse')">
            {{ trans('mod-manager::mods.tabs.browse') }}
        </x-filament::tabs.item>
    </x-filament::tabs>

    @if ($tab === 'installed')
        <x-filament::section>
            <div style="display:flex;flex-direction:column;align-items:center;gap:.75rem;padding:2rem 0;text-align:center">
                <x-filament::icon icon="tabler-package-off" style="width:2.5rem;height:2.5rem;opacity:.5" />
                <span style="opacity:.7">{{ trans('mod-manager::mods.nothing_installed') }}</span>
                <x-filament::button color="gray" icon="tabler-search" wire:click="setTab('browse')">{{ trans('mod-manager::mods.tabs.browse') }}</x-filament::button>
            </div>
        </x-filament::section>
    @else
        <form wire:submit="search" style="display:flex;gap:.75rem;flex-wrap:wrap">
            <x-filament::input.wrapper style="flex:1 1 16rem">
                <x-filament::input type="search" wire:model="query" :placeholder="trans('mod-manager::mods.search_placeholder')" />
            </x-filament::input.wrapper>
            <x-filament::input.wrapper style="flex:0 1 14rem">
                <x-filament::input.select wire:model="category" wire:change="search">
                    <option value="">{{ trans('mod-manager::mods.all_categories') }}</option>
                    @foreach ($this->categories() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
            <x-filament::input.wrapper style="flex:0 1 14rem">
                <x-filament::input.select wire:model="ordering" wire:change="search">
                    @foreach (\Marschux\ModManager\Sources\Thunderstore::ORDERINGS as $order)
                        <option value="{{ $order }}">{{ trans("mod-manager::mods.orderings.$order") }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
            <x-filament::button type="submit" icon="tabler-search">{{ trans('mod-manager::mods.search') }}</x-filament::button>
        </form>

        <div wire:loading.flex wire:target="search,goToPage" style="justify-content:center;padding:2rem">
            <x-filament::loading-indicator style="width:2rem;height:2rem" />
        </div>

        {{-- three tiles side by side, fewer when the page gets narrow --}}
        <div wire:loading.remove wire:target="search,goToPage" style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(max(16rem,calc((100% - 2rem) / 3)),1fr))">
            @forelse ($items as $item)
                <div wire:key="item-{{ md5($item['id']) }}" style="{{ $card }}">
                    <div style="display:flex;gap:.75rem;align-items:center;cursor:pointer" wire:click="showDetails({{ $js($item['id']) }})">
                        @if ($item['icon'])
                            <img src="{{ $item['icon'] }}" alt="" loading="lazy" style="{{ $icon }};width:4rem;height:4rem">
                        @else
                            <div style="{{ $icon }};width:4rem;height:4rem"></div>
                        @endif
                        <div style="flex:1;min-width:0">
                            <div style="font-weight:600;font-size:1.05rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $item['name'] }}</div>
                            <div style="{{ $muted }}">{{ trans('mod-manager::mods.by', ['author' => $item['author']]) }}</div>
                            <div style="{{ $muted }};display:flex;gap:.75rem">
                                <span title="{{ trans('mod-manager::mods.downloads') }}">⬇ {{ \Illuminate\Support\Number::abbreviate($item['downloads']) }}</span>
                                <span title="{{ trans('mod-manager::mods.rating') }}">★ {{ $item['rating'] }}</span>
                                @if ($item['updated'])
                                    <span>{{ \Illuminate\Support\Carbon::parse($item['updated'])->diffForHumans() }}</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div style="font-size:.875rem;opacity:.8;flex:1;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden">{{ $item['summary'] }}</div>

                    @if ($item['categories'] || $item['deprecated'])
                        <div style="display:flex;gap:.25rem;flex-wrap:wrap">
                            @if ($item['deprecated'])
                                <x-filament::badge color="danger" size="sm">{{ trans('mod-manager::mods.deprecated') }}</x-filament::badge>
                            @endif
                            @foreach (array_slice($item['categories'], 0, 3) as $category)
                                <x-filament::badge color="gray" size="sm">{{ $category }}</x-filament::badge>
                            @endforeach
                        </div>
                    @endif

                    <div style="display:flex;gap:.5rem">
                        <x-filament::button size="sm" icon="tabler-download" wire:click="install({{ $js($item['id']) }})">{{ trans('mod-manager::mods.install') }}</x-filament::button>
                        <x-filament::button size="sm" color="gray" icon="tabler-info-circle" wire:click="showDetails({{ $js($item['id']) }})">{{ trans('mod-manager::mods.details') }}</x-filament::button>
                    </div>
                </div>
            @empty
                <span style="opacity:.7">{{ trans('mod-manager::mods.no_results') }}</span>
            @endforelse
        </div>

        @if ($pages > 1)
            {{-- first, last and two around the current page, gaps as dots --}}
            @php
                $shown = collect([1, $pages, ...range(max(1, $page - 2), min($pages, $page + 2))])->unique()->sort()->values();
            @endphp
            <div style="display:flex;justify-content:center;align-items:center;gap:.25rem;flex-wrap:wrap" wire:loading.remove wire:target="search,goToPage" x-on:click="window.scrollTo({ top: 0, behavior: 'smooth' })">
                <x-filament::icon-button icon="tabler-chevron-left" color="gray" :disabled="$page <= 1" wire:click="goToPage({{ $page - 1 }})" :label="trans('mod-manager::mods.previous')" />
                @foreach ($shown as $index => $number)
                    @if ($index > 0 && $number - $shown[$index - 1] > 1)
                        <span style="opacity:.5;padding:0 .25rem">…</span>
                    @endif
                    <x-filament::button size="sm" :color="$number === $page ? 'primary' : 'gray'" :outlined="$number !== $page" wire:click="goToPage({{ $number }})">{{ $number }}</x-filament::button>
                @endforeach
                <x-filament::icon-button icon="tabler-chevron-right" color="gray" :disabled="$page >= $pages" wire:click="goToPage({{ $page + 1 }})" :label="trans('mod-manager::mods.next')" />
            </div>
        @endif
    @endif

    <x-filament::modal id="mod-details" width="5xl">
        @if ($details)
            <x-slot name="heading">
                <div style="display:flex;gap:1rem;align-items:center">
                    @if ($details['icon'])
                        <img src="{{ $details['icon'] }}" alt="" style="{{ $icon }};width:4rem;height:4rem">
                    @endif
                    <div>
                        <div>{{ $details['name'] }} <span style="{{ $muted }}">{{ $details['version'] }}</span></div>
                        <div style="{{ $muted }};font-weight:400">
                            {{ trans('mod-manager::mods.by', ['author' => $details['author']]) }} ·
                            ⬇ {{ number_format($details['downloads']) }} · ★ {{ $details['rating'] }}
                        </div>
                    </div>
                </div>
            </x-slot>

            <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                <x-filament::button icon="tabler-download" wire:click="install({{ $js($details['id']) }})">{{ trans('mod-manager::mods.install') }}</x-filament::button>
                <x-filament::button color="gray" icon="tabler-external-link" tag="a" :href="$details['url']" target="_blank">Thunderstore</x-filament::button>
                @if ($details['website'])
                    <x-filament::button color="gray" icon="tabler-world" tag="a" :href="$details['website']" target="_blank">{{ trans('mod-manager::mods.website') }}</x-filament::button>
                @endif
            </div>

            <div style="display:grid;gap:1.5rem;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr))">
                <div>
                    <div style="font-weight:600;margin-bottom:.5rem">{{ trans('mod-manager::mods.dependencies') }}</div>
                    @forelse ($details['dependencies'] as $dependency)
                        <div style="display:flex;gap:.5rem;align-items:center;padding:.25rem 0">
                            @if ($dependency['icon'])
                                <img src="{{ $dependency['icon'] }}" alt="" style="{{ $icon }};width:1.75rem;height:1.75rem">
                            @endif
                            <span style="flex:1">{{ $dependency['name'] }} <span style="{{ $muted }}">{{ $dependency['version'] }}</span></span>
                            @if ($dependency['hidden'])
                                <x-filament::badge color="gray" size="sm">{{ trans('mod-manager::mods.loader_dependency') }}</x-filament::badge>
                            @endif
                        </div>
                    @empty
                        <span style="{{ $muted }}">{{ trans('mod-manager::mods.no_dependencies') }}</span>
                    @endforelse
                </div>
                <div>
                    <div style="font-weight:600;margin-bottom:.5rem">{{ trans('mod-manager::mods.versions') }}</div>
                    <div style="max-height:12rem;overflow-y:auto">
                        @foreach ($details['versions'] as $version)
                            <div style="display:flex;justify-content:space-between;padding:.2rem 0;font-size:.875rem">
                                <span>{{ $version['number'] }}</span>
                                <span style="{{ $muted }}">{{ \Illuminate\Support\Carbon::parse($version['date'])->isoFormat('L') }} · ⬇ {{ \Illuminate\Support\Number::abbreviate($version['downloads']) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <style>.mod-readme img { max-width: 100%; height: auto; }</style>
            <div class="fi-prose mod-readme" style="max-width:none;border-top:1px solid rgba(127,127,127,.2);padding-top:1rem">
                {!! $details['readme'] !!}
            </div>
        @endif
    </x-filament::modal>
</x-filament-panels::page>
