{{--
    Mirrors the core "small stat block" markup (app/Filament/Server/Components/SmallStatBlock)
    exactly, label and value inside one shared <span> - the outer div is a bare grid with no
    columns defined, so two direct children would each get their own implicit row instead of
    sitting side by side. Alpine is already loaded panel-wide by Filament, so no extra script
    is needed for the reveal toggle.
--}}
<div
    x-data="{ revealed: false }"
    class="fi-small-stat-block grid grid-flow-row w-full p-3 rounded-lg bg-white shadow-sm overflow-hidden overflow-x-auto ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
>
    <span class="cursor-pointer select-none" x-on:click="revealed = !revealed" title="{{ trans('game-query::query.click_to_reveal') }}">
        <span class="text-md font-medium text-gray-500 dark:text-gray-400">
            {{ $getLabel() }}
        </span>
        <span class="text-md font-semibold">
            <span x-show="!revealed" x-cloak>&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</span>
            <span x-show="revealed" x-cloak>{{ $getValue() }}</span>
        </span>
    </span>
</div>
