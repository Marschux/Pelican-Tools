# Access Lists

Edits a game server's admin, ban and whitelist files from the Pelican panel. Files only - there
is no player list and no console command here; that belongs to a query plugin.

## Install

Copy this folder to `/var/www/pelican/plugins/access-lists/`, then:

```bash
cd /var/www/pelican
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan filament:cache-components
systemctl restart php8.4-fpm   # match your installed php version
```

A server only gets the page once an entry under **Access Lists** in the admin panel is linked to
its egg. Nothing is switched on automatically, even when a game's files are there.

## Presets

The admin form offers presets for Minecraft, Valheim, TeamSpeak, Counter-Strike 2, Counter-Strike: Global Offensive, Counter-Strike: Source and Counter-Strike 1.6. Picking one fills the files
with these, each with a hint for whoever edits the list; they can then be removed, changed or added
to:

| Game | Files |
|---|---|
| Minecraft | `whitelist.json`, `ops.json`, `banned-players.json`, `banned-ips.json` |
| Valheim | `savegames/adminlist.txt`, `savegames/bannedlist.txt`, `savegames/permittedlist.txt` |
| TeamSpeak | `query_ip_allowlist.txt`, `query_ip_denylist.txt` |
| Counter-Strike 2 | `game/csgo/cfg/banned_user.cfg`, `game/csgo/cfg/banned_ip.cfg`, `game/csgo/cfg/server.cfg` |
| Counter-Strike: Global Offensive | `csgo/cfg/banned_user.cfg`, `csgo/cfg/banned_ip.cfg`, `csgo/cfg/server.cfg` |
| Counter-Strike: Source | `cstrike/cfg/banned_user.cfg`, `cstrike/cfg/banned_ip.cfg`, `cstrike/cfg/server.cfg` |
| Counter-Strike 1.6 | `cstrike/banned.cfg`, `cstrike/listip.cfg`, `cstrike/server.cfg` |

The TeamSpeak lists only control who may reach ServerQuery. Client bans and server groups live in
its database and are not files, so they are not editable here.

The Counter-Strike lists use a line template (`banid 0 {value}` and `addip 0 {value}`), so the
editor only takes the SteamID or the address. The server rewrites both files itself on `writeid`
and `writeip` - a ban issued in game overwrites what was edited here.


## Configuring a list

One entry per game, holding all of that game's files - Minecraft alone has four, and linking
each of them to the egg separately is busywork. What the plugin needs to know is configuration
rather than code, so a game nobody here has heard of is added in the admin panel, not in a class.

Per entry: a **label** (e.g. "Minecraft"), the **eggs** it belongs to, and its **files**.

Per file:

| Field | Meaning |
|---|---|
| File label | What it is called in the server's editor, e.g. "Admins" |
| File path | Relative to the server directory, e.g. `savegames/adminlist.txt` |
| Format | `Text lines` (one entry per line) or `JSON` (a list of objects) |
| Key field | JSON only: the field that identifies an entry, e.g. `uuid` or `ip` |
| Display field | JSON only: the field shown and typed in the editor, e.g. `name` |
| Template | JSON only: the shape a new entry gets |

Placeholders in the template: `{name}`, `{uuid}`, `{ip}`, `{now}`. When it contains `{uuid}` the
panel looks the uuid up at Mojang before writing - or derives it locally
(`OfflinePlayer:<name>`, uuid v3) when the server's own `server.properties` says
`online-mode=false`, because an offline server uses those and not the real account's uuid.

### Examples

Valheim, `savegames/adminlist.txt`, format `Text lines`. Its ids look like
`Steam_76561197984483021` (`[Platform]_[User ID]`, case sensitive). Comment lines starting with
`//` or `#` are kept when saving.

Minecraft, `whitelist.json`, format `JSON`, key field `uuid`, display field `name`, template:

```json
{"uuid": "{uuid}", "name": "{name}"}
```

Minecraft, `ops.json`, same but with the fields an operator entry carries:

```json
{"uuid": "{uuid}", "name": "{name}", "level": 4, "bypassesPlayerLimit": false}
```

Minecraft, `banned-ips.json`, key field `ip`, display field `ip`:

```json
{"ip": "{ip}", "created": "{now}", "source": "Server", "expires": "forever", "reason": "Banned by an operator."}
```

## Editing

The server gets an **Access lists** page. Pick a list, edit its entries as tags, save. "Edit as
text" shows the file as it is for anything the tag editor cannot express; saving from there only
checks that json still parses.

Entries that stay keep every field they already had - Minecraft stores ban reasons and expiry
dates in the same objects, and rebuilding those from the template would throw them away. A file
that cannot be parsed is never written back over.

While a Minecraft server is running it rewrites these files itself, so changes made here can be
overwritten; edit them with the server stopped, or use the game's own commands.

## Permissions

Reading needs `file.read-content`, saving `file.update`, and creating a list file that does not
exist yet additionally `file.create` - the same permissions the core file manager uses. Every
save is written to the server's activity log as `server:file.write`.

## Notes

The save button is a plain submit button in the page's own view rather than a Filament page
action: page and table actions did not render at all in this panel, and a list editor whose only
button is invisible is worthless. The entries use a tags field for the same reason - its add and
remove controls belong to the field itself.

## Tests

```bash
php test_access_lists.php
```

Checks parsing and rebuilding of both formats (comments kept, unknown fields kept, unreadable
files refused, duplicates, case-insensitive matching), the template placeholders and the offline
uuid derivation. The daemon and the panel are not covered.
