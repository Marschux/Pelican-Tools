# Game Query

Queries game servers for players, map and server info and shows them in the Pelican panel: a
widget on the console page and a players column in the server list.

## Install

Upload `game-query.zip` from the [latest release](https://github.com/Marschux/Pelican-Tools/releases/latest) under **Admin → Plugins → Import from File**; updates show up there too. Or copy this folder to `/var/www/pelican/plugins/game-query/`, then:

```bash
cd /var/www/pelican
php artisan migrate --force
php artisan optimize:clear
php artisan filament:cache-components
systemctl restart php8.3-fpm   # match your installed php version
```

## Setup

**Admin → Game Queries → Create**, pick the type and assign the eggs.

| Type | Games |
|---|---|
| `source` | CS2, CS:GO, CS:S, TF2, Garry's Mod, L4D 1/2, Rust, Arma 3, Conan Exiles, Enshrouded, Return to Moria |
| `goldsrc` | CS 1.6, Condition Zero, Half-Life Deathmatch |
| `minecraft_java` | Minecraft |
| `valheim` | Valheim, with world name and seed |

- Minecraft: set `enable-query=true` and `query-port` in `server.properties` for the full player list.
- Valheim with crossplay: only player count and name, read from the console.
- The join password is shown (masked) to users who may see the startup variables.

## Options

Queries go to the node's address by default. Change it in the panel's `.env`, then run
`php artisan optimize:clear`:

| Variable | Default | |
|---|---|---|
| `GAME_QUERY_ADDRESS_SOURCE` | `node` | `node`, `allocation` or `alias` |
| `GAME_QUERY_TIMEOUT` | `3` | seconds to wait for an answer |
| `GAME_QUERY_CACHE_TTL` | `15` | seconds a result is reused, `0` disables the cache |
| `GAME_QUERY_DEBUG` | `false` | log failed queries |
