{{--
    Plain blade components and inline styles: Filament page actions did not render in this panel,
    and only the utility classes Filament itself uses exist in its stylesheet.
--}}
@php
    // browsing only needs which versions are installed - the rest asks Wings and stays on its tab
    $onInstalled = $tab === 'installed';
    $loader = $onInstalled ? $this->loader() : null;
    $installed = $onInstalled ? $this->installed() : collect();
    $versions = $this->installedVersions();
    $updates = $onInstalled ? $this->updates() : [];
    $unmanaged = $tab === 'installed' ? $this->unmanaged() : [];
    $configs = $tab === 'installed' ? $this->configs() : [];
    $relations = $tab === 'installed' ? $this->relations() : [];
    $loaderInstall = $tab === 'installed' ? $this->loaderInstall() : null;
    // without a running loader no mod loads, switched on or not
    $loaderRuns = !$loader || ($loader['installed'] && $loader['started']);
    $card = 'display:flex;flex-direction:column;gap:.75rem;padding:1rem;border:1px solid rgba(127,127,127,.2);border-radius:.75rem';
    $icon = 'flex:none;border-radius:.5rem;object-fit:cover;background:rgba(127,127,127,.15)';
    $muted = 'font-size:.8rem;opacity:.7';
    $js = fn (string $value) => \Illuminate\Support\Js::from($value);
    // buttons a subuser may not press are left out, the actions check again
    $permission = \App\Enums\SubuserPermission::class;
    $canInstall = $this->may($permission::FileCreate);
    $canUpdate = $this->may($permission::FileUpdate);
    $canDelete = $this->may($permission::FileDelete);
    $canLoader = $this->may($permission::FileCreate, $permission::StartupUpdate);
    $canRemoveLoader = $this->may($permission::FileDelete, $permission::StartupUpdate);
    $canReadConfig = $this->may($permission::FileReadContent);
    $canRestart = $this->may($permission::ControlRestart);
@endphp

<x-filament-panels::page>
    {{-- "installed" next to buttons: as tall as they are, text in the middle - filament's badge keeps its own height --}}
    <style>
        .mod-installed { align-self: stretch; display: inline-flex; align-items: center; gap: .35rem; padding: 0 .75rem; border-radius: .5rem; font-size: .875rem; font-weight: 500; color: rgb(21,128,61); background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.35); }
        .dark .mod-installed { color: rgb(74,222,128); }
    </style>
    @if ($this->needsRestart())
        <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;padding:.75rem 1rem;border-radius:.75rem;border:1px solid rgba(234,179,8,.5);background:rgba(234,179,8,.08)">
            <x-filament::icon icon="tabler-refresh-alert" style="width:1.25rem;height:1.25rem;color:rgb(234,179,8)" />
            <span style="flex:1">{{ trans('mod-manager::mods.restart.hint') }}</span>
            @if ($canRestart)
                <x-filament::button size="sm" color="warning" icon="tabler-refresh" wire:click="restart">{{ trans('mod-manager::mods.restart.button') }}</x-filament::button>
            @endif
            <x-filament::icon-button color="gray" icon="tabler-x" wire:click="dismissRestart" :label="trans('mod-manager::mods.restart.dismiss')" />
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
        @if ($installed->isNotEmpty() || $loaderInstall || $loader)
            @php
                $updatable = $installed->filter(fn ($install) => isset($updates[$install->package]) && !$install->pinned)->count();
            @endphp
            <x-filament::section>
                <x-slot name="afterHeader">
                    <div style="display:flex;gap:.5rem">
                        <x-filament::button size="sm" color="gray" icon="tabler-users" x-on:click="$dispatch('open-modal', { id: 'mod-players' })">{{ trans('mod-manager::mods.players.button') }}</x-filament::button>
                        @if ($updatable && $canUpdate)
                            <x-filament::button size="sm" icon="tabler-refresh" wire:click="updateAll">{{ trans('mod-manager::mods.update_all', ['count' => $updatable]) }}</x-filament::button>
                        @endif
                    </div>
                </x-slot>
                <x-slot name="heading">{{ trans('mod-manager::mods.tabs.installed') }} ({{ $installed->count() }})</x-slot>

                {{-- a table, scrolling sideways when the page gets narrow --}}
                {{-- icon buttons have a negative margin, so the last column keeps some room on the right, else the edge alone makes it scroll --}}
                <div style="overflow-x:auto;overflow-y:hidden">
                    <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                        <thead>
                            <tr style="text-align:left;{{ $muted }}">
                                <th style="padding:.4rem .5rem .4rem 0;font-weight:500">{{ trans('mod-manager::mods.columns.mod') }}</th>
                                <th style="padding:.4rem .5rem;font-weight:500">{{ trans('mod-manager::mods.columns.version') }}</th>
                                <th style="padding:.4rem .5rem;font-weight:500;text-align:right">{{ trans('mod-manager::mods.columns.size') }}</th>
                                <th style="padding:.4rem .5rem .4rem 2rem;font-weight:500">{{ trans('mod-manager::mods.columns.dependencies') }}</th>
                                <th style="padding:.4rem .5rem;font-weight:500">{{ trans('mod-manager::mods.columns.status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @if ($loader && !$loaderInstall)
                                {{-- the loader before it was installed through this page: what is wrong and the button to fix it --}}
                                <tr wire:key="loader-row" style="border-top:1px solid rgba(127,127,127,.15);vertical-align:middle">
                                    <td style="padding:.6rem .5rem .6rem 0">
                                        <div style="display:flex;gap:.75rem;align-items:center;min-width:12rem">
                                            <div style="{{ $icon }};width:2.25rem;height:2.25rem;display:flex;align-items:center;justify-content:center">
                                                <x-filament::icon icon="tabler-plug" style="width:1.25rem;height:1.25rem;opacity:.6" />
                                            </div>
                                            <div style="min-width:0">
                                                <div style="font-weight:600">{{ $loader['name'] }}</div>
                                                <div style="{{ $muted }}">{{ trans('mod-manager::mods.loader.hint', ['loader' => $loader['name']]) }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="padding:.6rem .5rem">–</td>
                                    <td style="padding:.6rem .5rem;text-align:right">–</td>
                                    <td style="padding:.6rem .5rem .6rem 2rem;{{ $muted }}">–</td>
                                    <td style="padding:.6rem .5rem">
                                        <div style="display:flex;gap:.25rem;flex-wrap:wrap">
                                                @if ($loader['installed'])
                                                    <x-filament::badge color="warning" size="sm" icon="tabler-alert-triangle">{{ trans('mod-manager::mods.loader.not_started') }}</x-filament::badge>
                                                @else
                                                    <x-filament::badge color="danger" size="sm" icon="tabler-x">{{ trans('mod-manager::mods.loader.missing') }}</x-filament::badge>
                                                @endif
                                            <x-filament::badge color="primary" size="sm" icon="tabler-plug">{{ trans('mod-manager::mods.loader_dependency') }}</x-filament::badge>
                                        </div>
                                    </td>
                                    <td style="padding:.6rem .5rem">
                                        <div style="display:flex;gap:.25rem;align-items:center;justify-content:flex-end">
                                                @if ($canLoader)
                                                    <x-filament::button size="xs" :icon="$loader['installed'] ? 'tabler-tool' : 'tabler-download'" wire:click="installLoader" wire:loading.attr="disabled" style="white-space:nowrap">
                                                        {{ trans($loader['installed'] ? 'mod-manager::mods.loader.repair' : 'mod-manager::mods.install') }}
                                                    </x-filament::button>
                                                @endif
                                        </div>
                                    </td>
                                </tr>
                            @endif
                            @foreach (collect([$loaderInstall])->filter()->concat($installed) as $install)
                                @php
                                    // the loader: no switch and no remove, without it no mod runs
                                    $isLoader = $install->is($loaderInstall);
                                    $relation = $relations[$install->package] ?? ['requires' => [], 'missing' => [], 'needed_by' => []];
                                    $config = \Marschux\ModManager\Installer::configFor($install->name, $configs);
                                @endphp
                                <tr wire:key="install-{{ $install->id }}" style="border-top:1px solid rgba(127,127,127,.15);vertical-align:middle">
                                    <td style="padding:.6rem .5rem .6rem 0">
                                        <div style="display:flex;gap:.75rem;align-items:center;min-width:12rem;{{ $install->enabled ? '' : 'opacity:.5' }}">
                                            @if ($install->icon)
                                                <img src="{{ $install->icon }}" alt="" loading="lazy" style="{{ $icon }};width:2.25rem;height:2.25rem">
                                            @else
                                                <div style="{{ $icon }};width:2.25rem;height:2.25rem"></div>
                                            @endif
                                            <div style="min-width:0">
                                                <div style="font-weight:600;cursor:pointer" x-on:click="$dispatch('open-modal', { id: 'mod-details' }); $wire.showDetails({{ $js($install->package) }})">{{ $install->name }}</div>
                                                <div style="{{ $muted }}">{{ trans('mod-manager::mods.by', ['author' => strtok($install->package, '-')]) }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="padding:.6rem .5rem;white-space:nowrap">
                                        {{ $install->version }}
                                        @if (isset($updates[$install->package]))
                                            <div style="color:rgb(59,130,246);font-size:.75rem">→ {{ $updates[$install->package] }}</div>
                                        @endif
                                    </td>
                                    <td style="padding:.6rem .5rem;white-space:nowrap;text-align:right">
                                        {{ $install->size !== null ? \Illuminate\Support\Number::fileSize($install->size, 1) : '–' }}
                                    </td>
                                    <td style="padding:.6rem .5rem .6rem 2rem;{{ $muted }};min-width:10rem">
                                        @if ($relation['requires'])
                                            <div>{{ trans('mod-manager::mods.requires', ['names' => implode(', ', $relation['requires'])]) }}</div>
                                        @endif
                                        @if ($relation['needed_by'])
                                            <div>{{ trans('mod-manager::mods.needed_by', ['names' => implode(', ', $relation['needed_by'])]) }}</div>
                                        @endif
                                        @if (!$relation['requires'] && !$relation['needed_by'])
                                            –
                                        @endif
                                    </td>
                                    <td style="padding:.6rem .5rem">
                                        <div style="display:flex;gap:.25rem;flex-wrap:wrap">
                                            @if (isset($updates[$install->package]) && !$install->pinned)
                                                <x-filament::badge color="info" size="sm" icon="tabler-arrow-up">{{ trans('mod-manager::mods.update') }}</x-filament::badge>
                                            @endif
                                            @if ($install->pinned)
                                                <x-filament::badge color="gray" size="sm" icon="tabler-pin">{{ trans('mod-manager::mods.pinned') }}</x-filament::badge>
                                            @endif
                                            @if (!$install->enabled)
                                                <x-filament::badge color="gray" size="sm" icon="tabler-power">{{ trans('mod-manager::mods.off') }}</x-filament::badge>
                                            @elseif ($isLoader && !$loaderRuns)
                                                @if ($loader['installed'])
                                                    <x-filament::badge color="warning" size="sm" icon="tabler-alert-triangle">{{ trans('mod-manager::mods.loader.not_started') }}</x-filament::badge>
                                                @else
                                                    <x-filament::badge color="danger" size="sm" icon="tabler-x">{{ trans('mod-manager::mods.loader.missing') }}</x-filament::badge>
                                                @endif
                                            @elseif (!$isLoader && !$loaderRuns)
                                                <x-filament::badge color="warning" size="sm" icon="tabler-alert-triangle" :tooltip="trans('mod-manager::mods.loader.hint', ['loader' => $loader['name']])">{{ trans('mod-manager::mods.not_loaded') }}</x-filament::badge>
                                            @elseif ($relation['missing'])
                                                <x-filament::badge color="warning" size="sm" icon="tabler-alert-triangle">{{ trans('mod-manager::mods.missing', ['names' => implode(', ', $relation['missing'])]) }}</x-filament::badge>
                                            @elseif (!isset($updates[$install->package]))
                                                <x-filament::badge color="success" size="sm" icon="tabler-check">{{ trans('mod-manager::mods.active') }}</x-filament::badge>
                                            @endif
                                            @if ($isLoader)
                                                <x-filament::badge color="primary" size="sm" icon="tabler-plug">{{ trans('mod-manager::mods.loader_dependency') }}</x-filament::badge>
                                            @endif
                                        </div>
                                    </td>
                                    <td style="padding:.6rem .5rem">
                                        <div style="display:flex;gap:.25rem;align-items:center;justify-content:flex-end">
                                            @if ($isLoader && !$loaderRuns)
                                                @if ($canLoader)
                                                    <x-filament::button size="xs" :icon="$loader['installed'] ? 'tabler-tool' : 'tabler-download'" wire:click="installLoader" wire:loading.attr="disabled" style="white-space:nowrap">
                                                        {{ trans($loader['installed'] ? 'mod-manager::mods.loader.repair' : 'mod-manager::mods.install') }}
                                                    </x-filament::button>
                                                @endif
                                            @elseif (isset($updates[$install->package]) && ($isLoader ? $canLoader : $canUpdate))
                                                {{-- shows the jump: from what is there to what is out --}}
                                                @if ($isLoader)
                                                    <x-filament::button size="xs" icon="tabler-arrow-up" wire:click="installLoader" :tooltip="trans('mod-manager::mods.update_from_to', ['from' => $install->version, 'to' => $updates[$install->package]])" style="white-space:nowrap">
                                                        {{ $updates[$install->package] }}
                                                    </x-filament::button>
                                                @else
                                                    <x-filament::button size="xs" :color="$install->pinned ? 'gray' : 'primary'" icon="tabler-arrow-up" wire:click="updateMod({{ $install->id }})" :tooltip="trans('mod-manager::mods.update_from_to', ['from' => $install->version, 'to' => $updates[$install->package]])" style="white-space:nowrap">
                                                        {{ $updates[$install->package] }}
                                                    </x-filament::button>
                                                @endif
                                            @endif
                                            @if ($config && $canReadConfig)
                                                {{-- the text colour, so white on dark and dark on light --}}
                                                <x-filament::icon-button icon="tabler-settings" size="lg" style="color:inherit" wire:click="openConfig({{ $js($config) }})" :label="trans('mod-manager::mods.config.edit', ['file' => basename($config)])" :tooltip="basename($config)" />
                                            @endif
                                            @unless ($isLoader)
                                                @if ($canUpdate)
                                            <x-filament::icon-button :color="$install->enabled ? 'success' : 'gray'" size="xl" :icon="$install->enabled ? 'tabler-toggle-right-filled' : 'tabler-toggle-left'" wire:click="toggle({{ $install->id }})" :label="trans($install->enabled ? 'mod-manager::mods.disable' : 'mod-manager::mods.enable')" :tooltip="trans($install->enabled ? 'mod-manager::mods.disable' : 'mod-manager::mods.enable')" />
                                                @endif
                                                @if ($canDelete)
                                            <x-filament::icon-button color="danger" icon="tabler-trash" size="lg" wire:click="remove({{ $install->id }})" :label="trans('mod-manager::mods.remove')" :tooltip="trans('mod-manager::mods.remove')" />
                                                @endif
                                            @elseif ($canRemoveLoader)
                                                <x-filament::icon-button color="danger" icon="tabler-trash" size="lg" wire:click="removeLoader" :label="trans('mod-manager::mods.remove')" :tooltip="trans('mod-manager::mods.remove')" />
                                            @endunless
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        @if ($installed->isEmpty() && !$unmanaged)
            <x-filament::section>
                <div style="display:flex;flex-direction:column;align-items:center;gap:.75rem;padding:2rem 0;text-align:center">
                    <x-filament::icon icon="tabler-package-off" style="width:2.5rem;height:2.5rem;opacity:.5" />
                    <span style="opacity:.7">{{ trans('mod-manager::mods.nothing_installed') }}</span>
                    <x-filament::button color="gray" icon="tabler-search" wire:click="setTab('browse')">{{ trans('mod-manager::mods.tabs.browse') }}</x-filament::button>
                </div>
            </x-filament::section>
        @endif

        @if ($unmanaged)
            <x-filament::section>
                <x-slot name="heading">{{ trans('mod-manager::mods.unmanaged.heading') }}</x-slot>
                <x-slot name="description">{{ trans('mod-manager::mods.unmanaged.hint') }}</x-slot>

                @foreach ($unmanaged as $entry)
                    <div wire:key="unmanaged-{{ md5($entry['path']) }}" style="display:flex;gap:.75rem;align-items:center;padding:.5rem 0;{{ $loop->first ? '' : 'border-top:1px solid rgba(127,127,127,.15)' }}">
                        <div style="flex:1;min-width:0">
                            <div style="font-weight:600">{{ $entry['name'] }} <span style="{{ $muted }};font-weight:400">{{ $entry['version'] }}</span></div>
                            <div style="{{ $muted }};font-family:monospace">{{ $entry['path'] }}</div>
                        </div>
                        @if ($entry['package'] && $canUpdate)
                            <x-filament::button size="sm" color="gray" icon="tabler-download" wire:click="adopt({{ $js($entry['path']) }})">{{ trans('mod-manager::mods.unmanaged.adopt') }}</x-filament::button>
                        @endif
                        @if ($canDelete)
                        <x-filament::icon-button color="danger" icon="tabler-trash" wire:click="removeUnmanaged({{ $js($entry['path']) }})" wire:confirm="{{ trans('mod-manager::mods.unmanaged.confirm_delete', ['path' => $entry['path']]) }}" :label="trans('mod-manager::mods.remove')" />
                        @endif
                    </div>
                @endforeach
            </x-filament::section>
        @endif
        @if ($configs)
            <x-filament::section collapsible collapsed>
                <x-slot name="heading">{{ trans('mod-manager::mods.config.heading') }} ({{ count($configs) }})</x-slot>
                <x-slot name="description">{{ trans('mod-manager::mods.config.hint') }}</x-slot>

                @foreach ($configs as $config)
                    <div wire:key="config-{{ md5($config) }}" style="display:flex;gap:.75rem;align-items:center;padding:.4rem 0;{{ $loop->first ? '' : 'border-top:1px solid rgba(127,127,127,.15)' }}">
                        <span style="flex:1;font-family:monospace;font-size:.875rem">{{ $config }}</span>
                        @if ($canReadConfig)
                            <x-filament::button size="sm" color="gray" icon="tabler-pencil" wire:click="openConfig({{ $js($config) }})">{{ trans('mod-manager::mods.config.open') }}</x-filament::button>
                        @endif
                        @if ($canDelete)
                            <x-filament::button size="sm" color="danger" outlined icon="tabler-trash" label-sr-only wire:click="deleteConfig({{ $js($config) }})" wire:confirm="{{ trans('mod-manager::mods.config.confirm_delete', ['file' => basename($config)]) }}" :tooltip="trans('mod-manager::mods.config.delete_hint')">{{ trans('mod-manager::mods.config.delete') }}</x-filament::button>
                        @endif
                    </div>
                @endforeach
            </x-filament::section>
        @endif
    @else
        {{-- every change searches right away, typing waits for a short pause --}}
        <form wire:submit="search" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
            <x-filament::input.wrapper prefix-icon="tabler-search" style="flex:1 1 16rem">
                <x-filament::input type="search" wire:model.live.debounce.500ms="query" :placeholder="trans('mod-manager::mods.search_placeholder')" />
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
                        <option value="{{ $order }}">{{ trans("mod-manager::mods.orderings.$order") }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
            <label style="display:flex;gap:.5rem;align-items:center;font-size:.875rem;cursor:pointer">
                <x-filament::input.checkbox wire:model.live="showClientSide" />
                {{ trans('mod-manager::mods.show_client_side') }}
            </label>
            <label style="display:flex;gap:.5rem;align-items:center;font-size:.875rem;cursor:pointer">
                <x-filament::input.checkbox wire:model.live="showDeprecated" />
                {{ trans('mod-manager::mods.show_deprecated') }}
            </label>
        </form>

        {{-- while the next page loads the tiles stay and only fade, so nothing jumps --}}
        <style>.mod-loading { opacity: .4; pointer-events: none; transition: opacity .15s; }</style>

        {{-- three tiles side by side, fewer when the page gets narrow; every row as tall as the tallest, so all tiles are the same size --}}
        <div wire:loading.class="mod-loading" wire:target="search,goToPage,query,category,ordering,showClientSide,showDeprecated" style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(max(16rem,calc((100% - 2rem) / 3)),1fr));grid-auto-rows:1fr">
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
                        <x-filament::button size="sm" color="gray" icon="tabler-info-circle" x-on:click="$dispatch('open-modal', { id: 'mod-details' }); $wire.showDetails({{ $js($item['id']) }})">{{ trans('mod-manager::mods.details') }}</x-filament::button>
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
            <div style="display:flex;justify-content:center;align-items:center;gap:.25rem;flex-wrap:wrap" wire:loading.class="mod-loading" wire:target="search,goToPage,query,category,ordering,showClientSide,showDeprecated">
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
        <div wire:loading.flex wire:target="showDetails" style="justify-content:center;padding:3rem">
            <x-filament::loading-indicator style="width:2rem;height:2rem" />
        </div>
        {{-- the details of the mod before stay hidden while the next ones load --}}
        <div wire:loading.remove wire:target="showDetails" style="display:contents">
        @if ($details)
            <x-slot name="heading">
                <div wire:loading.remove wire:target="showDetails" style="display:flex;gap:1rem;align-items:center">
                    @if ($details['icon'])
                        <img src="{{ $details['icon'] }}" alt="" style="{{ $icon }};width:4rem;height:4rem">
                    @endif
                    <div>
                        <div>{{ $details['name'] }} <span style="{{ $muted }}">{{ $details['version'] }}</span></div>
                        <div style="{{ $muted }};font-weight:400">
                            {{ trans('mod-manager::mods.by', ['author' => $details['author']]) }} ·
                            ⬇ {{ number_format($details['downloads']) }} · ★ {{ $details['rating'] }} ·
                            {{ \Illuminate\Support\Number::fileSize($details['size']) }} ·
                            {{ trans_choice('mod-manager::mods.used_by', $details['dependants'], ['count' => number_format($details['dependants'])]) }}
                        </div>
                    </div>
                </div>
            </x-slot>

            <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
                @if (isset($versions[$details['id']]))
                    <span class="mod-installed"><x-filament::icon icon="tabler-check" style="width:1rem;height:1rem" />{{ trans('mod-manager::mods.is_installed') }}</span>
                @elseif ($canInstall)
                    <x-filament::button icon="tabler-download" wire:click="install({{ $js($details['id']) }})">{{ trans('mod-manager::mods.install') }}</x-filament::button>
                @endif
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
                    {{-- one select instead of a long list; the button knows which version is picked --}}
                    @php
                        $current = $versions[$details['id']] ?? null;
                    @endphp
                    <div x-data="{ picked: {{ \Illuminate\Support\Js::from($details['versions'][0]['number'] ?? '') }}, current: {{ \Illuminate\Support\Js::from($current) }} }" wire:key="versions-{{ md5($details['id']) }}" style="display:flex;gap:.5rem;align-items:center">
                        <x-filament::input.wrapper style="flex:1">
                            <x-filament::input.select x-model="picked">
                                @foreach ($details['versions'] as $version)
                                    <option value="{{ $version['number'] }}">
                                        {{ $version['number'] }} · {{ \Illuminate\Support\Carbon::parse($version['date'])->isoFormat('L') }} · ⬇ {{ \Illuminate\Support\Number::abbreviate($version['downloads']) }}{{ $version['number'] === $current ? ' · ' . trans('mod-manager::mods.current') : '' }}
                                    </option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        <x-filament::button icon="tabler-download" x-show="{{ $canInstall ? 'picked !== current' : 'false' }}" x-on:click="$wire.install({{ \Illuminate\Support\Js::from($details['id']) }}, picked)">{{ trans('mod-manager::mods.install') }}</x-filament::button>
                        <x-filament::badge color="success" icon="tabler-check" x-show="picked === current" x-cloak>{{ trans('mod-manager::mods.current') }}</x-filament::badge>
                    </div>
                </div>
            </div>

            <style>.mod-readme img { max-width: 100%; height: auto; }</style>
            <div x-data="{ show: 'readme' }" wire:key="details-{{ md5($details['id']) }}" style="border-top:1px solid rgba(127,127,127,.2);padding-top:1rem">
                @if ($details['changelog'])
                    <x-filament::tabs style="margin-bottom:1rem">
                        <x-filament::tabs.item alpine-active="show === 'readme'" x-on:click="show = 'readme'">{{ trans('mod-manager::mods.readme') }}</x-filament::tabs.item>
                        <x-filament::tabs.item alpine-active="show === 'changelog'" x-on:click="show = 'changelog'">{{ trans('mod-manager::mods.changelog') }}</x-filament::tabs.item>
                    </x-filament::tabs>
                @endif
                <div class="fi-prose mod-readme" style="max-width:none" x-show="show === 'readme'">{!! $details['readme'] !!}</div>
                @if ($details['changelog'])
                    <div class="fi-prose mod-readme" style="max-width:none" x-show="show === 'changelog'" x-cloak>{!! $details['changelog'] !!}</div>
                @endif
            </div>
        @endif
        </div>
    </x-filament::modal>
    <x-filament::modal id="mod-config" width="5xl" :close-by-clicking-away="false">
        <x-slot name="heading">{{ $configPath ? basename($configPath) : '' }}</x-slot>
        <x-slot name="description">{{ trans('mod-manager::mods.config.restart_note') }}</x-slot>

        <textarea wire:model="configText" @readonly(!$canUpdate) spellcheck="false" rows="24" style="width:100%;font-family:monospace;font-size:.8rem;line-height:1.4;padding:.75rem;border-radius:.5rem;border:1px solid rgba(127,127,127,.3);background:rgba(127,127,127,.06);color:inherit;resize:vertical"></textarea>

        <x-slot name="footerActions">
            @if ($canUpdate)
                <x-filament::button icon="tabler-device-floppy" wire:click="saveConfig">{{ trans('mod-manager::mods.config.save') }}</x-filament::button>
            @endif
            <x-filament::button color="gray" x-on:click="close">{{ trans('mod-manager::mods.cancel') }}</x-filament::button>
        </x-slot>
    </x-filament::modal>

    {{-- for players: the same mods on their side, to paste into chat or a server description --}}
    <x-filament::modal id="mod-players" width="3xl">
        <x-slot name="heading">{{ trans('mod-manager::mods.players.heading') }}</x-slot>
        <x-slot name="description">{{ trans('mod-manager::mods.players.hint') }}</x-slot>

        @php
            $playerList = $tab === 'installed' ? $this->playerList() : '';
        @endphp
        <div x-data="{ copied: false }">
            <textarea readonly x-ref="list" rows="12" style="width:100%;font-family:monospace;font-size:.8rem;padding:.75rem;border-radius:.5rem;border:1px solid rgba(127,127,127,.3);background:rgba(127,127,127,.06);color:inherit">{{ $playerList }}</textarea>
            <div style="display:flex;gap:.5rem;align-items:center;margin-top:.5rem">
                <x-filament::button icon="tabler-copy" x-on:click="navigator.clipboard.writeText($refs.list.value); copied = true; setTimeout(() => copied = false, 2000)">{{ trans('mod-manager::mods.players.copy') }}</x-filament::button>
                <span x-show="copied" x-cloak style="color:rgb(34,197,94);font-size:.875rem">{{ trans('mod-manager::mods.players.copied') }}</span>
            </div>
        </div>
    </x-filament::modal>

    {{-- asks before installing dependencies along and before removing --}}
    <x-filament::modal id="mod-confirm" width="lg" :close-by-clicking-away="false">
        @if ($confirm)
            <x-slot name="heading">
                {{ trans($confirm['type'] === 'install' ? 'mod-manager::mods.confirm.install_heading' : 'mod-manager::mods.confirm.remove_heading', ['name' => $confirm['name']]) }}
            </x-slot>

            @if ($confirm['type'] === 'remove_loader')
                <div style="font-size:.875rem">{{ trans_choice('mod-manager::mods.confirm.loader_body', $confirm['mods'], ['name' => $confirm['name']]) }}</div>
            @endif

            @if ($confirm['packages'])
                <div style="{{ $muted }};font-size:.875rem">
                    {{ trans('mod-manager::mods.confirm.install_body', ['name' => $confirm['name']]) }}
                </div>
                <div>
                    @foreach ($confirm['packages'] as $package)
                        <div style="display:flex;gap:.5rem;align-items:center;padding:.25rem 0">
                            @if ($package['icon'] ?? null)
                                <img src="{{ $package['icon'] }}" alt="" style="{{ $icon }};width:1.75rem;height:1.75rem">
                            @endif
                            <span>{{ $package['name'] }} <span style="{{ $muted }}">{{ $package['version'] }}</span></span>
                        </div>
                    @endforeach
                </div>
            @endif

            @if (($confirm['type'] === 'remove') && ($confirm['needed_by'] ?? []))
                <div style="font-size:.875rem;color:rgb(234,179,8)">
                    {{ trans_choice('mod-manager::mods.confirm.needed_by', count($confirm['needed_by']), ['names' => implode(', ', $confirm['needed_by'])]) }}
                </div>
            @endif

            @if (($confirm['type'] === 'remove') && ($confirm['configs'] ?? []))
                <div>
                    <label style="display:flex;gap:.5rem;align-items:center;font-size:.875rem;cursor:pointer">
                        <x-filament::input.checkbox wire:model="removeConfigs" />
                        {{ trans('mod-manager::mods.confirm.remove_configs') }}
                    </label>
                    <div style="{{ $muted }};font-family:monospace;margin:.35rem 0 0 1.5rem;max-height:8rem;overflow-y:auto">
                        @foreach ($confirm['configs'] as $path)
                            <div>{{ $path }}</div>
                        @endforeach
                    </div>
                </div>
            @endif

            <x-slot name="footerActions">
                @if ($confirm['type'] === 'install')
                    <x-filament::button icon="tabler-download" wire:click="confirmInstall">{{ trans('mod-manager::mods.confirm.install_all') }}</x-filament::button>
                @elseif ($confirm['type'] === 'remove_loader')
                    <x-filament::button color="danger" icon="tabler-trash" wire:click="confirmRemoveLoader">{{ trans('mod-manager::mods.remove') }}</x-filament::button>
                @else
                    <x-filament::button color="danger" icon="tabler-trash" wire:click="confirmRemove">{{ trans('mod-manager::mods.remove') }}</x-filament::button>
                @endif
                <x-filament::button color="gray" wire:click="closeConfirm">{{ trans('mod-manager::mods.cancel') }}</x-filament::button>
            </x-slot>
        @endif
    </x-filament::modal>
</x-filament-panels::page>
