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
component cache is rebuilt. If FPM keeps serving the old code even after that, the OPcache is
the next thing to clear - restart php-fpm, not just reload it, since reload keeps existing
workers (and their OPcache) running.

## Query address

By default queries go to the **node** the server runs on, not to the allocation ip. Docker
publishes the allocation port on the node host, so this also works when the allocation ip is
`0.0.0.0`, `::` or a local address the panel cannot reach directly. Switch to the allocation ip
or its alias in the plugin settings if you need to.

## Protocols

| Type | Games |
|---|---|
| `source` | CS2, CS:GO, CS:S, TF2, Garry's Mod, L4D 1/2, Rust, Arma 3, Conan Exiles, Enshrouded, The Forest, Sons of the Forest, Return to Moria |
| `goldsrc` | CS 1.6, Condition Zero, Half-Life Deathmatch |
| `minecraft_java` | Minecraft (query, falling back to ping) |
| `valheim` | Valheim - the A2S query plus the world seed |

Which fields are offered for a query depend on its type: each schema declares
`supportedFields()`, and the admin's field list only shows what that protocol can actually
report. Valheim for one has no map, no bots, no VAC and no meaningful "version" - A2S answers
for it, but those particular fields are either always empty or actively misleading, so they are
not offered at all rather than being checkable and useless. Of what is offered, a field only
appears on the server page once the protocol has actually reported it in a given query.

### Valheim world seed

Valheim can neither report its seed over the network nor accept one on the command line - it
only exists inside the world metadata file. The `valheim` type therefore reads it through the
daemon file api, from `savegames/worlds_local`, using the `WORLD` startup variable to find the
world. Both layouts are handled: the pre-1.0 `<world>.fwl` and the 1.0 `<world>/_main.N.fwl2`
folder.

With crossplay enabled Valheim routes everything through the PlayFab relay and never opens the
udp ports locally, so no A2S query can reach it. In that case the schema falls back to reading
the server's own console output through the daemon, where Valheim logs its session state -
player count and server name. Wings caps that at the last 100 lines, so the count is only
available while a session line is still within them, and this route gives no player names.

(Valheim's console also carries a crossplay join code, but on an active server it scrolls out
of that 100-line window within minutes - long before the code itself would next change - so it
was not reliable enough to show and is not read.)

The 1.0 header layout is not documented anywhere, so the parser validates what it reads and
returns nothing when the bytes do not look like a seed. An empty seed field means "could not
read it", never a wrong value.

For Minecraft, set `enable-query=true` and `query-port` to the server port in
`server.properties` - the ping fallback works without it but reports no map and only samples up
to 12 players.

## Connect password

"Password" is always offered under Visible Fields and needs no configuration: GameQuery checks
a fixed, ordered list of startup variable names (`PASSWORD`, `SERVER_PASSWORD`,
`SRV_PASSWORD`, `SV_PASS` - see `GameQuery::PASSWORD_VARIABLES`) and uses the first one that
exists on the egg. It shows the real value, masked until clicked, rather than the generic
yes/no "Password Protected" indicator any A2S game already reports.

That list is deliberately exact rather than a fuzzy "contains PASS" match - several eggs also
expose `RCON_PASSWORD`, `TV_PASSWORD` or `password_rcon`, which are real variables but not the
connect password, and confidently showing the wrong secret under this label would be worse than
showing nothing. A game whose password variable is named something else entirely (rare among
the eggs in this repo) just won't show one - add its name to the list rather than guessing.

Showing it bypasses no permission: it reads the same `user_viewable` flag the core Startup tab
already respects, and additionally requires the viewer to hold the `startup.read` permission -
exactly the two checks the Startup tab itself makes before showing any variable. A variable an
admin has hidden from subusers there stays hidden here too.

## Notes

The query for a server is looked up with `GameQuery::forServer()`, a plain join over the pivot.
Registering a relation on the core `Egg` model at boot was the alternative, but when such a
registration does not take effect everything silently behaves as if no query were configured -
which is indistinguishable from the plugin not being installed.

The server console page renders a fixed core widget list plus whatever was registered through
`Console::registerCustomWidgets()` at one of its positions (`Top`, `AboveConsole`,
`BelowConsole`, `Bottom`). Discovering a widget through `discoverWidgets()` alone makes it known
to the panel but places it nowhere, so the widget is registered explicitly in the plugin's
`boot()`, at `AboveConsole` - right after the core overview blocks.

## Tests

```bash
php test_query_result.php
```

Checks the result mapping: bot subtraction, "not reported" versus zero, name markup stripping,
the seed file parser (including the malformed and truncated cases) and the Valheim console log
parser. The socket layer needs a real server and is not covered.
