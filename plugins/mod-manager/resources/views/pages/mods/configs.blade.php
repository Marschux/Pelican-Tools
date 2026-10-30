@if ($configs)
    <x-filament::section>
        <x-slot name="heading">{{ trans('mod-manager::mods.config.heading') }} ({{ count($configs) }})</x-slot>
        <x-slot name="description">{{ trans('mod-manager::mods.config.hint') }}</x-slot>

        {{-- one group per folder, its rows show the file name only --}}
        @foreach (collect($configs)->groupBy(fn ($config) => dirname($config)) as $folder => $files)
            <div style="{{ $muted }};display:flex;align-items:center;gap:.4rem;font-weight:600;font-family:monospace;margin:{{ $loop->first ? '0' : '1rem' }} 0 .25rem">
                <x-filament::icon icon="tabler-folder" style="width:1rem;height:1rem" />{{ $folder === '.' ? '/' : $folder . '/' }}
            </div>
        @foreach ($files as $config)
            <div wire:key="config-{{ md5($config) }}" style="display:flex;gap:.75rem;align-items:center;padding:.4rem 0 .4rem 1.4rem;{{ $loop->first ? '' : 'border-top:1px solid rgba(127,127,127,.15)' }}">
                <span style="flex:1;font-family:monospace;font-size:.875rem">{{ basename($config) }}</span>
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
        @endforeach
    </x-filament::section>
@endif
