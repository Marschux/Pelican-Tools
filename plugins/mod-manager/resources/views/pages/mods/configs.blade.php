@if ($configs)
    <x-filament::section>
        <x-slot name="heading">{{ trans('mod-manager::mods.config.heading') }} ({{ count($configs) }})</x-slot>
        <x-slot name="description">{{ trans('mod-manager::mods.config.hint') }}</x-slot>

        @foreach ($configs as $config)
            <div wire:key="config-{{ md5($config) }}" style="display:flex;gap:.75rem;align-items:center;padding:.4rem 0;{{ $loop->first ? '' : 'border-top:1px solid rgba(127,127,127,.15)' }}">
                <span style="flex:1;font-family:monospace;font-size:.875rem">{{ $config }}</span>
                {{-- the same square buttons as the rows of the installed table, side by side --}}
                <span style="display:inline-flex;gap:.25rem">
                    @if ($canReadConfig)
                        <button type="button" class="mod-action" wire:click="openConfig({{ $js($config) }})" title="{{ trans('mod-manager::mods.config.open') }}" aria-label="{{ trans('mod-manager::mods.config.edit', ['file' => $config]) }}">
                            <x-filament::icon icon="tabler-pencil" />
                        </button>
                    @endif
                    @if ($canDelete && $this->keepsConfig($config))
                        {{-- AMX Mod X does not write these anew: deleting asks with a warning, not the usual "fresh one on the next start" --}}
                        <button type="button" class="mod-action mod-danger" wire:click="deleteConfig({{ $js($config) }})" wire:confirm="{{ trans('mod-manager::mods.config.confirm_delete_core', ['file' => $config]) }}" title="{{ trans('mod-manager::mods.config.delete') }}" aria-label="{{ trans('mod-manager::mods.config.delete') }}">
                            <x-filament::icon icon="tabler-trash" />
                        </button>
                    @elseif ($canDelete)
                        <button type="button" class="mod-action mod-danger" wire:click="deleteConfig({{ $js($config) }})" wire:confirm="{{ trans('mod-manager::mods.config.confirm_delete', ['file' => $config]) }}" title="{{ trans('mod-manager::mods.config.delete_hint') }}" aria-label="{{ trans('mod-manager::mods.config.delete') }}">
                            <x-filament::icon icon="tabler-trash" />
                        </button>
                    @endif
                </span>
            </div>
        @endforeach
    </x-filament::section>
@endif
