<?php

namespace Marschux\GameManager\Concerns;

use Illuminate\Support\Facades\Cache;
use Marschux\GameManager\Models\ModInstall;
use Marschux\GameManager\Sources\Hangar;
use Marschux\GameManager\Sources\Modrinth;
use RuntimeException;

/** Minecraft Paper: plugin jars, datapacks and resource packs, server.properties. Part of Installer. */
trait HandlesJars
{
    /**
     * A plugin: one jar, pulled straight into the mods folder - or next to it while switched off.
     * A datapack likewise into the world's datapacks folder.
     */
    private function installJar(array $package): ModInstall
    {
        if (self::kind($package['id']) === 'resourcepack') {
            return $this->installResourcePack($package);
        }

        $existing = $this->installs()->firstWhere('package', $package['id']);
        $enabled = $existing?->enabled ?? true;
        $directory = self::kind($package['id']) === 'datapack' ? $this->datapacksDir() : $this->modsDir();
        $path = ($enabled ? $directory : "{$directory}_disabled") . '/' . self::jarName((string) $package['file']);

        // an update replaces the old jar, which may be named differently; Wings will not pull onto a file
        $this->files->delete(array_values(array_filter(array_unique([...($existing?->files ?? []), $path]), fn (string $old) => $this->files->exists($old))));
        $this->files->pull($package['download'], dirname($path), basename($path));

        return ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => $package['id']],
            [
                'name' => $package['name'],
                'version' => $package['version'],
                'icon' => $package['icon'],
                'dependency' => $existing ? $existing->dependency && $package['dependency'] : $package['dependency'],
                'requires' => $package['requires'],
                'files' => [$path],
                'pinned' => $package['pinned'] ?? false,
                'enabled' => $enabled,
                'size' => $package['size'] ?: $this->files->size($path),
            ],
        );
    }

    /**
     * A resource pack: server.properties points the players to it, no file on the server. A new
     * one is switched on, which switches the one before off.
     */
    private function installResourcePack(array $package): ModInstall
    {
        $existing = $this->installs()->firstWhere('package', $package['id']);
        $enabled = $existing?->enabled ?? true;

        if ($enabled) {
            $this->useResourcePack($package, $package['id']);
        }

        return ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => $package['id']],
            [
                'name' => $package['name'],
                'version' => $package['version'],
                'icon' => $package['icon'],
                'dependency' => false,
                'requires' => [],
                'files' => [],
                'pinned' => $package['pinned'] ?? false,
                'enabled' => $enabled,
                'size' => $package['size'],
            ],
        );
    }

    /**
     * Points server.properties to this resource pack, or to none. The other resource packs are
     * switched off - Minecraft offers only one.
     *
     * @param  array<string, mixed>|null  $package  from release()
     */
    private function useResourcePack(?array $package, ?string $id = null): void
    {
        $this->files->write(self::PROPERTIES, self::withProperties((string) $this->files->read(self::PROPERTIES), [
            'resource-pack' => (string) ($package['download'] ?? ''),
            'resource-pack-sha1' => (string) ($package['sha1'] ?? ''),
        ]));

        if ($id !== null) {
            ModInstall::query()
                ->where('server_id', $this->server->id)
                ->where('package', 'like', Modrinth::KINDS['resourcepack']['prefix'] . '%')
                ->where('package', '!=', $id)
                ->update(['enabled' => false]);
        }
    }

    /** The world's datapacks folder, e.g. world/datapacks - the world named in server.properties. */
    private function datapacksDir(): string
    {
        $level = trim((string) self::property((string) $this->files->read(self::PROPERTIES), 'level-name'), '/');

        return ($level === '' || str_contains($level, '..') ? 'world' : $level) . '/datapacks';
    }

    /** A value of a Java properties file, "\:" read as ":"; null when it is not there. */
    public static function property(string $text, string $key): ?string
    {
        if (!preg_match('/^' . preg_quote($key, '/') . '[ \t]*[=:][ \t]*([^\r\n]*)/m', $text, $match)) {
            return null;
        }

        return preg_replace('/\\\\(.)/', '$1', trim($match[1]));
    }

    /**
     * The properties file with these values set: a line that is there is replaced, else one is
     * added. Everything else stays as it is.
     *
     * @param  array<string, string>  $values
     */
    public static function withProperties(string $text, array $values): string
    {
        foreach ($values as $key => $value) {
            // one line, and a backslash would start an escape
            $line = "$key=" . str_replace(['\\', "\r", "\n"], ['\\\\', '', ''], $value);
            $replaced = preg_replace_callback('/^' . preg_quote($key, '/') . '[ \t]*[=:][^\r\n]*/m', fn () => $line, $text, 1, $count);
            $text = $count ? $replaced : ($text === '' ? '' : rtrim($text, "\r\n") . "\n") . "$line\n";
        }

        return $text;
    }

    /** plugin (any mod), datapack or resourcepack */
    public static function kind(string $package): string
    {
        return Modrinth::kindOf($package) ?? 'plugin';
    }

    /** The file's name as the source gives it - it ends up in a path, so nothing but a plain name. */
    public static function jarName(string $file): string
    {
        if (!preg_match('/^\w[\w .+()\[\]-]*\.(jar|zip)\z/i', $file)) {
            throw new RuntimeException(trans('game-manager::mods.messages.bad_file', ['file' => $file]));
        }

        return $file;
    }

    /** A package of a source that ships single jars: Modrinth or Hangar. */
    public static function jarPackage(string $package): bool
    {
        return Modrinth::kindOf($package) !== null || str_starts_with($package, Hangar::PREFIX);
    }

    /** Mods of this game are single jars: the profile gets them from Modrinth or Hangar. */
    public function jarMods(): bool
    {
        return array_intersect($this->profile->sources(), ['modrinth', 'hangar']) !== [];
    }

    /**
     * The jars in the plugins folder the plugin did not put there, recognised by their hash on
     * Modrinth or Hangar. Data folders and other files next to them are left out.
     *
     * @param  string[]  $known  paths of what is installed through the plugin
     * @return array<int, array{path: string, name: string, version: ?string, package: ?string, icon: ?string, requires: string[]}>
     */
    private function unmanagedJars(array $known): array
    {
        $found = [];

        foreach ($this->files->entries($this->modsDir()) as $name => $entry) {
            $path = $this->modsDir() . "/$name";

            if ($entry['directory'] || !str_ends_with(strtolower($name), '.jar') || in_array($path, $known, true)) {
                continue;
            }

            $match = $this->identify($path, $entry);

            $found[] = [
                'path' => $path,
                'name' => $match['name'] ?? $name,
                'version' => $match['version'] ?? null,
                'package' => $match['id'] ?? null,
                'icon' => $match['icon'] ?? null,
                'requires' => $match['requires'] ?? [],
            ];
        }

        return $found;
    }

    /**
     * What a jar is, by its hash: asked once per file content and kept, the tab lists these on
     * every visit.
     *
     * @param  array{size: int, modified: string}  $entry
     * @return array<string, mixed>|null
     */
    private function identify(string $path, array $entry): ?array
    {
        $key = 'game-manager:jar:' . $this->server->uuid . ':' . md5("$path:{$entry['size']}:{$entry['modified']}");

        // not found is kept too ([]), else every visit would read the jar and ask again
        $match = Cache::remember($key, now()->addDay(), function () use ($path) {
            // the jar passes through the panel once to be hashed; Wings has no checksum call
            $content = $this->files->read($path, 64 * 1048576);

            if ($content === null) {
                return [];
            }

            $sources = $this->profile->sources();
            $sha1 = sha1($content);

            if (in_array('modrinth', $sources, true)) {
                $found = (new Modrinth((array) $this->profile->setting('modrinth_loaders', [])))->byHashes([$sha1])[$sha1] ?? null;

                if ($found) {
                    return $found;
                }
            }

            return in_array('hangar', $sources, true) ? ((new Hangar())->byHash(hash('sha256', $content)) ?? []) : [];
        });

        return $match ?: null;
    }
}
