# Mod Manager

A "Mods" page on the server: browse a mod site for the server's game, install with one click,
update, switch off or remove later. Wings does the downloading, unpacking and moving; nothing
passes through the panel. Eggs are not changed.

Built in: Valheim (Thunderstore + BepInEx), Minecraft Paper (plugins from Modrinth and Hangar,
datapacks and resource packs from Modrinth), Counter-Strike 1.6 and Condition Zero (Metamod-R +
AMX Mod X, plugins uploaded, maps from GameBanana).

## Install

Copy this folder to `/var/www/pelican/plugins/mod-manager/`, then:

```bash
cd /var/www/pelican
sudo -u www-data php artisan p:plugin:install mod-manager
```

## Setup

**Admin → Mod profiles → Create.** Pick a preset, which fills in everything, and assign the eggs.
Servers with those eggs get the Mods page. An egg can have one profile.

| Field | Valheim | Minecraft Paper | Counter-Strike 1.6 |
|---|---|---|---|
| Sources | Thunderstore, community `valheim` | Modrinth (loaders `paper`, `spigot`, `bukkit`) and Hangar | GameBanana, game `4254`, category `5474` (maps) |
| Mods folder | `BepInEx/plugins` (each mod in its own folder `Namespace-Name`) | `plugins` (one jar per plugin) | `cstrike/addons/amxmodx/plugins` |
| Config folder | `BepInEx/config` | none - plugins write to `plugins/<Name>/` | `cstrike/addons/amxmodx/configs` |
| Loader | BepInEx, package `denikson-BepInExPack_Valheim`, check path `BepInEx/core` | none | type Metamod-R + AMX Mod X, game folder `cstrike` |
| Start command prefix | the doorstop variables BepInEx needs | none | none - `liblist.gam` loads Metamod |
| Game version | - | `.paper/version_history.json`, before the first start the egg variable `PAPER_VERSION` | - |
| Hidden packages | the loader itself and r2modman | - | the loader |

Condition Zero is the Counter-Strike 1.6 preset in the game folder `czero` (mods, configs and
check path below it), GameBanana game `7`, category `5181`.

## The page

**Loader row** (only with a loader). Shows whether the loader is installed and the server starts
through it. The button installs or updates it:
- BepInEx: the pack goes to the server root (existing BepInEx configs stay), and only *this
  server's* start command gets the prefix - the egg stays as it is.
- Metamod-R + AMX Mod X: Metamod-R from its GitHub releases and AMX Mod X 1.10 (base and
  cstrike) are unpacked into `cstrike/addons/`, without AMX Mod X's scripting folder. Existing
  `.ini`/`.cfg` stay. `liblist.gam` loads `addons/metamod/metamod.so` - put there under this name,
  as current HLDS does not load a game library with `_i386` in its name - and Metamod's
  `plugins.ini` loads AMX Mod X. Removing it points `liblist.gam` back to `dlls/cs.so` and leaves
  configs, plugins and data. A `validate` of steamcmd restores `liblist.gam`; the row then says so
  and its button hooks Metamod in again.

**Browse.** Search while typing, category, sorting, pages of 12. Filters are remembered per
server. A tile opens the details: dependencies, all versions (each installable on its own),
description and changelog.
- Thunderstore: client-only and deprecated mods are hidden by default.
- With a game version, only what fits it is shown; a box shows everything.
- Several sources: a switch above the search, each with its own categories. For Paper, Modrinth
  has a second switch: Plugins, Datapacks, Resource packs.
- GameBanana: its lists have no download counts, so tiles show views and "most viewed" sorts by
  them. A typed search goes through the site search, which cannot be limited to a category: the
  first ~100 hits are filtered to the profile's category. A submission's files are its versions.

**Installing.** Required dependencies are listed first and installed along on confirm, optional
ones can be ticked. The loader is never pulled in as a dependency - it has its own button.
Without a version picked, the newest one for the server's game version is installed.

| What | Goes to |
|---|---|
| Thunderstore package | `BepInEx/plugins/<Namespace-Name>/`, its `patchers/`, `monomod/` and `config/` into BepInEx's folders, like r2modman |
| Plugin | `plugins/<file>.jar` |
| Datapack | `<world>/datapacks/<file>.zip`, the world named by `level-name` in `server.properties` |
| Resource pack | nothing on the server: `resource-pack` and `resource-pack-sha1` in `server.properties` point to Modrinth, players are offered it on join. One at a time - a new one switches the one before off |
| Map (GameBanana) | unpacked on the server (zip, rar, 7z) and sorted into `cstrike/`: `maps/`, `sound/`, `gfx/` ... from wherever they sit in the archive, loose `.bsp`/`.wad`/`.wav`/... by type. Files already there stay; one another installed map brought (a shared `.wad`) is both maps', and removing either leaves it while the other is there. A `.bsp` that is there already and not from this page stops it. A new map goes into `mapcycle.txt` |

**AMX Mod X plugins** (Counter-Strike 1.6). AMX Mod X has no site to browse:
- Its own plugins, as listed in `plugins.ini`, have a section with a switch each (the line
  commented out or back in), grouped by the comment lines of the file. Deleting one removes file
  and line; after a loader update the file is back, without a line, so it stays off.
- "Upload plugin or zip" takes a compiled `.amxx`, or a zip of plugins with what they bring along:
  `addons/amxmodx/{plugins,configs,data,modules}` and game folders from wherever they sit in the
  zip, loose `.amxx`/`.cfg`/`.ini`/`lang/*.txt` into AMX Mod X's folders. Source code (`.sma`) is
  left out, a zip with nothing but source code is refused - compile it first. Uploaded plugins get
  their lines in `configs/plugins-modmanager.ini` (AMX Mod X reads every `plugins-*.ini`), so
  `plugins.ini` stays as the admin keeps it. Configs already there are not overwritten.
- `.amxx` files in the plugins folder that no `plugins*.ini` names are not loaded; they are listed
  and can be taken over (registered) or deleted.

**Installed.**
- Update hints, per mod and "update all". A version picked by hand is pinned and left out of
  "update all" until it is updated by hand.
- Switch off: the files move next to their folder (`BepInEx/plugins_disabled/`,
  `plugins_disabled/`, `world/datapacks_disabled/`), where the game does not look. A resource
  pack switched off empties the two lines in `server.properties`. An uploaded AMX Mod X plugin
  stays where it is, its line is commented out. A map's switch takes it out of `mapcycle.txt`.
- Remove: asks first and says which mods need it. Offers to also delete the configs a mod wrote,
  for a plugin its data folder `plugins/<Name>/` - off by default.
- Installed by hand: a Thunderstore folder `Namespace-Name` with a manifest, or a jar Modrinth
  (SHA1) or Hangar (SHA256) knows, can be taken over; anything there can be deleted.
- Configs: the gear next to a mod opens its config in an editor (for a plugin
  `plugins/<Name>/config.yml` first; for the AMX Mod X loader `amxx.cfg`), the "Configs" section
  lists all. Deleting one of AMX Mod X's own configs (`plugins.ini`, `users.ini`, ...) or a
  `plugins-*.ini` asks with a warning - AMX Mod X does not write them again.
- Mod list for players: names, versions and links to copy.

After a change a banner offers to restart the server.

## Permissions

| Action | Needs |
|---|---|
| See the page | file read |
| Install, upload | file create |
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
- Uploads go from the browser straight to Wings, like Pelican's file manager; Wings' `upload_limit`
  (100 MB by default) applies, not PHP's.
- Uploaded AMX Mod X plugins load after those of `plugins.ini`, in the order they were uploaded. A
  plugin that needs a library plugin uploaded after it: move the library's line up in
  `plugins-modmanager.ini` with the config editor.

## Check

```bash
php test_mod_manager.php
```
