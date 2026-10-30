<?php

namespace Marschux\ModManager\Filament\Server\Pages\Concerns;

use App\Enums\NodeJwtScope;
use App\Enums\SubuserPermission;
use App\Services\Nodes\NodeJWTService;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Marschux\ModManager\Installer;
use Marschux\ModManager\Profiles\Presets;

/** What goes in without browsing: uploaded maps and workshop links; the loader's own plugins and what was put there by hand. Part of ModsPage. */
trait AddsPlugins
{
    /**
     * Where the browser sends an upload: straight to Wings like Pelican's file manager does, into
     * a folder of its own - so it never passes through the panel and PHP's upload limit.
     *
     * @return array{url: string, directory: string, token: string}
     */
    public function uploadTarget(): array
    {
        $this->requirePermission(SubuserPermission::FileCreate);

        $server = $this->server();
        $token = bin2hex(random_bytes(16));
        $jwt = app(NodeJWTService::class)
            ->setExpiresAt(CarbonImmutable::now()->addMinutes(15))
            ->setScopes(NodeJwtScope::FileUpload)
            ->setUser(user())
            ->setClaims(['server_uuid' => $server->uuid])
            ->handle($server->node, user()->id . $server->uuid);

        return [
            'url' => $server->node->getConnectionAddress() . '/upload/file?token=' . $jwt->toString(),
            'directory' => Installer::UPLOADS . "/$token",
            'token' => $token,
        ];
    }

    /** The browser's upload is on the server: the plugin goes where it belongs. */
    public function installUploaded(string $token, string $name): void
    {
        $this->requirePermission(SubuserPermission::FileCreate);

        if (!$this->canUpload()) {
            return;
        }

        $this->attempt(fn () => $this->installer()->installUpload($token, $name), trans('mod-manager::mods.messages.installed', ['name' => $name]));
    }

    /** The upload to Wings itself failed, the browser says why. */
    public function uploadFailed(string $reason): void
    {
        Notification::make()->title(trans('mod-manager::mods.messages.failed'))->body(mb_substr($reason, 0, 200))->danger()->send();
    }

    /** The Counter-Strike games: AMX Mod X, SourceMod or CounterStrikeSharp, plugin lists and maps. */
    public function archiveLoader(): bool
    {
        return in_array($this->profile()->setting('loader_type'), Presets::ARCHIVE_LOADERS, true);
    }

    /** Maps are uploaded as a .bsp - not on Counter-Strike 2, whose maps come from the workshop. */
    public function canUpload(): bool
    {
        return $this->archiveLoader() && !$this->source2();
    }

    /** Counter-Strike 2's workshop maps, by id or link. */
    public function canLink(): bool
    {
        return $this->source2();
    }

    /** A workshop map or collection by id or link; a collection says how many of its maps came along. */
    private function addWorkshop(string $input): void
    {
        $this->attempt(
            fn () => $this->installer()->addWorkshop($input),
            fn (array $result) => $result['collection']
                ? trans('mod-manager::mods.workshop.collection_added', ['added' => $result['added'], 'skipped' => $result['skipped']])
                : trans('mod-manager::mods.workshop.added'),
        );
    }

    public function addWorkshopMap(): void
    {
        $this->requirePermission(SubuserPermission::FileCreate);

        $this->addWorkshop($this->workshopLink);
        $this->workshopLink = '';
    }

    public function hasLoginToken(): bool
    {
        return once(fn () => $this->installer()->hasLoginToken());
    }

    /** A plugin of AMX Mod X's plugins.ini on or off. */
    public function toggleLoaderPlugin(string $file, bool $enabled): void
    {
        $this->requirePermission(SubuserPermission::FileUpdate);

        $this->attempt(
            fn () => $this->installer()->setLoaderPlugin($file, $enabled),
            trans($enabled ? 'mod-manager::mods.messages.enabled' : 'mod-manager::mods.messages.disabled', ['name' => $file]),
        );
    }

    /** A plugin of AMX Mod X's plugins.ini deleted, file and line. */
    public function deleteLoaderPlugin(string $file): void
    {
        $this->requirePermission(SubuserPermission::FileDelete);

        $this->attempt(fn () => $this->installer()->deleteLoaderPlugin($file), trans('mod-manager::mods.messages.removed', ['name' => $file]));
    }

    /** @return array<int, array{file: string, enabled: bool, note: string, group: string, exists: bool}> */
    public function loaderPlugins(): array
    {
        return $this->installer()->loaderPlugins();
    }

    public function adopt(string $path): void
    {
        $this->requirePermission(SubuserPermission::FileUpdate);

        $this->attempt(fn () => $this->installer()->adopt($path), trans('mod-manager::mods.messages.adopted', ['name' => basename($path)]), false);
    }

    public function removeUnmanaged(string $path): void
    {
        $this->requirePermission(SubuserPermission::FileDelete);

        $this->attempt(fn () => $this->installer()->removeUnmanaged($path), trans('mod-manager::mods.messages.removed', ['name' => basename($path)]));
    }

    /** @return array<int, array<string, mixed>> */
    public function unmanaged(): array
    {
        return $this->installer()->unmanaged();
    }
}
