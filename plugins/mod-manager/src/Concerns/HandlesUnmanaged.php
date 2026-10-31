<?php

namespace Marschux\ModManager\Concerns;

use Marschux\ModManager\Models\ModInstall;
use RuntimeException;

/** What sits in the mods folder without the plugin having put it there: listed, taken over or deleted. Part of Installer. */
trait HandlesUnmanaged
{
    /**
     * What sits in the mods folder without the plugin having put it there - by hand, or with
     * r2modman. A folder named like a package ("Namespace-Name") can be taken over.
     *
     * @return array<int, array{path: string, name: string, version: ?string, package: ?string}>
     */
    public function unmanaged(): array
    {
        $known = $this->knownFiles();

        if ($this->goldsrc()) {
            return $this->unmanagedAmxx($known);
        }

        // SourceMod's own plugins and those put there by hand have their own section (loaderPlugins)
        if ($this->source1()) {
            return [];
        }

        if ($this->jarMods()) {
            return $this->unmanagedJars($known);
        }

        $found = [];

        foreach ($this->files->list($this->modsDir()) as $name => $isDirectory) {
            $path = $this->modsDir() . "/$name";

            if (in_array($path, $known, true)) {
                continue;
            }

            // CounterStrikeSharp's own folder for plugins switched off by hand
            if ($this->source2()) {
                if ($isDirectory && $name !== 'disabled') {
                    $found[] = ['path' => $path, 'name' => $name, 'version' => null, 'package' => self::UPLOAD . $name];
                }

                continue;
            }

            $manifest = $isDirectory ? $this->manifest($path) : null;
            $package = $isDirectory && preg_match('/^\w+-\w+$/', $name) && is_array($manifest) ? $name : null;

            $found[] = [
                'path' => $path,
                'name' => is_array($manifest) && isset($manifest['name']) ? str_replace('_', ' ', (string) $manifest['name']) : $name,
                'version' => is_array($manifest) ? ($manifest['version_number'] ?? null) : null,
                'package' => $package,
            ];
        }

        return $found;
    }

    /** Takes a hand-installed package over as it is, so it gets update hints and can be removed. */
    public function adopt(string $path): ModInstall
    {
        $entry = collect($this->unmanaged())->firstWhere('path', $path);

        if (!$entry || !$entry['package']) {
            throw new RuntimeException(trans('mod-manager::mods.messages.not_adoptable'));
        }

        if ($this->source2()) {
            return ModInstall::query()->create([
                'server_id' => $this->server->id,
                'package' => $entry['package'],
                'name' => $entry['name'],
                'version' => '-',
                'icon' => null,
                'dependency' => false,
                'requires' => [],
                'files' => [$path],
                'size' => $this->files->usage($path),
            ]);
        }

        // registered in plugins-modmanager.ini like an upload, switched on
        if ($this->goldsrc()) {
            $this->writeUploadedIni(fn (string $ini) => self::withAmxxPlugin(self::withAmxxLine($ini, basename($path)), basename($path), true));

            return ModInstall::query()->create([
                'server_id' => $this->server->id,
                'package' => $entry['package'],
                'name' => $entry['name'],
                'version' => '-',
                'icon' => null,
                'dependency' => false,
                'requires' => [],
                'files' => [$path],
                'size' => $this->files->size($path),
            ]);
        }

        if ($this->jarMods()) {
            // the same plugin twice: Paper refuses to load either, so one has to go
            if ($this->installs()->contains('package', $entry['package'])) {
                throw new RuntimeException(trans('mod-manager::mods.messages.duplicate', ['name' => $entry['name']]));
            }

            return ModInstall::query()->create([
                'server_id' => $this->server->id,
                'package' => $entry['package'],
                'name' => $entry['name'],
                'version' => (string) ($entry['version'] ?? '0'),
                'icon' => $entry['icon'],
                'dependency' => false,
                'requires' => $entry['requires'],
                'files' => [$path],
                'size' => $this->files->size($path),
            ]);
        }

        // asks the source, so only packages that really exist there are taken over
        $latest = $this->source->latest($entry['package']);
        $manifest = $this->manifest($path) ?? [];

        return ModInstall::query()->create([
            'server_id' => $this->server->id,
            'package' => $entry['package'],
            'name' => $latest['name'],
            'version' => (string) ($entry['version'] ?? '0'),
            'icon' => $latest['icon'],
            'dependency' => false,
            'requires' => array_values(array_map(fn (string $dependency) => preg_replace('/-[^-]+$/', '', $dependency), (array) ($manifest['dependencies'] ?? []))),
            'files' => [$path],
            'size' => $this->files->usage($path),
        ]);
    }

    /** Deletes something hand-installed - only what unmanaged() lists, nothing else. */
    public function removeUnmanaged(string $path): void
    {
        if (preg_match('/\.(bsp|vpk)\z/i', $path) && collect($this->unmanagedMaps())->contains('path', $path)) {
            // the map's companions beside it go along: navigation mesh, resources, overview text
            $base = substr(basename($path), 0, -4);
            $folder = dirname($path);
            $this->files->delete(collect($this->files->list($folder))
                ->reject()
                ->keys()
                ->filter(fn (string $name) => pathinfo($name, PATHINFO_FILENAME) === $base)
                ->map(fn (string $name) => "$folder/$name")
                ->values()
                ->all());

            return;
        }

        if (!collect($this->unmanaged())->contains('path', $path)) {
            throw new RuntimeException(trans('mod-manager::mods.messages.not_adoptable'));
        }

        $this->files->delete([$path]);
    }
}
