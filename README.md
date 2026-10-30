# Pelican Eggs & Plugins

Custom and modified eggs plus custom plugins for the Pelican panel.

<p align="center">
  <img src="assets/pelican-panel.png" alt="Pelican Panel" width="160">
</p>

## Requirements

Pelican panel `1.0.0-rc.2` or any newer `1.x`. The plugins declare this as `panel_version` and Pelican marks them incompatible on anything else.

## Eggs

All eggs sit flat in `eggs/`. Import one under **Admin → Eggs → Import**; to update an egg that is already in the panel, import it onto that egg instead of creating a new one. An updated install script only runs for servers installed or reinstalled afterwards.

| Game | Egg file | Server type |
|---|---|---|
| Counter-Strike 1.6 | `eggs/egg-counter-strike-16.json` | GoldSrc |
| Counter-Strike: Condition Zero | `eggs/egg-counter-strike-condition-zero.json` | GoldSrc |
| Counter-Strike: Source | `eggs/egg-counter-strike-source.json` | Source |
| Counter-Strike: Global Offensive | `eggs/egg-counter-strike-global-offensive.json` | Source |
| Counter-Strike 2 | `eggs/egg-counter-strike-2.json` | Source |
| Minecraft Paper | `eggs/egg-minecraft-paper.json` | Minecraft |
| TeamSpeak-6 Server | `eggs/egg-teamspeak-6-server.json` | Voice |
| Valheim Server | `eggs/egg-valheim-server.json` | SteamCMD |

## Plugins

Source code lives in `plugins/<name>/`, the deploy artifact is `plugins/<name>.zip`.

| Plugin | Purpose | Panel |
|---|---|---|
| [Game Query](plugins/game-query/README.md) | Query players, map and server info | Admin + Server |
| [Mod Manager](plugins/mod-manager/README.md) | Browse and install mods, edit configs and access lists (admins, bans, whitelists) per server, set up per egg (Valheim, Minecraft Paper, Counter-Strike 1.6, Condition Zero, Source, Global Offensive and 2) | Admin + Server |
| [Server Navigation](plugins/server-navigation/README.md) | Order the server sidebar and fold entries into "More", right in the sidebar | Server |
| [Server Resources](plugins/server-resources/README.md) | Resources of host, nodes and servers on the admin dashboard | Admin |

### Installing a plugin

Download `<name>.zip` from the [latest release](https://github.com/Marschux/Pelican-Tools/releases/latest) and upload it under **Admin → Plugins → Import from File**, then install it there. Or copy the folder to `/var/www/pelican/plugins/<name>/` and run:

```bash
cd /var/www/pelican
sudo -u www-data php artisan p:plugin:install <name>
```

After installing or updating, clear the caches and restart PHP-FPM so the new code is loaded (adjust the PHP version):

```bash
sudo -u www-data php artisan optimize:clear
systemctl restart php8.4-fpm
```

Each plugin's README covers what it needs set up in the admin panel.

Updates: every plugin checks the latest release (`update_url` in its `plugin.json`), so the panel offers a new version under **Admin → Plugins** once it is released.

### Building

After every plugin change, bump the version in `plugin.json` and rebuild the ZIP:

```
python scripts/build.py game-query
```

A release carries every ZIP and an `updates.json` with version, download link and SHA-256 per plugin:

```
python scripts/release.py v2026.10.28 <out dir>
gh release create v2026.10.28 plugins/*.zip <out dir>/updates.json
```

## Sources

- [Pelican Project](https://pelican.dev/)
- [Pelican Eggs](https://github.com/pelican-eggs)

## License

[AGPL-3.0](LICENSE), the same license as Pelican itself.

The eggs build on the [Pelican Eggs](https://github.com/pelican-eggs) collection, which is released under the MIT license; its copyright notice applies to the parts taken from there.
