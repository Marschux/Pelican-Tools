@php
    $gameVersion = $this->gameVersion();
@endphp
@if ($gameVersion)
    <span style="{{ $muted }}">{{ trans('mod-manager::mods.game_version', $gameVersion) }}</span>
@endif
{{-- every change searches right away, typing waits for a short pause --}}
<form wire:submit="search" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
    <x-filament::input.wrapper prefix-icon="tabler-search" style="flex:1 1 16rem">
        <x-filament::input type="search" wire:model.live.debounce.500ms="query" :placeholder="trans($this->sourceKey() === 'gamebanana' ? 'mod-manager::mods.search_maps' : 'mod-manager::mods.search_placeholder')" />
    </x-filament::input.wrapper>
    <x-filament::input.wrapper style="flex:0 1 14rem">
        <x-filament::input.select wire:model.live="category">
            <option value="">{{ trans('mod-manager::mods.all_categories') }}</option>
            @foreach ($this->categories() as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </x-filament::input.select>
    </x-filament::input.wrapper>
    <x-filament::input.wrapper style="flex:0 1 14rem">
        <x-filament::input.select wire:model.live="ordering">
            @foreach (\Marschux\ModManager\Sources\Thunderstore::ORDERINGS as $order)
                <option value="{{ $order }}">{{ trans($order === 'most-downloaded' && $this->sourceKey() === 'gamebanana' ? 'mod-manager::mods.orderings.most-viewed' : "mod-manager::mods.orderings.$order") }}</option>
            @endforeach
        </x-filament::input.select>
    </x-filament::input.wrapper>
    {{-- thunderstore tags client-only and deprecated packages, other sources do not --}}
    @if ($this->sourceKey() === 'thunderstore')
        <label style="display:flex;gap:.5rem;align-items:center;font-size:.875rem;cursor:pointer">
            <x-filament::input.checkbox wire:model.live="showClientSide" />
            {{ trans('mod-manager::mods.show_client_side') }}
        </label>
        <label style="display:flex;gap:.5rem;align-items:center;font-size:.875rem;cursor:pointer">
            <x-filament::input.checkbox wire:model.live="showDeprecated" />
            {{ trans('mod-manager::mods.show_deprecated') }}
        </label>
    @endif
    @if ($gameVersion)
        <label style="display:flex;gap:.5rem;align-items:center;font-size:.875rem;cursor:pointer">
            <x-filament::input.checkbox wire:model.live="showAll" />
            {{ trans('mod-manager::mods.show_all_versions', ['version' => $gameVersion['version']]) }}
        </label>
    @endif
</form>

{{-- while the next page loads the tiles stay and only fade, so nothing jumps --}}
<style>.mod-loading { opacity: .4; pointer-events: none; transition: opacity .15s; }</style>

{{-- three tiles side by side, fewer when the page gets narrow; every row as tall as the tallest, so all tiles are the same size --}}
<div wire:loading.class="mod-loading" wire:target="search,goToPage,source,kind,query,category,ordering,showClientSide,showDeprecated,showAll" style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(max(16rem,calc((100% - 2rem) / 3)),1fr));grid-auto-rows:1fr">
    @forelse ($items as $item)
        <div wire:key="item-{{ md5($item['id']) }}" style="{{ $card }}">
            <div style="display:flex;gap:.75rem;align-items:center;cursor:pointer" x-on:click="$dispatch('open-modal', { id: 'mod-details' }); $wire.showDetails({{ $js($item['id']) }})">
                @if ($item['icon'])
                    <img src="{{ $item['icon'] }}" alt="" loading="lazy" style="{{ $icon }};width:4rem;height:4rem">
                @else
                    <div style="{{ $icon }};width:4rem;height:4rem"></div>
                @endif
                <div style="flex:1;min-width:0">
                    <div style="font-weight:600;font-size:1.05rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $item['name'] }}</div>
                    {{-- the workshop only gives a Steam id for who made it --}}
                    @if ($item['author'] !== '')
                        <div style="{{ $muted }}">{{ trans('mod-manager::mods.by', ['author' => $item['author']]) }}</div>
                    @endif
                    <div style="{{ $muted }};display:flex;gap:.75rem">
                        @if (isset($item['views']))
                            {{-- a source that lists no downloads --}}
                            <span title="{{ trans('mod-manager::mods.views') }}">👁 {{ \Illuminate\Support\Number::abbreviate($item['views']) }}</span>
                        @else
                            <span title="{{ trans('mod-manager::mods.downloads') }}">⬇ {{ \Illuminate\Support\Number::abbreviate($item['downloads']) }}</span>
                        @endif
                        <span title="{{ trans('mod-manager::mods.rating') }}">★ {{ $item['rating'] }}</span>
                        @if ($item['updated'])
                            <span>{{ \Illuminate\Support\Carbon::parse($item['updated'])->diffForHumans() }}</span>
                        @endif
                    </div>
                </div>
            </div>

            <div x-on:click="$dispatch('open-modal', { id: 'mod-details' }); $wire.showDetails({{ $js($item['id']) }})" style="cursor:pointer;font-size:.875rem;opacity:.8;flex:1;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden">{{ $item['summary'] }}</div>

            @if ($item['categories'] || $item['deprecated'] || $item['side'])
                <div style="display:flex;gap:.25rem;flex-wrap:wrap">
                    @if ($item['side'] === 'server')
                        <x-filament::badge color="success" size="sm" icon="tabler-server">{{ trans('mod-manager::mods.side.server') }}</x-filament::badge>
                    @elseif ($item['side'] === 'client')
                        <x-filament::badge color="warning" size="sm" icon="tabler-device-desktop">{{ trans('mod-manager::mods.side.client') }}</x-filament::badge>
                    @endif
                    @if ($item['deprecated'])
                        <x-filament::badge color="danger" size="sm">{{ trans('mod-manager::mods.deprecated') }}</x-filament::badge>
                    @endif
                    @foreach (array_slice(array_diff($item['categories'], ['Server-side', 'Client-side']), 0, 3) as $category)
                        <x-filament::badge color="gray" size="sm">{{ $category }}</x-filament::badge>
                    @endforeach
                </div>
            @endif

            <div style="display:flex;gap:.5rem;align-items:center">
                @if (isset($versions[$item['id']]))
                    <span class="mod-installed"><x-filament::icon icon="tabler-check" style="width:1rem;height:1rem" />{{ trans('mod-manager::mods.is_installed') }}</span>
                @elseif ($canInstall)
                    <x-filament::button size="sm" icon="tabler-download" wire:click="install({{ $js($item['id']) }})">{{ trans('mod-manager::mods.install') }}</x-filament::button>
                @endif
                @if ($item['url'] ?? null)
                    <x-filament::button size="sm" color="gray" icon="tabler-external-link" tag="a" :href="$item['url']" target="_blank">{{ $this->sourceName($item['id']) }}</x-filament::button>
                @endif
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
    <div style="display:flex;justify-content:center;align-items:center;gap:.25rem;flex-wrap:wrap" wire:loading.class="mod-loading" wire:target="search,goToPage,source,kind,query,category,ordering,showClientSide,showDeprecated,showAll">
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
