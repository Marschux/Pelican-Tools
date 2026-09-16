# Server Navigation

Hides sidebar entries of the server panel per server. Admin panel → edit a server → tab
**Sidebar** next to allocations and databases: uncheck what this server does not need.

Only the sidebar changes - a hidden page stays reachable by its url, and permissions are untouched.

## Install

Copy this folder to `/var/www/pelican/plugins/server-navigation/`, then:

```bash
cd /var/www/pelican
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan filament:cache-components
```
