# Server Resources

Puts a resource overview on the admin dashboard:

- **Panel host** - cpu, memory, disk, load and uptime of the machine the panel runs on, read from
  `/proc` (root admins only).
- **One card per node** - cpu, memory and disk from the node's own statistics, and below it every
  server on that node with icon, egg, status, cpu, memory, disk and - when the Game Query plugin is
  installed - the player count.

Values come from the panel's cached helpers (`Node::statistics()`, `Server::retrieveResources()`),
so the dashboard polls no harder than the node and server pages already do. Servers on a node in
maintenance or out of reach show no values instead of waiting for a timeout each.

In an LXC container, cpu is the container's own only when lxcfs virtualises `/proc/stat`;
otherwise it shows the host's.

## Install

Copy this folder to `/var/www/pelican/plugins/server-resources/`, then:

```bash
cd /var/www/pelican
sudo -u www-data php artisan filament:cache-components
```
