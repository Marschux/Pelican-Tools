# Mod Manager

A "Mods" page on the server: browse a mod site for the server's game, install with one click,
update, switch off or remove later. Wings does the downloading, unpacking and moving; nothing
passes through the panel. Eggs are not changed.

Built in: Valheim (Thunderstore + BepInEx), Minecraft Paper (plugins from Modrinth and Hangar,
datapacks and resource packs from Modrinth), Counter-Strike 1.6 and Condition Zero (Metamod-R +
AMX Mod X, plugins uploaded or from a GitHub release, maps from GameBanana), Counter-Strike: Source and
Global Offensive (Metamod:Source + SourceMod, plugins uploaded or from a GitHub release, maps from GameBanana), Counter-Strike 2 (Metamod:Source +
CounterStrikeSharp, plugins uploaded or from a GitHub release, maps from the Steam Workshop).

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

Counter-Strike: Source: source GameBanana, game `2`, category `5535` (maps), game folder `cstrike`,
mods folder `cstrike/addons/sourcemod/plugins`, config folder `cstrike/addons/sourcemod/configs`,
loader type Metamod:Source + SourceMod. The server runs 32-bit, as the egg of this repository
starts it.

Counter-Strike: Global Offensive (the legacy server, app 740): like Counter-Strike: Source with the
game folder `csgo`, GameBanana game `4660`, category `5413`. The rotation is a mapgroup, not
`mapcycle.txt`: the plugin keeps its own group `mg_modmanager` in `csgo/gamemodes_server.txt` and
sets the startup variable `MAPGROUP` to it. The group starts with the maps of `mg_active`; other
groups in the file stay as they are. The egg of this repository removes the bundled
`bin/libgcc_s.so.1` on start, which is too old for the image's `libstdc++`.

Counter-Strike 2: source Steam Workshop (maps; browsing needs a Steam Web API key from
steamcommunity.com/dev/apikey in the server's startup variable `WORKSHOP_AUTHKEY`, without one
maps are added by id), game folder `game/csgo`, mods folder
`game/csgo/addons/counterstrikesharp/plugins`, config folder `…/counterstrikesharp/configs`,
loader type Metamod:Source + CounterStrikeSharp. It needs the Counter-Strike 2 egg of this
repository: its `start.sh` lists Metamod in `gameinfo.gi` on every start (game updates overwrite
the file) and starts a workshop map after a stock map.

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

**Engine row** (Counter-Strike 1.6 and Condition Zero, optional). ReHLDS, ReGameDLL and the ReAPI
module of AMX Mod X, newest releases from GitHub - many newer AMX Mod X plugins need them. ReHLDS
is built against the Steam API of Valve's `steam_legacy` branch, which today's HLDS no longer
ships, so its `libsteam_api.so` comes along from the ReHLDS repository at the same release; the egg
stays as it is. Valve's files are moved to `.mod-manager/valve/` and come back when the engine is
removed. A `validate` of steamcmd (reinstalling the server) puts Valve's files back as well - install
the engine again afterwards.

**Browse.** One tab per source, named after it and what it offers ("GameBanana Maps",
"Thunderstore Mods"); Modrinth gets a tab per kind (plugins, datapacks, resource packs). Search while typing, category, sorting, pages of 12. Filters are remembered per
server. A tile opens the details: dependencies, all versions (each installable on its own),
description and changelog.
- Thunderstore: client-only and deprecated mods are hidden by default.
- With a game version, only what fits it is shown; a box shows everything.
- Several sources: a switch above the search, each with its own categories. For Paper, Modrinth
  has a second switch: Plugins, Datapacks, Resource packs.
- GameBanana: its lists have no download counts, so tiles show views and "most viewed" sorts by
  them. A typed search goes through the site search, which cannot be limited to a category: the
  first ~100 hits are filtered to the profile's category. A submission's files are its versions, named by the day they were uploaded (GameBanana has no version numbers); workshop maps show "Latest", the game keeps them up to date.

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
- A plugin on GitHub: paste the link in the tab "Add by link"; the release's `.amxx` or zip goes in
  like an upload and gets update hints from new releases.
- `.amxx` files in the plugins folder that no `plugins*.ini` names are not loaded; they are listed
  and can be taken over (registered) or deleted.

**Counter-Strike: Source.** The loader is Metamod:Source 1.12 and SourceMod 1.12 (AlliedModders
stable builds), one button for both; Metamod's `addons/metamod.vdf` makes the engine load it.
Updating keeps configs already on the server; removing it keeps `data/` and `cfg/sourcemod/`.
- SourceMod's own plugins (and `.smx` put there by hand) have a section with a switch each: off
  moves the file to `plugins/disabled/`, where SourceMod does not look. A loader update puts each new
  version where the old one is, so on stays on, off stays off. Deleting one SourceMod comes with
  brings it back on the next loader update, switched on - the page says so before deleting.
- Plugins: "Upload plugin or zip" takes a `.smx` or a zip, or paste a GitHub link in the tab "Add by
  link". `addons/sourcemod/{plugins,configs,data,gamedata,translations,extensions}`, `cfg/` and game
  folders (`sound/`, `models/`, `materials/`, ...) count from wherever they sit in the zip; source code (`.sp`) is left out. Switching off moves the `.smx`
  to `plugins/disabled/`.
- Maps from GameBanana: `maps/`, `materials/`, `models/`, `sound/` and `resource/` from wherever they
  sit in the archive, loose `.bsp`/`.nav` into `maps/`. The switch of a map puts it in or out of
  `cfg/mapcycle.txt`; the first time, that file starts as a copy of the game's `mapcycle_default.txt`.

**Counter-Strike 2.** The loader is Metamod:Source 2.0 (AlliedModders build drop) and
CounterStrikeSharp with its .NET runtime (GitHub), one button for both; the server picks it up on
its next start. Removing it keeps `configs/`, `plugins/` and `logs/`.
- Plugins: "Upload plugin or zip" takes a `.zip` or a `.dll`, or paste a GitHub link in the tab
  "Add by link" - a release with several zips asks which one. A plugin is a folder with a `.dll` of the same
  name; `addons/`, `counterstrikesharp/`, `plugins/`, `configs/`, `shared/`, `gamedata/` and `cfg/`
  count from wherever they sit in the zip. A plugin from GitHub gets update hints from new releases.
  Switching off moves the plugin folder to `plugins_disabled`, its configs stay editable.
- Workshop maps: paste an id or a workshop link in the tab "Add by link", or - with the key - pick one in the tab "Steam
  Workshop Maps" (search, game mode, sort). Title and picture come from Steam, nothing is
  downloaded by the panel. The map the server starts on is the startup variable `WORKSHOP_MAP`.
  Downloading needs a Game Server Login Token (`STEAM_ACC`, App
  730) - the page says so when it is missing.
- Changing between workshop maps needs a map vote plugin, e.g. RockTheVote (`Oz-Lin/cs2-rockthevote`),
  installed by its GitHub link. With it installed, the switch of a map puts it in or out of a block
  of RockTheVote's `maplist.txt`; lines written there by hand stay.

**Installed.**
- Updates: a blue arrow button left of the switch, per mod, and "update all"; the status stays "Active". A version picked by hand is pinned and left out of
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
