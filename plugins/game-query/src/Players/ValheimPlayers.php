<?php

namespace Marschux\GameQuery\Players;

use App\Enums\SubuserPermission;

/**
 * Valheim keeps its three lists as plain text files, one platform id per line.
 *
 * There are no kick or ban actions: the dedicated server does not read the console at all -
 * typing "kick <name>" into the panel console produces not even an error - and its own kick and
 * ban commands exist only in the in-game F5 console of an admin. Everything here therefore goes
 * through the files, which the server re-reads on its own.
 */
class ValheimPlayers extends PlayerManager
{
    /** The egg pins this with -savedir "savegames". */
    private const DIR = 'savegames';

    private const FILES = [
        'admins' => 'adminlist.txt',
        'bans' => 'bannedlist.txt',
        'whitelist' => 'permittedlist.txt',
    ];

    /**
     * "[Platform]_[User ID]", case sensitive - the server log writes them as
     * "Steam_76561197984483021". Kept generic rather than a fixed list of prefixes so Xbox,
     * PlayStation and Switch ids pass too.
     */
    public const ID = '/^[A-Za-z]+_[A-Za-z0-9]{1,56}$/';

    public function tabs(): array
    {
        return ['online', 'admins', 'bans', 'whitelist'];
    }

    public function canRead(string $tab): bool
    {
        return $tab === 'online' || $this->can(SubuserPermission::FileReadContent);
    }

    public function canWrite(string $tab): bool
    {
        return isset(self::FILES[$tab]) && $this->can(SubuserPermission::FileUpdate);
    }

    /** The list files hold platform ids, not names - calling that column "name" would mislead. */
    public function nameLabel(string $tab): string
    {
        return $tab === 'online' ? 'name' : 'id';
    }

    public function rows(string $tab): array
    {
        if ($tab === 'online') {
            return $this->queriedPlayers();
        }

        $rows = [];

        foreach (self::ids((string) $this->readFile($this->path($tab))) as $id) {
            $rows[$id] = ['name' => $id];
        }

        return $rows;
    }

    public function add(string $tab, string $input): string
    {
        $id = self::normaliseId($input);

        if ($id === null || !isset(self::FILES[$tab])) {
            throw PlayerActionException::make('invalid_id');
        }

        return $this->save($tab, fn (string $content) => self::withId($content, $id));
    }

    public function remove(string $tab, array $row): string
    {
        $id = self::normaliseId((string) ($row['name'] ?? ''));

        if ($id === null || !isset(self::FILES[$tab])) {
            throw PlayerActionException::make('invalid_id');
        }

        return $this->save($tab, fn (string $content) => self::withoutId($content, $id));
    }

    /** @param  callable(string): string  $change */
    private function save(string $tab, callable $change): string
    {
        $path = $this->path($tab);
        $content = $this->readFile($path);

        return $this->writeFile($path, $change($content ?? ''), $content !== null);
    }

    private function path(string $tab): string
    {
        return self::DIR . '/' . self::FILES[$tab];
    }

    public static function normaliseId(string $input): ?string
    {
        $id = trim($input);

        return preg_match(self::ID, $id) === 1 ? $id : null;
    }

    /**
     * The ids in a list file, without the "//" comment lines the server writes as a header.
     *
     * @return string[]
     */
    public static function ids(string $content): array
    {
        $ids = [];

        foreach (preg_split('/\R/', $content) as $line) {
            $line = trim($line);

            if ($line !== '' && !str_starts_with($line, '//')) {
                $ids[] = $line;
            }
        }

        return $ids;
    }

    /** Appends unless it is already listed; comment lines and order are left alone. */
    public static function withId(string $content, string $id): string
    {
        if (in_array($id, self::ids($content), true)) {
            return $content;
        }

        $lines = $content === '' ? [] : preg_split('/\R/', rtrim($content, "\r\n"));
        $lines[] = $id;

        return implode("\n", $lines) . "\n";
    }

    /** Removes only the exact line, so removing "Steam_123" leaves "Steam_1234" alone. */
    public static function withoutId(string $content, string $id): string
    {
        $kept = [];

        foreach (preg_split('/\R/', rtrim($content, "\r\n")) as $line) {
            if (trim($line) !== $id) {
                $kept[] = $line;
            }
        }

        return $kept === [] ? '' : implode("\n", $kept) . "\n";
    }
}
