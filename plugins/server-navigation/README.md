# Server Navigation

Orders the sidebar of the server panel per server, right in the sidebar: the pencil below the last entry,
**Customize sidebar** (admins and the server's owner, not subusers) turns it into a list to drag.
Everything above the line shows directly, everything below it goes into the collapsed "More" menu.
**Done** saves it for everyone on that server.

Only the sidebar changes - permissions are untouched.

## Install

Upload `server-navigation.zip` from the [latest release](https://github.com/Marschux/Pelican-Tools/releases/latest) under **Admin → Plugins → Import from File**; updates show up there too. Or copy this folder to `/var/www/pelican/plugins/server-navigation/`, then:

```bash
cd /var/www/pelican
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan filament:cache-components
```
