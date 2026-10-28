# Server Navigation

Orders the server sidebar per server. **Customize sidebar** (the pencil below the last entry, for
admins and the server's owner) lets you drag the entries; those below the line go into a collapsed
"More" menu. Permissions are not changed.

## Install

Upload `server-navigation.zip` from the [latest release](https://github.com/Marschux/Pelican-Tools/releases/latest) under **Admin → Plugins → Import from File**; updates show up there too. Or copy this folder to `/var/www/pelican/plugins/server-navigation/`, then:

```bash
cd /var/www/pelican
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan filament:cache-components
```
