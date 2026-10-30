<?php

namespace Marschux\ModManager\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Marschux\ModManager\Models\ModInstall;
use RuntimeException;

/** Configs: which belong to a mod, and reading, writing and deleting them from the page. Part of Installer. */
trait HandlesConfigs
{
    /**
     * The configs these mods wrote on their own when they ran - what uninstalling leaves behind
     * unless asked. Never a file another installed mod brought along.
     *
     * @param  Collection<int, ModInstall>  $installs
     * @return string[]
     */
    public function runtimeConfigs(Collection $installs): array
    {
        // what an uploaded archive brought into the config folder
        if ($this->goldsrc() || $this->source1()) {
            return array_values(array_filter(
                $installs->filter(fn (ModInstall $install) => $this->ownPlugin($install->package))->pluck('files')->flatten()->all(),
                fn (string $path) => $this->pluginConfig($path),
            ));
        }

        if ($this->jarMods()) {
            // datapacks and resource packs keep no data of their own
            return $this->dataFolders($installs->filter(fn (ModInstall $install) => self::kind($install->package) === 'plugin'));
        }

        $directory = $this->configDir();
        $others = $this->installs()->reject(fn (ModInstall $other) => $installs->contains('id', $other->id))->pluck('files')->flatten()->all();
        $configs = $this->configs();
        $found = [];

        foreach ($installs as $install) {
            $found = [...$found, ...self::configsOf($install->name, $configs, $directory, [...$others, ...$install->files])];
        }

        return array_values(array_unique($found));
    }

    /**
     * Configs that belong to a mod by name: the file is named after it (advize.PlantEverything.cfg)
     * or sits in a folder named after it (EpicLoot/...). Paths in $skip stay.
     *
     * @param  string[]  $configs  from configs()
     * @param  string[]  $skip
     * @return string[]
     */
    public static function configsOf(string $modName, array $configs, string $directory, array $skip = []): array
    {
        $needle = self::needle($modName);

        if (strlen($needle) < 3 || $directory === '') {
            return [];
        }

        return array_values(array_filter($configs, function (string $path) use ($needle, $directory, $skip) {
            if (in_array($path, $skip, true) || !str_starts_with($path, "$directory/")) {
                return false;
            }

            $parts = explode('/', substr($path, strlen($directory) + 1));

            return (count($parts) > 1 && self::needle($parts[0]) === $needle)
                || str_contains(self::needle(pathinfo($path, PATHINFO_FILENAME)), $needle);
        }));
    }

    /**
     * The folders these plugins keep their data in: plugins/<plugin name> next to the jars.
     *
     * @param  Collection<int, ModInstall>  $installs
     * @return string[]
     */
    private function dataFolders(Collection $installs): array
    {
        $folders = array_keys(array_filter($this->files->list($this->modsDir())));
        $others = $this->installs()->reject(fn (ModInstall $other) => $installs->contains('id', $other->id))->pluck('name')->all();
        $found = [];

        foreach ($installs as $install) {
            foreach (self::dataFoldersOf($install->name, $folders, $others) as $folder) {
                $found[] = $this->modsDir() . "/$folder";
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * A plugin's data folder is named after the plugin as its jar names it, which is close to
     * but not always the project's name: "EssentialsX" writes to "Essentials". So a folder
     * named exactly like it, else one whose name the plugin's name starts with - unless that
     * folder fits one of the $others too, then it is not certain whose it is.
     *
     * @param  string[]  $folders
     * @param  string[]  $others  names of the other installed plugins
     * @return string[]
     */
    public static function dataFoldersOf(string $name, array $folders, array $others = []): array
    {
        $needle = self::needle($name);

        if (strlen($needle) < 3) {
            return [];
        }

        $exact = array_values(array_filter($folders, fn (string $folder) => self::needle($folder) === $needle));

        if ($exact !== []) {
            return $exact;
        }

        $fits = fn (string $folder, string $plugin) => strlen(self::needle($folder)) >= 4 && str_starts_with(self::needle($plugin), self::needle($folder));

        return array_values(array_filter($folders, fn (string $folder) => $fits($folder, $name)
            && !array_filter($others, fn (string $other) => $fits($folder, $other))));
    }

    /** A name as it shows up in file names: lower case, letters and digits only. */
    private static function needle(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($name));
    }

    /**
     * The text files in the config folder - what the mods wrote there on their first start.
     *
     * @return string[] paths from the server root
     */
    public function configs(): array
    {
        if ($this->jarMods()) {
            return $this->pluginConfigs();
        }

        $directory = trim((string) $this->profile->setting('config_dir'), '/');

        if ($directory === '') {
            return [];
        }

        // plugins write theirs to cfg/<plugin>/ beside Valve's own files: SourceMod's to cfg/sourcemod/,
        // Metamod plugins (MultiAddonManager ...) to a folder of their own
        $cfg = $this->source1() || $this->source2() ? $this->gameDir() . '/cfg' : null;
        $metamod = $cfg === null ? [] : collect($this->files->list($cfg))
            ->filter()
            ->keys()
            ->flatMap(fn (string $folder) => collect($this->files->list("$cfg/$folder"))->reject()->keys()->map(fn (string $file) => "$cfg/$folder/$file"))
            ->all();

        return collect($this->files->walk($directory))
            ->map(fn (string $path) => "$directory/$path")
            ->concat($metamod)
            ->filter(fn (string $path) => in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::CONFIG_TYPES, true))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Plugins write their configs to plugins/<plugin name>/. Only that first level: deeper down
     * are languages, player data and the like.
     *
     * @return string[]
     */
    private function pluginConfigs(): array
    {
        $directory = $this->modsDir();

        return collect($this->files->list($directory))
            ->filter(fn (bool $isDirectory, string $name) => $isDirectory && !str_starts_with($name, '.'))
            ->keys()
            ->flatMap(fn (string $folder) => collect($this->files->list("$directory/$folder"))
                ->reject()
                ->keys()
                ->map(fn (string $file) => "$directory/$folder/$file"))
            ->filter(fn (string $path) => in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::CONFIG_TYPES, true))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The config a mod most likely wrote: in a folder named after it (plugins/LuckPerms/config.yml,
     * config.yml first), else named after its id (advize.PlantEverything.cfg).
     */
    public static function configFor(string $modName, array $configs): ?string
    {
        $needle = self::needle($modName);

        if (strlen($needle) < 3) {
            return null;
        }

        $inFolder = array_filter($configs, fn (string $path) => self::dataFoldersOf($modName, [basename(dirname($path))]) !== []);

        if ($inFolder !== []) {
            usort($inFolder, fn (string $a, string $b) => (basename($a) !== 'config.yml') <=> (basename($b) !== 'config.yml'));

            return $inFolder[0];
        }

        foreach ($configs as $path) {
            if (str_contains(self::needle(pathinfo($path, PATHINFO_FILENAME)), $needle)) {
                return $path;
            }
        }

        return null;
    }

    /** Reads one config - only one configs() lists, so the editor cannot open anything else. */
    public function readConfig(string $path): string
    {
        $this->assertConfig($path);

        return (string) $this->files->read($path);
    }

    public function writeConfig(string $path, string $content): void
    {
        $this->assertConfig($path);

        $this->files->write($path, $content);
    }

    /** Back to defaults: the mod writes a fresh one on its next start. */
    public function deleteConfig(string $path): void
    {
        $this->assertConfig($path);

        $this->files->delete([$path]);
    }

    private function assertConfig(string $path): void
    {
        if (!in_array($path, $this->configs(), true)) {
            throw new RuntimeException(trans('mod-manager::mods.messages.not_a_config'));
        }
    }
}
