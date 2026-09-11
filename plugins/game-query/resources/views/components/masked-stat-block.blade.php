{{--
    Mirrors the core "small stat block" styling (app/Filament/Server/Components/SmallStatBlock)
    so this sits visually indistinguishable among the other tiles. Alpine is already loaded
    panel-wide by Filament, so no extra script is needed for the reveal toggle.
--}}
<div
    x-data="{ revealed: false }"
    class="fi-small-stat-block grid grid-flow-row w-full p-3 rounded-lg bg-white shadow-sm overflow-hidden overflow-x-auto ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
>
    <span class="text-md font-medium text-gray-500 dark:text-gray-400">
        {{ $getLabel() }}
    </span>
    <span
        class="text-md font-semibold cursor-pointer select-none"
        x-on:click="revealed = !revealed"
        title="{{ trans('game-query::query.click_to_reveal') }}"
    >
        <span x-show="!revealed" x-cloak>&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</span>
        <span x-show="revealed" x-cloak>{{ $getValue() }}</span>
    </span>
</div>
