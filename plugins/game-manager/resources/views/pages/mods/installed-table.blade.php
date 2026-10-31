@php $withLoader = $section === 'mods'; @endphp
@if ($installed->isNotEmpty() || ($withLoader && ($loaderInstall || $loader)))
    @php
        $updatable = $installed->filter(fn ($install) => isset($updates[$install->package]) && !$install->pinned)->count();
        // filter by type, in the browser, loader and engine rows count too
        $installedTypes = $installed->map(fn ($install) => $this->type($install->package)[0]);
        $types = collect([$withLoader && $loader ? 'loader' : null, $engineInstall || $canEngine ? 'engine' : null, $canEngine ? 'plugin' : null])
            ->concat($installedTypes->sort())
            ->filter()->unique()->values();
        // the filter only where the section mixes types; without it or an update note the header goes
        $hasFilter = $installedTypes->unique()->count() > 1;
        $hasHeader = $hasFilter || !$this->updatesChecked || ($updatable && $canUpdate);
    @endphp
    {{-- the section brings an x-data of its own, so the filter state lives around it --}}
    <div x-data="{ type: '' }" style="display:contents" @unless ($this->updatesChecked) wire:init="checkUpdates" @endunless>
    <x-filament::section>
        @if ($hasHeader)
        <x-slot name="afterHeader">
            <div class="mod-head" style="display:flex;gap:.5rem">
                @unless ($this->updatesChecked)
                    <span style="{{ $muted }};display:inline-flex;align-items:center;gap:.4rem"><x-filament::loading-indicator style="width:1rem;height:1rem" />{{ trans('game-manager::mods.checking_updates') }}</span>
                @endunless
                @if ($updatable && $canUpdate)
                    <x-filament::button size="sm" icon="tabler-refresh" wire:click="updateAll">{{ trans('game-manager::mods.update_all', ['count' => $updatable]) }}</x-filament::button>
                @endif
            </div>
        </x-slot>
        {{-- the type filter sits left, next to the heading --}}
        <x-slot name="heading">
            <div class="mod-head" style="display:flex;align-items:center;gap:1rem;font-weight:normal">
                @if ($hasFilter)
                    <x-filament::input.wrapper>
                        <x-filament::input.select x-model="type" :aria-label="trans('game-manager::mods.columns.type')">
                            <option value="">{{ trans('game-manager::mods.all_types') }}</option>
                            @foreach ($types as $key)
                                <option value="{{ $key }}">{{ trans("game-manager::mods.types.$key") }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                @endif
            </div>
        </x-slot>
        @endif

        {{-- a table, scrolling sideways when the page gets narrow --}}
        {{-- icon buttons have a negative margin, so the last column keeps some room on the right, else the edge alone makes it scroll --}}
        <div style="overflow-x:auto;overflow-y:hidden">
            {{-- fixed column widths, the same for every game; below 60rem the table scrolls --}}
            <table style="width:100%;min-width:60rem;table-layout:fixed;border-collapse:collapse;font-size:.875rem">
                <colgroup>
                    <col style="width:22%">
                    <col style="width:9%">
                    <col style="width:10%">
                    <col style="width:7%">
                    <col style="width:20%">
                    <col style="width:12%">
                    <col style="width:20%">
                </colgroup>
                <thead>
                    <tr style="text-align:left;{{ $muted }}">
                        <th style="padding:.4rem .5rem .4rem 0;font-weight:500">{{ trans('game-manager::mods.columns.mod') }}</th>
                        <th style="padding:.4rem .5rem;font-weight:500">{{ trans('game-manager::mods.columns.type') }}</th>
                        <th style="padding:.4rem .5rem;font-weight:500">{{ trans('game-manager::mods.columns.version') }}</th>
                        <th style="padding:.4rem .5rem;font-weight:500;text-align:right">{{ trans('game-manager::mods.columns.size') }}</th>
                        <th style="padding:.4rem .5rem;font-weight:500">{{ trans('game-manager::mods.columns.dependencies') }}</th>
                        <th style="padding:.4rem .5rem;font-weight:500">{{ trans('game-manager::mods.columns.status') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @if ($withLoader)
                        @include('game-manager::pages.mods.loader-missing-row')
                    @endif
                    {{-- what the profile offers comes first: loader, then engine and bots, installed or not --}}
                    @foreach (collect($withLoader ? [$loaderInstall, $engineInstall ?? ($canEngine ? 'engine' : null), $botsInstall ?? ($canEngine ? 'bots' : null)] : [])->filter()->concat($installed) as $install)
                        @if ($install === 'engine')
                            @include('game-manager::pages.mods.offer-row', ['offer' => ['key' => 'engine', 'name' => 'ReHLDS + ReGameDLL + ReAPI', 'type' => 'engine', 'icon' => 'tabler-engine', 'action' => 'installEngine']])
                            @continue
                        @endif
                        @if ($install === 'bots')
                            @include('game-manager::pages.mods.offer-row', ['offer' => ['key' => 'bots', 'name' => 'YaPB', 'type' => 'plugin', 'icon' => 'tabler-robot', 'action' => 'installBots']])
                            @continue
                        @endif
                        @include('game-manager::pages.mods.install-row')
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
    </div>
@endif
