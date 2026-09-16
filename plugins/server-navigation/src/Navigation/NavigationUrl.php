<?php

namespace Marschux\ServerNavigation\Navigation;

class NavigationUrl
{
    /**
     * The segment right after the server in a server panel url - "/server/84eb5e20/backups/create"
     * gives "backups". Null for anything outside the server, like the admin link.
     */
    public static function slug(string $url, string $tenant): ?string
    {
        $segments = explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'));
        $index = array_search($tenant, $segments, true);

        return $index === false ? null : ($segments[$index + 1] ?? null);
    }

    /** "files/{path?}" and the like count as "files". */
    public static function firstSegment(string $slug): string
    {
        return explode('/', trim($slug, '/'))[0];
    }
}
