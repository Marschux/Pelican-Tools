# Mod Manager

A "Mods" page on the server: browse a mod site for the server's game, install with one click,
update, switch off or remove later. Wings does the downloading, unpacking and moving; nothing
passes through the panel. Eggs are not changed.

Built in: Valheim (Thunderstore + BepInEx) and Minecraft Paper (plugins from Modrinth and Hangar,
datapacks and resource packs from Modrinth).

## Install

Copy this folder to `/var/www/pelican/plugins/mod-manager/`, then:

```bash
cd /var/www/pelican
sudo -u www-data php artisan p:plugin:install mod-manager
```

## Setup

**Admin → Mod profiles → Create.** Pick a preset, which fills in everything, and assign the eggs.
Servers with those eggs get the Mods page. An egg can have one profile.

| Field | Valheim | Minecraft Paper |
|---|---|---|
| Sources | Thunderstore, community `valheim` | Modrinth (loaders `paper`, `spigot`, `bukkit`) and Hangar |
| Mods folder | `BepInEx/plugins` (each mod in its own folder `Namespace-Name`) | `plugins` (one jar per plugin) |
| Config folder | `BepInEx/config` | none - plugins write to `plugins/<Name>/` |
| Loader | BepInEx, package `denikson-BepInExPack_Valheim`, check path `BepInEx/core` | none |
| Start command prefix | the doorstop variables BepInEx needs | none |
| Game version | - | `.paper/version_history.json`, before the first start the egg variable `PAPER_VERSION` |
| Hidden packages | the loader itself and r2modman | - |

## The page

**Loader bar** (only with a loader). Shows whether BepInEx is installed and the server starts
through it. The button installs or updates it: the pack goes to the server root (existing BepInEx
configs stay), and only *this server's* start command gets the prefix - the egg stays as it is.

**Browse.** Search while typing, category, sorting, pages of 12. Filters are remembered per
server. A tile opens the details: dependencies, all versions (each installable on its own),
description and changelog.
- Thunderstore: client-only and deprecated mods are hidden by default.
- With a game version, only what fits it is shown; a box shows everything.
- Several sources: a switch above the search, each with its own categories. For Paper, Modrinth
  has a second switch: Plugins, Datapacks, Resource packs.

**Installing.** Required dependencies are listed first and installed along on confirm, optional
ones can be ticked. The loader is never pulled in as a dependency - it has its own button.
Without a version picked, the newest one for the server's game version is installed.

| What | Goes to |
|---|---|
| Thunderstore package | `BepInEx/plugins/<Namespace-Name>/`, its `patchers/`, `monomod/` and `config/` into BepInEx's folders, like r2modman |
| Plugin | `plugins/<file>.jar` |
| Datapack | `<world>/datapacks/<file>.zip`, the world named by `level-name` in `server.properties` |
| Resource pack | nothing on the server: `resource-pack` and `resource-pack-sha1` in `server.properties` point to Modrinth, players are offered it on join. One at a time - a new one switches the one before off |

**Installed.**
- Update hints, per mod and "update all". A version picked by hand is pinned and left out of
  "update all" until it is updated by hand.
- Switch off: the files move next to their folder (`BepInEx/plugins_disabled/`,
  `plugins_disabled/`, `world/datapacks_disabled/`), where the game does not look. A resource
  pack switched off empties the two lines in `server.properties`.
- Remove: asks first and says which mods need it. Offers to also delete the configs a mod wrote,
  for a plugin its data folder `plugins/<Name>/` - off by default.
- Installed by hand: a Thunderstore folder `Namespace-Name` with a manifest, or a jar Modrinth
  (SHA1) or Hangar (SHA256) knows, can be taken over; anything there can be deleted.
- Configs: the gear next to a mod opens its config in an editor (for a plugin
  `plugins/<Name>/config.yml` first), the "Configs" section lists all.
- Mod list for players: names, versions and links to copy.

After a change a banner offers to restart the server.

## Permissions

| Action | Needs |
|---|---|
| See the page | file read |
| Install | file create |
| Update, switch on/off, take over, save a config | file update |
| Remove | file delete |
| Open a config | file read content |
| Install the loader | file create + startup update (it changes the start command) |
| Restart button | control restart |

## Limits

- Downloads run inside the click; a very large mod can hit the web server's timeout.
- Wings cannot unpack every archive format (e.g. Deflate64 zips); that shows as an error.
- Hangar plugins only offered as a link to another site cannot be installed, the error says where
  to get them. Dependencies outside Hangar are only listed.
- Recognising hand-installed jars reads each jar through the panel once (Wings has no checksum
  call), up to 64 MB.

## Check

```bash
php test_mod_manager.php
```
