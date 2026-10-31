@if ($canInstall && $this->canUpload())
    @php
        // maps only: plugins come in too many shapes to place them on their own
        $accept = '.bsp,.zip,.rar,.7z';
    @endphp
    {{--
        a file dropped or picked goes straight to Wings, like Pelican's file manager - not through
        the panel, so PHP's upload limit does not apply - and then the page installs it
    --}}
    <label x-data="{
        busy: false,
        over: false,
        async send(file) {
            if (!file || this.busy) return;
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
    }"
        x-on:dragover.prevent="over = true" x-on:dragleave.prevent="over = false" x-on:drop.prevent="over = false; send($event.dataTransfer.files[0])"
        class="mod-drop" x-bind:class="over && 'mod-drop-over'">
        <x-filament::icon icon="tabler-upload" style="width:2.5rem;height:2.5rem;opacity:.5" />
        <span x-show="!busy" style="font-weight:600">{{ trans('game-manager::mods.upload.button_map') }}</span>
        <span x-show="busy" x-cloak style="display:inline-flex;align-items:center;gap:.4rem;font-weight:600"><x-filament::loading-indicator style="width:1rem;height:1rem" />{{ trans('game-manager::mods.upload.busy') }}</span>
        <span style="font-size:.8rem;opacity:.7">{{ trans('game-manager::mods.upload.drop') }} · {{ str_replace(',', ', ', $accept) }}</span>
        <input type="file" accept="{{ $accept }}" style="display:none" x-on:change="send($event.target.files[0]); $event.target.value = ''">
    </label>
@endif
