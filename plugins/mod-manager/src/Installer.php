<?php

namespace Marschux\ModManager;

use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Marschux\ModManager\Models\ModInstall;
use Marschux\ModManager\Models\ModProfile;
use Marschux\ModManager\Sources\Thunderstore;
use RuntimeException;

/**
 * Puts mods on a server and takes them off again, the way the server's mod profile says.
 *
 * Every Thunderstore package is unpacked whole into a folder of its own below the mods folder,
 * like r2modman does it - BepInEx loads plugins from any depth there.
 */
class Installer
{
    private ServerFiles $files;

    private Thunderstore $source;

    public function __construct(private Server $server, private ModProfile $profile)
    {
        $this->files = new ServerFiles($server);
        $this->source = new Thunderstore((string) $profile->setting('community'), $this->hidden());
    }

    /**
     * What installing $id means: its dependencies that are not there yet, then $id itself.
     * Packages the profile hides (the loader) are left out.
     *
     * @return array<int, array<string, mixed>> from Thunderstore::latest(), plus "dependency"
     */
    public function plan(string $id): array
    {
        $installed = $this->installs()->pluck('package')->all();
        $plan = [];

        $collect = function (string $package, bool $root) use (&$collect, &$plan, $installed) {
            if (array_key_exists($package, $plan) || in_array($package, $this->hidden(), true) || (!$root && in_array($package, $installed, true))) {
                return;
            }

            // taken before the dependencies, so a dependency cycle stops here
            $plan[$package] = null;
            $latest = $this->source->latest($package);

            foreach ($latest['requires'] as $required) {
                $collect($required, false);
            }

            // dependencies first, so nothing is installed before what it needs
            unset($plan[$package]);
            $plan[$package] = $latest + ['dependency' => !$root];
        };

        $collect($id, true);

        return array_values($plan);
    }

    /** @param  array<int, array<string, mixed>>  $plan  from plan() */
    public function install(array $plan): void
    {
        foreach ($plan as $package) {
            $this->installPackage($package);
        }
    }

    /**
     * Dependencies nothing needs any more once $install is gone - also the ones only those
     * needed.
     *
     * @return Collection<int, ModInstall>
     */
    public function orphans(ModInstall $install): Collection
    {
        $remaining = $this->installs()->reject(fn (ModInstall $other) => $other->id === $install->id);
        $orphans = new Collection();

        do {
            $required = $remaining->pluck('requires')->flatten()->unique()->all();
            $found = $remaining->filter(fn (ModInstall $other) => $other->dependency && !in_array($other->package, $required, true));

            $orphans = $orphans->merge($found);
            $remaining = $remaining->diff($found);
        } while ($found->isNotEmpty());

        return $orphans->values();
    }

    /** @return string[] the names removed */
    public function uninstall(ModInstall $install, bool $withOrphans): array
    {
        $removing = $withOrphans ? $this->orphans($install)->prepend($install) : new Collection([$install]);

        $this->files->delete($removing->pluck('files')->flatten()->all());
        ModInstall::query()->whereIn('id', $removing->pluck('id'))->delete();

        return $removing->pluck('name')->all();
    }

    /**
     * The mod loader: its pack folder's content goes to the server root, then this server's
     * start command gets the profile's prefix. Configs the loader already has are kept.
     */
    public function installLoader(): ModInstall
    {
        $package = $this->source->latest((string) $this->profile->setting('loader_package'));
        $scratch = '.mod-manager/loader';

        $this->files->delete($this->files->exists($scratch) ? [$scratch] : []);

        try {
            $this->files->pull($package['download'], $scratch, 'loader.zip');
            $this->files->decompress($scratch, 'loader.zip');

            // a loader pack is one folder (e.g. BepInExPack_Valheim) next to manifest and readme
            $folder = collect($this->files->list($scratch))->filter()->keys()->first()
                ?? throw new RuntimeException(trans('mod-manager::mods.messages.loader_unknown'));

            $configDir = trim((string) $this->profile->setting('config_dir'), '/');
            $moves = [];
            $replace = [];
            $listings = [];

            foreach ($this->files->walk("$scratch/$folder") as $path) {
                $directory = dirname($path) === '.' ? '' : dirname($path);
                $listings[$directory] ??= $this->files->list($directory);

                if (isset($listings[$directory][basename($path)])) {
                    if ($configDir !== '' && str_starts_with($path, "$configDir/")) {
                        continue;
                    }

                    $replace[] = $path;
                }

                $moves[] = ['from' => "$scratch/$folder/$path", 'to' => $path];
            }

            // Wings refuses to move onto an existing file, so an update removes the old one first
            $this->files->delete($replace);
            $this->files->move($moves);
        } finally {
            $this->files->delete([$scratch]);
        }

        $this->applyStartPrefix();

        return ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => $package['id']],
            ['name' => (string) $this->profile->setting('loader_name', $package['name']), 'version' => $package['version'], 'icon' => $package['icon'], 'dependency' => false, 'requires' => [], 'files' => array_column($moves, 'to')],
        );
    }

    /** Only this server's start command changes, the egg stays as it is. */
    public function applyStartPrefix(): void
    {
        $prefix = trim((string) $this->profile->setting('start_prefix'));

        if ($prefix !== '' && !str_contains((string) $this->server->startup, $prefix)) {
            $this->server->update(['startup' => "$prefix " . $this->server->startup]);
        }
    }

    /** @return Collection<int, ModInstall> */
    public function installs(): Collection
    {
        return ModInstall::query()->where('server_id', $this->server->id)->orderBy('name')->get();
    }

    /** @param  array<string, mixed>  $package */
    private function installPackage(array $package): ModInstall
    {
        $directory = trim((string) $this->profile->setting('mods_dir'), '/') . '/' . $package['id'];

        // a reinstall or update starts from an empty folder
        $this->files->delete($this->files->exists($directory) ? [$directory] : []);
        $this->files->pull($package['download'], $directory, 'package.zip');
        $this->files->decompress($directory, 'package.zip');
        $this->files->delete(["$directory/package.zip"]);

        $existing = $this->installs()->firstWhere('package', $package['id']);

        return ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => $package['id']],
            [
                'name' => $package['name'],
                'version' => $package['version'],
                'icon' => $package['icon'],
                // picked by hand once, it stays picked by hand
                'dependency' => $existing ? $existing->dependency && $package['dependency'] : $package['dependency'],
                'requires' => $package['requires'],
                'files' => [$directory],
            ],
        );
    }

    /** @return string[] */
    private function hidden(): array
    {
        return (array) $this->profile->setting('hidden_packages', []);
    }
}
