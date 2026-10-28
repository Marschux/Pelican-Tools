<div>
    @if ($may)
        @if ($editing)
            {{-- the sidebar's own entries step aside while this list stands in for them --}}
            <style>.fi-sidebar-nav-groups { display: none !important; }</style>
            <div style="display:flex;flex-direction:column;gap:.5rem;padding:0 .25rem">
                <p style="font-size:.75rem;opacity:.7;margin:0">{{ trans('server-navigation::navigation.hint') }}</p>
                <div x-sortable x-ref="list" style="display:flex;flex-direction:column;gap:.125rem">
                    @foreach ($order as $slug)
                        @if ($slug === \Marschux\ServerNavigation\Navigation\Entries::DIVIDER)
                            <div x-sortable-item="{{ $slug }}" x-sortable-handle style="cursor:grab;display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;font-size:.75rem;font-weight:600;text-transform:uppercase;opacity:.7">
                                <span style="flex:1;border-top:1px solid currentColor"></span>
                                {{ trans('server-navigation::navigation.more') }}
                                <span style="flex:1;border-top:1px solid currentColor"></span>
                            </div>
                        @else
                            <div x-sortable-item="{{ $slug }}" x-sortable-handle style="cursor:grab;display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;border-radius:.5rem;border:1px solid rgba(127,127,127,.25);font-size:.875rem">
                                <x-filament::icon icon="tabler-grip-vertical" style="width:1rem;height:1rem;opacity:.5" />
                                {{ $labels[$slug] ?? $slug }}
                            </div>
                        @endif
                    @endforeach
                </div>
                <div style="display:flex;gap:.5rem">
                    <x-filament::button size="sm" icon="tabler-check" x-on:click="$wire.save($refs.list.sortable.toArray())">{{ trans('server-navigation::navigation.done') }}</x-filament::button>
                    <x-filament::button size="sm" color="gray" wire:click="cancel">{{ trans('server-navigation::navigation.cancel') }}</x-filament::button>
                </div>
            </div>
        @else
            {{-- only the icon, below the last entry and in line with the entries' icons --}}
            <button type="button" wire:click="edit" title="{{ trans('server-navigation::navigation.edit') }}" aria-label="{{ trans('server-navigation::navigation.edit') }}" style="display:flex;padding:.5rem .75rem;margin-left:-.6rem;border-radius:.5rem;opacity:.6;cursor:pointer">
                <x-filament::icon icon="tabler-pencil" style="width:1.25rem;height:1.25rem" />
            </button>
        @endif
    @endif
</div>
