# Server Resources

A resource overview on the admin dashboard: cpu, memory, disk and uptime of the panel host, and
one card per node with its servers (status, cpu, memory, disk and, with Game Query installed,
players).

![Server Resources on the admin dashboard](../../assets/screenshots/server-resources.png)

## Install

Upload `server-resources.zip` from the [latest release](https://github.com/Marschux/Pelican-Tools/releases/latest) under **Admin → Plugins → Import from File**; updates show up there too. Or copy this folder to `/var/www/pelican/plugins/server-resources/`, then:

```bash
cd /var/www/pelican
sudo -u www-data php artisan filament:cache-components
```
