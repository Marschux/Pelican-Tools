@if ($canInstall && $this->canUpload())
    {{--
        picking the file sends it straight to Wings, like Pelican's file manager - not through
        the panel, so PHP's upload limit does not apply - and then the page installs it
    --}}
    <div x-data="{
        busy: false,
        async send(file) {
            if (!file) return;
            this.busy = true;
            try {
                const target = await $wire.uploadTarget();
                const url = new URL(target.url);
                url.searchParams.append('directory', target.directory);
                const body = new FormData();
                body.append('files', file);
                const response = await fetch(url, { method: 'POST', body });
                if (!response.ok) {
                    const answer = await response.json().catch(() => ({}));
                    throw new Error(answer.error || `HTTP ${response.status}`);
                }
                await $wire.installUploaded(target.token, file.name);
            } catch (error) {
                await $wire.uploadFailed(String(error?.message ?? error));
            } finally {
                this.busy = false;
            }
        },
    }">
        <x-filament::button size="sm" color="gray" icon="tabler-upload" tag="label" style="cursor:pointer" x-bind:class="busy && 'opacity-50 pointer-events-none'">
            <span x-show="!busy">{{ trans($this->source2() ? 'mod-manager::mods.upload.button' : 'mod-manager::mods.upload.button_maps') }}</span>
            <span x-show="busy" x-cloak>{{ trans('mod-manager::mods.upload.busy') }}</span>
            <input type="file" accept="{{ $this->source2() ? '.zip,.dll' : ($this->goldsrc() ? '.amxx,.zip' : '.smx,.zip') . ',.bsp,.rar,.7z' }}" style="display:none" x-on:change="send($event.target.files[0]); $event.target.value = ''">
        </x-filament::button>
    </div>
@endif
