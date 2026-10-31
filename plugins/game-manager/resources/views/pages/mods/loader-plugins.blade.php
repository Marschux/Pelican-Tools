@if ($loaderPlugins)
    {{-- AMX Mod X's own plugins are part of the loader, the installed table stays for what was added --}}
    <x-filament::section collapsible>
        <x-slot name="heading">{{ trans($this->goldsrc() ? 'game-manager::mods.loader_plugins.heading' : 'game-manager::mods.loader_plugins.sourcemod_heading') }} ({{ collect($loaderPlugins)->where('enabled', true)->count() }}/{{ count($loaderPlugins) }})</x-slot>
        <x-slot name="description">
            {{ trans($this->goldsrc() ? 'game-manager::mods.loader_plugins.hint' : 'game-manager::mods.loader_plugins.sourcemod_hint') }}
            {{-- the loader removed leaves plugins.ini behind, switched on or not none of it runs --}}
            @if (!$loaderRuns)
                <span style="display:block;margin-top:.25rem;color:rgb(234,179,8)">{{ trans('game-manager::mods.loader.hint', ['loader' => $loader['name']]) }}</span>
            @endif
        </x-slot>

        @foreach (collect($loaderPlugins)->groupBy('group') as $group => $plugins)
            @if ($group !== '')
                <div style="{{ $muted }};font-weight:600;margin:{{ $loop->first ? '0' : '.75rem' }} 0 .25rem">{{ $group }}</div>
            @endif
            @foreach ($plugins as $plugin)
                <div wire:key="amxx-{{ md5($plugin['file']) }}" style="display:flex;gap:.75rem;align-items:center;padding:.3rem 0;border-top:1px solid rgba(127,127,127,.15)">
                    <span style="font-family:monospace;font-size:.875rem;min-width:11rem;{{ $plugin['enabled'] ? '' : 'opacity:.5' }}">{{ $plugin['file'] }}</span>
                    <span style="{{ $muted }};flex:1">{{ $plugin['note'] }}</span>
                    @if (!$plugin['exists'])
                        <x-filament::badge color="warning" size="sm" icon="tabler-alert-triangle">{{ trans('game-manager::mods.loader_plugins.missing') }}</x-filament::badge>
                    @endif
                    @php
                        $pluginConfig = \Marschux\GameManager\Installer::configFor(pathinfo($plugin['file'], PATHINFO_FILENAME), $configs);
                    @endphp
                    {{-- the same slots on the right as in the installed table: switch, gear, bin --}}
                    <span style="display:flex;gap:.25rem">
                    <span class="mod-slot">
                        @if ($canUpdate)
                            <button type="button" @class(['mod-action', 'mod-on' => $plugin['enabled']]) wire:click="toggleLoaderPlugin({{ $js($plugin['file']) }}, {{ $plugin['enabled'] ? 'false' : 'true' }})" title="{{ trans($plugin['enabled'] ? 'game-manager::mods.disable' : 'game-manager::mods.enable') }}" aria-label="{{ trans($plugin['enabled'] ? 'game-manager::mods.disable' : 'game-manager::mods.enable') }}" aria-pressed="{{ $plugin['enabled'] ? 'true' : 'false' }}">
                                <x-filament::icon :icon="$plugin['enabled'] ? 'tabler-toggle-right' : 'tabler-toggle-left'" />
                            </button>
                        @endif
                    </span>
                    <span class="mod-slot">
                        @if ($pluginConfig && $canReadConfig)
                            <button type="button" class="mod-action" wire:click="openConfig({{ $js($pluginConfig) }})" title="{{ $pluginConfig }}" aria-label="{{ trans('game-manager::mods.config.edit', ['file' => $pluginConfig]) }}">
                                <x-filament::icon icon="tabler-settings" />
                            </button>
                        @endif
                    </span>
                    <span class="mod-slot">
                        @if ($canDelete)
                            <button type="button" class="mod-action mod-danger" wire:click="deleteLoaderPlugin({{ $js($plugin['file']) }})" wire:confirm="{{ trans($this->goldsrc() ? 'game-manager::mods.loader_plugins.confirm_delete' : 'game-manager::mods.loader_plugins.sourcemod_confirm_delete', ['file' => $plugin['file']]) }}" title="{{ trans('game-manager::mods.remove') }}" aria-label="{{ trans('game-manager::mods.remove') }}">
                                <x-filament::icon icon="tabler-trash" />
                            </button>
                        @endif
                    </span>
                    </span>
                </div>
            @endforeach
        @endforeach
    </x-filament::section>
@endif
