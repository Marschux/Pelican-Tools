<?php

namespace Marschux\ModManager;

use App\Facades\Activity;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Throwable;

/**
 * The file operations an install needs, all done by Wings: it downloads, unpacks and moves, so
 * no mod passes through the panel.
 */
class ServerFiles
{
    private DaemonFileRepository $repository;

    public function __construct(private Server $server)
    {
        $this->repository = app(DaemonFileRepository::class)->setServer($server);
    }

    /** Downloads $url into $directory and only returns once the file is there. */
    public function pull(string $url, string $directory, string $filename): void
    {
        // ponytail: inside the click, so a download longer than the web server's timeout fails;
        // a queued job is the upgrade once mods that large turn up
        $this->repository->getHttpClient()
            ->timeout(300)
            ->post("/api/servers/{$this->server->uuid}/files/pull", [
                'url' => $url,
                'root' => $directory,
                'file_name' => $filename,
                'foreground' => true,
            ])
            ->throw();

        Activity::event('server:file.pull')->property(['url' => $url, 'directory' => $directory])->log();
    }

    public function decompress(string $directory, string $file): void
    {
        // Wings answers a format it cannot read with a bare 500
        if ($this->repository->decompressFile($directory, $file)->failed()) {
            throw new RuntimeException(trans('mod-manager::mods.messages.unpack_failed', ['file' => $file]));
        }
    }

    /** @param  array<array{from: string, to: string}>  $moves  paths from the server root */
    public function move(array $moves): void
    {
        foreach (array_chunk($moves, 200) as $chunk) {
            $this->repository->renameFiles('/', $chunk)->throw();
        }
    }

    /** @param  string[]  $paths  missing ones are fine */
    public function delete(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        // Wings fails the whole call over one missing file - someone may have removed it by
        // hand, so that case goes file by file, skipping what is gone already. Pelican's client
        // throws on any error status, so the 404 arrives as an exception.
        try {
            $this->repository->deleteFiles('/', array_values(array_unique($paths)))->throw();
        } catch (RequestException $exception) {
            if ($exception->response->status() !== 404) {
                throw $exception;
            }

            foreach (array_unique($paths) as $path) {
                try {
                    $this->repository->deleteFiles('/', [$path])->throw();
                } catch (RequestException $missing) {
                    if ($missing->response->status() !== 404) {
                        throw $missing;
                    }
                }
            }
        }

        Activity::event('server:file.delete')->property(['directory' => '/', 'files' => $paths])->log();
    }

    public function write(string $path, string $content): void
    {
        $this->repository->putContent($path, $content);

        Activity::event('server:file.write')->property('file', $path)->log();
    }

    public function read(string $path): ?string
    {
        try {
            return (string) $this->repository->getContent($path, 1048576);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, bool> name => is directory, empty when the directory is missing */
    public function list(string $directory): array
    {
        try {
            $entries = $this->repository->getDirectory($directory === '' ? '/' : $directory);
        } catch (Throwable) {
            return [];
        }

        $list = [];

        foreach (array_is_list($entries) ? $entries : [] as $entry) {
            $list[$entry['name']] = (bool) ($entry['directory'] ?? false);
        }

        return $list;
    }

    /** @return string[] every file below $directory, relative to it */
    public function walk(string $directory, string $prefix = ''): array
    {
        $files = [];

        foreach ($this->list(rtrim("$directory/$prefix", '/')) as $name => $isDirectory) {
            $files = $isDirectory ? [...$files, ...$this->walk($directory, "$prefix$name/")] : [...$files, "$prefix$name"];
        }

        return $files;
    }

    /** Bytes of a file, or of everything below a directory. */
    public function size(string $path): int
    {
        $directory = dirname($path) === '.' ? '/' : dirname($path);

        try {
            $entries = $this->repository->getDirectory($directory);
        } catch (Throwable) {
            return 0;
        }

        foreach (array_is_list($entries) ? $entries : [] as $entry) {
            if ($entry['name'] === basename($path)) {
                return ($entry['directory'] ?? false) ? $this->usage($path) : (int) ($entry['size'] ?? 0);
            }
        }

        return 0;
    }

    /** Bytes of every file below $directory together. */
    public function usage(string $directory): int
    {
        try {
            $entries = $this->repository->getDirectory($directory);
        } catch (Throwable) {
            return 0;
        }

        $bytes = 0;

        foreach (array_is_list($entries) ? $entries : [] as $entry) {
            $bytes += ($entry['directory'] ?? false) ? $this->usage("$directory/{$entry['name']}") : (int) ($entry['size'] ?? 0);
        }

        return $bytes;
    }

    public function exists(string $path): bool
    {
        $directory = dirname($path);

        return array_key_exists(basename($path), $this->list($directory === '.' ? '' : $directory));
    }
}
