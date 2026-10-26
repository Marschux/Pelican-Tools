# Mod Manager

A "Mods" page on the server: browse a mod site for the server's game, install with one click,
update, switch off or remove later. Wings does the downloading, unpacking and moving; nothing
passes through the panel. Eggs are not changed.

So far for Valheim (Thunderstore + BepInEx). Other games get their own preset.

## Install

Copy this folder to `/var/www/pelican/plugins/mod-manager/`, then:

```bash
cd /var/www/pelican
sudo -u www-data php artisan p:plugin:install mod-manager
```

## Setup

**Admin → Mod profiles → Create.** Pick the preset "Valheim", which fills in everything, and
assign the eggs. Servers with those eggs get the Mods page. An egg can have one profile.

| Field | Valheim |
|---|---|
| Source / community | Thunderstore, `valheim` |
| Mods folder | `BepInEx/plugins` (each mod in its own folder `Namespace-Name`) |
| Config folder | `BepInEx/config` |
| Loader | BepInEx, package `denikson-BepInExPack_Valheim`, check path `BepInEx/core` |
| Start command prefix | the doorstop variables BepInEx needs |
| Hidden packages | the loader itself and r2modman |

## The page

**Loader bar.** Shows whether BepInEx is installed and the server starts through it. The button
installs or updates it: the pack goes to the server root (existing BepInEx configs stay), and
only *this server's* start command gets the prefix - the egg stays as it is.

**Browse.** Search while typing, category, sorting, pages of 20. Client-only mods are hidden by
default, deprecated ones too. Filters are remembered per server. A tile opens the details:
dependencies, all versions (each installable on its own), description and changelog.

**Installing.** A mod with dependencies lists them first and installs them along on confirm.
The loader is never pulled in as a dependency - it has its own button.

**Installed.**
- Update hints, per mod and "update all". A version picked by hand is pinned and left out of
  "update all" until it is updated by hand.
- Switch off: the mod moves to `BepInEx/plugins_disabled/`, where BepInEx does not look.
- Remove: asks first, and offers to remove dependencies nothing else needs any more.
- Mods installed by hand or with r2modman are listed separately. A `Namespace-Name` folder with a
  manifest can be taken over, anything there can be deleted.
- Configs: the gear next to a mod opens its config in an editor, the "Configs" section lists all.
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
- A package is unpacked whole into its own folder. `config/` and `patchers/` folders inside a
  package are not moved to BepInEx's own folders.
- Wings cannot unpack every archive format (e.g. Deflate64 zips); that shows as an error.

## Check

```bash
php test_mod_manager.php
```
