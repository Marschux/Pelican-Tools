# Server Navigation

Hides sidebar entries of the server panel per server. Admin panel → edit a server → tab
**Sidebar** after the actions tab, saved with the page: unchecked entries move into a collapsed "More" menu at the end of the sidebar.

Only the sidebar changes - permissions are untouched.

## Install

Copy this folder to `/var/www/pelican/plugins/server-navigation/`, then:

```bash
cd /var/www/pelican
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan filament:cache-components
```
