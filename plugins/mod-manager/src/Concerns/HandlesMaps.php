<?php

namespace Marschux\ModManager\Concerns;

use Marschux\ModManager\Models\ModInstall;
use Marschux\ModManager\Sources\GameBanana;
use Marschux\ModManager\Sources\Workshop;
use RuntimeException;

/** Maps: from GameBanana or uploaded into the game folder, workshop maps by id; the rotation (mapcycle.txt, a mapgroup, RockTheVote's maplist.txt). Part of Installer. */
trait HandlesMaps
{
    /** Counter-Strike 2: a workshop map by id or link - nothing to download, the game fetches it. */
    public function addWorkshopMap(string $input): ModInstall
    {
        $id = Workshop::id($input) ?? throw new RuntimeException(trans('mod-manager::mods.workshop.bad_link'));
        $install = $this->storeWorkshopMap((new Workshop())->summary($id));

        $this->writeMaplist();

        return $install;
    }

    /**
     * A workshop map, or each Counter-Strike 2 map of a collection - by id or link.
     *
     * @return array{collection: bool, added: int, skipped: int}
     */
    public function addWorkshop(string $input): array
    {
        $id = Workshop::id($input) ?? throw new RuntimeException(trans('mod-manager::mods.workshop.bad_link'));
        $workshop = new Workshop();
        $children = $workshop->children($id);

        if ($children === null) {
            $this->addWorkshopMap($id);

            return ['collection' => false, 'added' => 1, 'skipped' => 0];
        }

        $maps = $workshop->maps($children);

        if ($maps === []) {
            throw new RuntimeException(trans('mod-manager::mods.workshop.collection_empty'));
        }

        foreach ($maps as $map) {
            $this->storeWorkshopMap($map);
        }

        $this->writeMaplist();

        return ['collection' => true, 'added' => count($maps), 'skipped' => count($children) - count($maps)];
    }

    /** @param  array{id: string, name: string, title: string, icon: ?string, size: int}  $map  from Workshop::summary() */
    private function storeWorkshopMap(array $map): ModInstall
    {
        return ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => Workshop::PREFIX . $map['id']],
            ['name' => $map['title'], 'version' => $map['name'], 'icon' => $map['icon'], 'dependency' => false, 'requires' => [], 'files' => [], 'enabled' => true, 'size' => $map['size']],
        );
    }

    /** Workshop maps only download with a Game Server Login Token (STEAM_ACC): the server has to be logged on to Steam. */
    public function hasLoginToken(): bool
    {
        return $this->variable('STEAM_ACC') !== '';
    }

    /** Counter-Strike 2: the Steam Web API key in WORKSHOP_AUTHKEY - browsing the workshop needs it. */
    public function steamKey(): string
    {
        return $this->variable('WORKSHOP_AUTHKEY');
    }

    /**
     * RockTheVote's maplist.txt with the workshop maps switched on, in a block of its own - lines
     * written by hand stay. A new file starts from the example RockTheVote comes with. Without
     * RockTheVote installed there is nothing to write.
     */
    public function writeMaplist(): void
    {
        $folder = collect([$this->modsDir(), $this->disabledDir()])->map(fn (string $dir) => "$dir/" . self::MAP_VOTE)->first(fn (string $path) => $this->files->exists("$path/" . self::MAP_VOTE . '.dll'));

        if ($folder === null) {
            return;
        }

        $current = (string) $this->files->read("$folder/maplist.txt");
        // a new file: the example's stock maps, not its sample workshop map - that would show twice
        $current = $current !== '' ? $current : (string) preg_replace('/^[^\/\n]*:\d+\r?\n/m', '', (string) $this->files->read("$folder/maplist.example.txt"));
        $maps = $this->installs()
            ->filter(fn (ModInstall $install) => str_starts_with($install->package, Workshop::PREFIX) && $install->enabled)
            ->map(fn (ModInstall $install) => $install->version . ':' . substr($install->package, strlen(Workshop::PREFIX)))
            ->values()
            ->all();

        $this->files->write("$folder/maplist.txt", self::withWorkshopBlock($current, $maps));
    }

    /** $text with the block of workshop maps replaced by $lines, or put at its end. */
    public static function withWorkshopBlock(string $text, array $lines): string
    {
        $block = [self::MAPLIST_START, ...$lines, self::MAPLIST_END];
        $kept = [];
        $inside = false;

        foreach (preg_split('/\r?\n/', rtrim($text, "\r\n")) as $line) {
            if (trim($line) === self::MAPLIST_START) {
                $inside = true;
            } elseif (trim($line) === self::MAPLIST_END) {
                $inside = false;
            } elseif (!$inside && ($line !== '' || $kept !== [])) {
                $kept[] = $line;
            }
        }

        return implode("\n", [...$kept, ...$block]) . "\n";
    }

    /**
     * A map archive from GameBanana: pulled and unpacked on the server, sorted into the game
     * folder (see mapPlacement). Files already there stay as they are and are not this map's -
     * wads and sounds are shared between maps. A map whose .bsp is there and not from this
     * install (de_dust2.bsp of the game) is not put over it.
     *
     * @param  array<string, mixed>  $package  from GameBanana::release()
     */
    private function installMap(array $package): ModInstall
    {
        $scratch = '.mod-manager/map';
        $file = basename((string) $package['file']);

        if (!preg_match('/^[\w .()\[\]+-]+\.\w+\z/', $file)) {
            throw new RuntimeException(trans('mod-manager::mods.messages.bad_file', ['file' => $file]));
        }

        $this->files->delete($this->files->exists($scratch) ? [$scratch] : []);

        try {
            $this->files->pull($package['download'], $scratch, $file);
            $this->files->decompress($scratch, $file);
            $this->files->delete(["$scratch/$file"]);

            return $this->placeMap($package, $scratch, $file);
        } finally {
            $this->files->delete([$scratch]);
        }
    }

    /**
     * The unpacked map in $scratch sorted into the game folder and into the rotation - from
     * GameBanana or uploaded. $file only names it in messages.
     *
     * @param  array<string, mixed>  $package  id, name, version, icon, size
     */
    private function placeMap(array $package, string $scratch, string $file): ModInstall
    {
        $existing = $this->installs()->firstWhere('package', $package['id']);
        $ours = $existing?->files ?? [];
        $others = $this->filesOfOthers($package['id']);
        $game = $this->gameDir();

        $placed = [];

        foreach ($this->files->walk($scratch) as $path) {
            $to = self::mapPlacement($path);

            if ($to !== null) {
                $placed["$game/$to"] = "$scratch/$path";
            }
        }

        if (!array_filter(array_keys($placed), fn (string $path) => str_ends_with(strtolower($path), '.bsp'))) {
            throw new RuntimeException(trans('mod-manager::mods.messages.no_map', ['file' => $file]));
        }

        $moves = [];
        $replace = [];
        $shared = [];
        $listings = [];

        foreach ($placed as $to => $from) {
            $listings[dirname($to)] ??= $this->files->list(dirname($to));

            if (isset($listings[dirname($to)][basename($to)])) {
                if (!in_array($to, $ours, true)) {
                    if (str_ends_with(strtolower($to), '.bsp')) {
                        throw new RuntimeException(trans('mod-manager::mods.messages.exists', ['file' => basename($to)]));
                    }

                    // a wad or sound another installed map brought: this one needs it too, so it is
                    // both maps' - removing either leaves it while the other is there
                    if (in_array($to, $others, true)) {
                        $shared[] = $to;
                    }

                    continue;
                }

                $replace[] = $to;
            }

            $moves[] = ['from' => $from, 'to' => $to];
        }

        $this->files->delete($replace);
        $this->files->move($moves);

        // what the version before brought and this one does not goes - unless another map needs it
        $files = array_values(array_unique([...array_column($moves, 'to'), ...$shared]));
        $this->files->delete(array_values(array_diff($ours, $files, $others)));

        // a new map comes into the mapcycle; one taken out of it before stays out
        $enabled = $existing?->enabled ?? true;
        $previousMaps = $existing ? self::mapNames($existing->files) : [];

        $this->writeMapcycle(fn (string $cycle) => self::withMaps(self::withMaps($cycle, $previousMaps, false), self::mapNames($files), $enabled));

        return ModInstall::query()->updateOrCreate(
            ['server_id' => $this->server->id, 'package' => $package['id']],
            [
                'name' => $package['name'],
                'version' => $package['version'],
                'icon' => $package['icon'],
                'dependency' => false,
                'requires' => [],
                'files' => $files,
                'pinned' => $package['pinned'] ?? false,
                'enabled' => $enabled,
                'size' => $package['size'] ?: null,
            ],
        );
    }

    /** @param  callable(string): string  $change */
    private function writeMapcycle(callable $change): void
    {
        if ($this->mapgroups()) {
            $this->writeMapgroup($change);

            return;
        }

        $path = $this->gameDir() . ($this->source1() ? '/cfg/mapcycle.txt' : '/mapcycle.txt');
        // CS:S comes with only cfg/mapcycle_default.txt and reads it while there is no mapcycle.txt - that is the start
        $cycle = $this->files->read($path) ?? ($this->source1()
            // without its "do not modify this file" header
            ? preg_replace('~^\s*//.*(\R|\z)~m', '', (string) $this->files->read($this->gameDir() . '/cfg/mapcycle_default.txt'))
            : null);

        $this->files->write($path, $change((string) $cycle));
    }

    /** CS:GO: started with +mapgroup, the rotation is a mapgroup and mapcycle.txt is not read. */
    public function mapgroups(): bool
    {
        return $this->source1() && basename($this->gameDir()) === 'csgo';
    }

    /**
     * The mapgroup mg_modmanager in gamemodes_server.txt, changed like mapcycle.txt: one map a
     * line. It starts with the maps of mg_active; the server's MAPGROUP is set to it.
     *
     * @param  callable(string): string  $change
     */
    private function writeMapgroup(callable $change): void
    {
        $path = $this->gameDir() . '/gamemodes_server.txt';
        $text = (string) $this->files->read($path);
        $maps = self::mapgroupMaps($text, self::MAPGROUP)
            ?? self::mapgroupMaps((string) $this->files->read($this->gameDir() . '/gamemodes.txt'), 'mg_active')
            ?? [];
        $changed = trim($change($maps === [] ? '' : implode("\n", $maps) . "\n"));

        $this->files->write($path, self::withMapgroup($text, $changed === '' ? [] : array_map('trim', explode("\n", $changed))));
        $this->setVariable('MAPGROUP', self::MAPGROUP);
    }

    /**
     * The maps of a mapgroup in a gamemodes*.txt, null when the group is not there.
     *
     * @return string[]|null
     */
    public static function mapgroupMaps(string $text, string $group): ?array
    {
        if (!preg_match('/"' . preg_quote($group, '/') . '"\s*\{[^{}]*"maps"\s*\{([^{}]*)\}/', $text, $match)) {
            return null;
        }

        preg_match_all('/"([^"]+)"\s*"[^"]*"/', $match[1], $maps);

        return $maps[1];
    }

    /**
     * gamemodes_server.txt with the mapgroup mg_modmanager holding $maps - replaced if there, else
     * added to "mapgroups"; the rest of the file stays as it is.
     *
     * @param  string[]  $maps
     */
    public static function withMapgroup(string $text, array $maps): string
    {
        $group = self::MAPGROUP;
        $block = "\t\t\"$group\"\n\t\t{\n\t\t\t\"name\"\t\"$group\"\n\t\t\t\"maps\"\n\t\t\t{\n"
            . implode('', array_map(fn (string $map) => "\t\t\t\t\"$map\"\t\"\"\n", $maps))
            . "\t\t\t}\n\t\t}\n";

        $pattern = '/[ \t]*"' . $group . '"\s*\{[^{}]*"maps"\s*\{[^{}]*\}[^{}]*\}[ \t]*\R?/';

        if (preg_match($pattern, $text)) {
            return (string) preg_replace_callback($pattern, fn () => $block, $text, 1);
        }

        if (preg_match('/"mapgroups"\s*\{[ \t]*\R?/', $text, $match, PREG_OFFSET_CAPTURE)) {
            $at = $match[0][1] + strlen($match[0][0]);

            return substr($text, 0, $at) . $block . substr($text, $at);
        }

        $mapgroups = "\t\"mapgroups\"\n\t{\n$block\t}\n";

        // a file without mapgroups: into its outer braces; no file: a new one
        return ($at = strrpos($text, '}')) !== false
            ? substr($text, 0, $at) . $mapgroups . substr($text, $at)
            : "\"GameModes_Server.txt\"\n{\n$mapgroups}\n";
    }

    /**
     * The maps among these files, by name: the .bsp files in the maps folder.
     *
     * @param  string[]  $files
     * @return string[]
     */
    public static function mapNames(array $files): array
    {
        return array_values(array_map(
            fn (string $path) => pathinfo($path, PATHINFO_FILENAME),
            array_filter($files, fn (string $path) => (bool) preg_match('#(^|/)maps/[^/]+\.bsp$#i', $path)),
        ));
    }

    /**
     * mapcycle.txt with these maps in it (appended, unless there) or out of it. Other lines stay.
     *
     * @param  string[]  $maps
     */
    public static function withMaps(string $cycle, array $maps, bool $in): string
    {
        $lines = $cycle === '' ? [] : preg_split('/\r?\n/', rtrim($cycle, "\r\n"));
        $eol = str_contains($cycle, "\r\n") ? "\r\n" : "\n";

        if ($in) {
            $there = array_map('trim', $lines);
            $lines = [...$lines, ...array_values(array_filter($maps, fn (string $map) => !in_array($map, $there, true)))];
        } else {
            $lines = array_values(array_filter($lines, fn (string $line) => !in_array(trim($line), $maps, true)));
        }

        return $lines === [] ? '' : implode($eol, $lines) . $eol;
    }

    /**
     * Where a file of a map archive goes, relative to the game folder - null to leave it out.
     * A game folder (maps/, sound/, gfx/, ...) counts from wherever it sits in the archive; loose
     * files go by their type. Readmes, sources and the like stay out.
     */
    public static function mapPlacement(string $path): ?string
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        $lower = array_map('strtolower', $parts);
        $file = end($parts);
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        foreach (['maps', 'materials', 'sound', 'models', 'sprites', 'gfx', 'overviews', 'resource'] as $folder) {
            // the folder in lower case - Linux tells Maps/ from maps/, the game only looks in the latter
            if (($index = array_search($folder, $lower, true)) !== false && $index < count($parts) - 1) {
                return implode('/', [$folder, ...array_slice($parts, $index + 1)]);
            }
        }

        return match ($extension) {
            'bsp', 'res', 'nav' => "maps/$file",
            'wad' => $file,
            'mdl' => "models/$file",
            'wav', 'mp3' => "sound/$file",
            'spr' => "sprites/$file",
            // loose skies: <name>up.tga, <name>dn.tga, ...
            'tga' => "gfx/env/$file",
            default => null,
        };
    }

    /** A map with files in the game folder and a place in the rotation: from GameBanana or uploaded. */
    public static function mapPackage(string $package): bool
    {
        return str_starts_with($package, GameBanana::PREFIX) || str_starts_with($package, self::MAP_UPLOAD);
    }
}
