@if ($installed->isNotEmpty() || $loaderInstall || $loader)
    @php
        $updatable = $installed->filter(fn ($install) => isset($updates[$install->package]) && !$install->pinned)->count();
        // filter by type, in the browser, loader and engine rows count too
        $types = collect([$loader ? 'loader' : null, $engineInstall || $canEngine ? 'engine' : null])
            ->concat($installed->map(fn ($install) => $this->type($install->package)[0])->sort())
            ->filter()->unique()->values();
    @endphp
    {{-- the section brings an x-data of its own, so the filter state lives around it --}}
    <div x-data="{ type: '' }" style="display:contents" @unless ($this->updatesChecked) wire:init="checkUpdates" @endunless>
    <x-filament::section>
        <x-slot name="afterHeader">
            <div class="mod-head" style="display:flex;gap:.5rem">
                @include('mod-manager::pages.mods.upload-button')
                <x-filament::button size="sm" color="gray" icon="tabler-users" x-on:click="$dispatch('open-modal', { id: 'mod-players' })">{{ trans('mod-manager::mods.players.button') }}</x-filament::button>
                @unless ($this->updatesChecked)
                    <span style="{{ $muted }};display:inline-flex;align-items:center;gap:.4rem"><x-filament::loading-indicator style="width:1rem;height:1rem" />{{ trans('mod-manager::mods.checking_updates') }}</span>
                @endunless
                @if ($updatable && $canUpdate)
                    <x-filament::button size="sm" icon="tabler-refresh" wire:click="updateAll">{{ trans('mod-manager::mods.update_all', ['count' => $updatable]) }}</x-filament::button>
                @endif
            </div>
        </x-slot>
        {{-- the type filter sits left, next to the heading --}}
        <x-slot name="heading">
            <div class="mod-head" style="display:flex;align-items:center;gap:1rem;font-weight:normal">
                <span style="font-weight:600">{{ trans('mod-manager::mods.tabs.installed') }} ({{ $installed->count() }})</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select x-model="type" :aria-label="trans('mod-manager::mods.columns.type')">
                        <option value="">{{ trans('mod-manager::mods.all_types') }}</option>
                        @foreach ($types as $key)
                            <option value="{{ $key }}">{{ trans("mod-manager::mods.types.$key") }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        </x-slot>

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
                        <th style="padding:.4rem .5rem .4rem 0;font-weight:500">{{ trans('mod-manager::mods.columns.mod') }}</th>
                        <th style="padding:.4rem .5rem;font-weight:500">{{ trans('mod-manager::mods.columns.type') }}</th>
                        <th style="padding:.4rem .5rem;font-weight:500">{{ trans('mod-manager::mods.columns.version') }}</th>
                        <th style="padding:.4rem .5rem;font-weight:500;text-align:right">{{ trans('mod-manager::mods.columns.size') }}</th>
                        <th style="padding:.4rem .5rem;font-weight:500">{{ trans('mod-manager::mods.columns.dependencies') }}</th>
                        <th style="padding:.4rem .5rem;font-weight:500">{{ trans('mod-manager::mods.columns.status') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @include('mod-manager::pages.mods.loader-missing-row')
                    {{-- what the profile offers comes first: loader, then engine, installed or not --}}
                    @foreach (collect([$loaderInstall, $engineInstall ?? ($canEngine ? 'engine' : null)])->filter()->concat($installed) as $install)
                        @if ($install === 'engine')
                            @include('mod-manager::pages.mods.engine-row')
                            @continue
                        @endif
                        @include('mod-manager::pages.mods.install-row')
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
    </div>
@endif
