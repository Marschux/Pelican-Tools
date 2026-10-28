# Server Navigation

Orders the sidebar of the server panel per server, right in the sidebar: the pencil below the last entry,
**Customize sidebar** (admins and the server's owner, not subusers) turns it into a list to drag.
Everything above the line shows directly, everything below it goes into the collapsed "More" menu.
**Done** saves it for everyone on that server.

Only the sidebar changes - permissions are untouched.

## Install

Copy this folder to `/var/www/pelican/plugins/server-navigation/`, then:

```bash
cd /var/www/pelican
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan filament:cache-components
```
