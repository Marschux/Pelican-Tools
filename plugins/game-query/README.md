# Game Query

Queries game servers for player counts, map and server info, and shows them in the Pelican panel.

## Install

Copy this folder to `/var/www/pelican/plugins/game-query/`, then:

```bash
cd /var/www/pelican
php artisan migrate --force
php artisan db:seed --class=Database\Seeders\GameQuerySeeder   # optional, links known eggs
php artisan optimize:clear
php artisan filament:cache-components
systemctl reload php8.3-fpm
```

`optimize:clear` is not optional after an update: newly added config keys read as `null` while
a cached config is in place, and new Filament fields and actions stay invisible until the
component cache is rebuilt.

## Query address

By default queries go to the **node** the server runs on, not to the allocation ip. Docker
publishes the allocation port on the node host, so this also works when the allocation ip is
`0.0.0.0`, `::` or a local address the panel cannot reach directly. Switch to the allocation ip
or its alias in the plugin settings if you need to.

## Protocols

| Type | Games |
|---|---|
| `source` | CS2, CS:GO, CS:S, TF2, Garry's Mod, L4D 1/2, Rust, Valheim, Arma 3, Conan Exiles, Enshrouded, The Forest, Sons of the Forest, Return to Moria |
| `goldsrc` | CS 1.6, Condition Zero, Half-Life Deathmatch |
| `minecraft_java` | Minecraft (query, falling back to ping) |

For Minecraft, set `enable-query=true` and `query-port` to the server port in
`server.properties` - the ping fallback works without it but reports no map and only samples up
to 12 players.

Which fields appear per game is configured per query under **Visible Fields**. A field shows up
only if it is enabled and the protocol actually reported it, so nothing ever reads "unknown".

## Player actions

Kick, ban, whitelist and op are command templates on the game query, not built-in features.
`{player}` is replaced with the player name, so any console command works for any game. They
require the `control.console` permission and are sent through the daemon.

## Notes

The players page is a Filament custom-data table: `records()` returns plain arrays, columns
resolve by array key, and actions receive the row as `array $record`. Sorting and searching
would have to be handled inside the `records()` closure, so they are deliberately not offered
rather than shipped as controls that do nothing.

## Tests

```bash
php test_query_result.php
```

Checks the result mapping: bot subtraction, "not reported" versus zero, and that every field
offered in the admin is resolvable. The socket layer needs a real server and is not covered.
