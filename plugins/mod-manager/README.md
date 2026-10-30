# Mod Manager

A "Mods & Configs" page on the server: browse a mod site for the server's game, install with
one click, update, switch off or remove later, and edit its configs and access lists (admins, bans,
whitelists, server.cfg). Wings does the downloading and unpacking; eggs are not changed.

| Game | Loader | Sources |
|---|---|---|
| Valheim | BepInEx | Thunderstore |
| Minecraft Paper | - | Modrinth (plugins, datapacks, resource packs), Hangar |
| Counter-Strike 1.6, Condition Zero | Metamod-R + AMX Mod X | GameBanana maps, maps uploaded |
| Counter-Strike: Source, Global Offensive | Metamod:Source + SourceMod | GameBanana maps, plugins uploaded or from GitHub |
| Counter-Strike 2 | Metamod:Source + CounterStrikeSharp | Steam Workshop maps, plugins uploaded or from GitHub |

![Mod Manager, installed mods](../../assets/screenshots/mod-manager-installed.png)

![Mod Manager, browsing Thunderstore](../../assets/screenshots/mod-manager-browse.png)

## Install

Upload `mod-manager.zip` from the [latest release](https://github.com/Marschux/Pelican-Tools/releases/latest) under **Admin → Plugins → Import from File**; updates show up there too. Or copy this folder to `/var/www/pelican/plugins/mod-manager/`, then:

```bash
cd /var/www/pelican
sudo -u www-data php artisan p:plugin:install mod-manager
```

## Setup

**Admin → Mod profiles → Create**, pick a preset and assign the eggs. Servers with those eggs get
the Mods & Configs page.

**Admin → Access Lists → Create** does the same for the files of the Configs tab: a file path, plain
text lines, `key = value` settings or JSON. A game without a mod profile (TeamSpeak) gets an
Access Lists page of its own.

Coming from the Access Lists plugin: update Mod Manager first - it copies the lists over - then
uninstall Access Lists. Uninstalling it first deletes its lists.

- Counter-Strike: Global Offensive and 2 need the eggs of this repository.
- Counter-Strike 2: workshop maps need a Game Server Login Token (`STEAM_ACC`); browsing them needs
  a Steam Web API key in `WORKSHOP_AUTHKEY`, without one maps are added by id. Switching maps in
  game needs a map vote plugin such as RockTheVote.

## Using it

- **Loader:** one button installs or updates it; configs already on the server stay.
- **Browse:** search, categories, sorting; a tile shows versions, dependencies and changelog.
  Required dependencies are installed along.
- **Installed:** mods, plugins and maps; update (one or all), switch off, remove. Mods installed by
  hand can be taken over. After a change a banner offers to restart the server.
- **Configs:** the access lists as tags or settings, or as plain text; the configs the mods wrote.
  Minecraft and Counter-Strike rewrite some of these files while running - edit them with the
  server stopped, or use the game's commands.

## Permissions

| Action | Needs |
|---|---|
| See the page | file read |
| Open a config or access list | file read content |
| Install, upload | file create |
| Update, switch, save a config or list | file update |
| Remove | file delete |
| Install the loader | file create + startup update |

## Limits

- A very large download can hit the web server's timeout.
- Uploads are limited by Wings' `upload_limit` (100 MB by default).
- Wings cannot unpack every archive (e.g. Deflate64 zips).
