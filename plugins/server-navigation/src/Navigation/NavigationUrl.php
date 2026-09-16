<?php

namespace Marschux\ServerNavigation\Navigation;

class NavigationUrl
{
    /** The panel's own "open in admin" link, the one entry that is not a page of the server. */
    public const ADMIN = 'open-in-admin';

    /**
     * The segment right after the server in a server panel url - "/server/84eb5e20/backups/create"
     * gives "backups". A link into the admin panel counts as the "open in admin" entry; anything
     * else outside the server gives null.
     */
    public static function slug(string $url, string $tenant, string $adminPath = 'admin'): ?string
    {
        $segments = explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'));

        if ($segments[0] === trim($adminPath, '/')) {
            return self::ADMIN;
        }

        $index = array_search($tenant, $segments, true);

        return $index === false ? null : ($segments[$index + 1] ?? null);
    }

    /** "files/{path?}" and the like count as "files". */
    public static function firstSegment(string $slug): string
    {
        return explode('/', trim($slug, '/'))[0];
    }
}
