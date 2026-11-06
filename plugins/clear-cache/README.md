# Clear Cache

A **Clear cache** button in **Admin → Plugins**, beside the import buttons above the list. It does what

```bash
php artisan optimize:clear && systemctl restart php8.4-fpm
```

does, without a shell, on any install path and PHP version:

- clears the panel's config, route, view, event and application caches (`optimize:clear`)
- resets PHP's code cache with `opcache_reset()`, the part a PHP-FPM restart was needed for
- tells the queue workers to restart after their current job (`queue:restart`)

Useful after copying a plugin by hand or changing `.env`. Uploading or updating a plugin under
**Plugins** needs none of it. Only admins allowed to manage plugins see the button; it asks before it runs.

Where `opcache.restrict_api` or `disable_functions` blocks `opcache_reset()`, the panel caches are
still cleared and the message says that PHP-FPM needs a restart by hand.

## Install

Upload `clear-cache.zip` from the [latest release](https://github.com/Marschux/Pelican-Tools/releases/latest) under **Admin → Plugins → Import from File**; updates show up there too. Or copy this folder to `/var/www/pelican/plugins/clear-cache/`, then:

```bash
cd /var/www/pelican
sudo -u www-data php artisan p:plugin:install clear-cache
```
