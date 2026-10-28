# Mod Manager

A "Mods" page on the server: browse a mod site for the server's game, install with one click,
update, switch off or remove later. Wings does the downloading and unpacking; eggs are not changed.

| Game | Loader | Sources |
|---|---|---|
| Valheim | BepInEx | Thunderstore |
| Minecraft Paper | - | Modrinth (plugins, datapacks, resource packs), Hangar |
| Counter-Strike 1.6, Condition Zero | Metamod-R + AMX Mod X | GameBanana maps, plugins uploaded or from GitHub |
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
the Mods page.

- Counter-Strike: Global Offensive and 2 need the eggs of this repository.
- Counter-Strike 2: workshop maps need a Game Server Login Token (`STEAM_ACC`); browsing them needs
  a Steam Web API key in `WORKSHOP_AUTHKEY`, without one maps are added by id. Switching maps in
  game needs a map vote plugin such as RockTheVote.

## Using it

- **Loader:** one button installs or updates it; configs already on the server stay.
- **Browse:** search, categories, sorting; a tile shows versions, dependencies and changelog.
  Required dependencies are installed along.
- **Installed:** update (one or all), switch off, remove, edit configs. Mods installed by hand can
  be taken over. After a change a banner offers to restart the server.

## Permissions

| Action | Needs |
|---|---|
| See the page, open a config | file read |
| Install, upload | file create |
| Update, switch, save a config | file update |
| Remove | file delete |
| Install the loader | file create + startup update |

## Limits

- A very large download can hit the web server's timeout.
- Uploads are limited by Wings' `upload_limit` (100 MB by default).
- Wings cannot unpack every archive (e.g. Deflate64 zips).
