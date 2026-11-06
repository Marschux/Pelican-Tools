# Game Manager - Notes

Per-game details and gotchas that are not obvious from the code. Usage is in the [README](README.md).

## Principles

- A game comes in through a **mod profile** (preset, adjustable) linked to eggs
- Files go through Wings (pull, decompress, move), never through PHP
- Filament actions do not render in this panel, so the page uses its own Blade modals

## Valheim (Thunderstore/Hexium + BepInEx)

- BepInEx starts through the server's startup command (Doorstop variables in front), the egg stays as it is
- Disabling moves a path to its `*_disabled` sibling; hand-installed mods are matched through their `manifest.json`

## Counter-Strike 1.6 / Condition Zero (Metamod-R + AMX Mod X)

- HLDS does not load a game DLL with `_i386` in its name, so Metamod is placed as `metamod.so` and `liblist.gam` is switched over
- Own plugins go to `configs/plugins-modmanager.ini` (AMXX reads every `plugins-*.ini`), `plugins.ini` stays the admin's
- Wings unpacks zip, rar and 7z and follows GitHub redirects
- Map archives have no fixed shape: they are cut down to known folders and lowercased, existing files are not overwritten; a `.wad` shipped by two maps belongs to both
- ReHLDS 3.15 needs the old `libsteam_api.so` (in the ReHLDS repo) - shipped with it, it also runs on the current HLDS branch
- Uploads go from the browser straight to Wings (signed URL, `.game-manager/upload/<token>`), Wings' `upload_limit` applies

## Counter-Strike: Source (Metamod:Source + SourceMod)

- The server runs 32-bit (`srcds_linux`, no 64-bit binary), Metamod 1.12 loads through `addons/metamod.vdf` - no change to the egg
- SourceMod ignores `plugins/disabled/`, so off = moved there. The loader install lists all bundled `.smx` in its files, so they do not count as "known" in the plugin list
- A loader update puts each `.smx` where the old one is (on/off is kept)
- The map cycle is `cfg/mapcycle.txt`; out of the box only `mapcycle_default.txt` exists, so the first write copies it, otherwise only the new map would be in the cycle
- AlliedModders blocks automated downloads (Cloudflare 403): plugins come only by upload or GitHub link, there is no browse tab. No workshop for CS:S

## Counter-Strike: Global Offensive (Metamod:Source + SourceMod)

- The legacy server (app 740) runs 32-bit; Metamod's `metamod_x64.vdf` reports "wrong ELF class", harmless
- The first start failed on `libtier0.so` (`bin/libgcc_s.so.1` too old) - the egg's startup command begins with `rm -f bin/libgcc_s.so.1 &&` (the image runs the startup through `eval`); no longer reproducible, kept as a known fix
- Rotation = map group: own group `mg_modmanager` in `csgo/gamemodes_server.txt`, seeded with the maps of `mg_active`, `MAPGROUP` is set to it; checked with `print_mapgroup_sv` and `changelevel`. Hand-written map groups stay
- Detection: `source1` loader + game folder `csgo` (no profile setting of its own)
- No workshop (the CS:GO workshop is gone), maps only from GameBanana

## Counter-Strike 2 (Metamod:Source + CounterStrikeSharp)

- Needs the CS2 egg from this repo: its `start.sh` adds Metamod to `gameinfo.gi` on every start (updates overwrite the file) - the panel does not see the start
- Metamod: `mms.alliedmods.net/mmsdrop/2.0/mmsource-latest-linux` names the tarball; CSS: GitHub asset `counterstrikesharp-with-runtime-linux-*.zip` (430 files, .NET included - do not filter out `.dll`)
- Plugin = folder with a `.dll` of the same name; off = folder moved to `plugins_disabled`. GitHub package id is `github:<repo>@<asset without version>`, so updates find the same asset
- Workshop downloads need the Steam login (GSLT in `STEAM_ACC`), which only happens when a map loads, so `start.sh` starts with `+map <SRCDS_MAP> +host_workshop_map <id>`; `+host_workshop_map` alone stays "Idle"
- Workshop details (`GetPublishedFileDetails`) need no key, search (`QueryFiles`) needs a Steam Web API key from `WORKSHOP_AUTHKEY`; tags: `Map`, `Cs2`, modes `Classic`, `Deathmatch`, `Wingman`, `Armsrace`, `Custom`
- RockTheVote (fork `Oz-Lin/cs2-rockthevote`) reads `maplist.txt` from its **plugin folder** and does not load without it; workshop maps as `name:id`, `//` are comments. The Game Manager keeps its own block there
- The `steamclient.so` dlopen message and `S_API FAIL` in the log are normal in the container

## Access lists

- Formerly the access-lists plugin (removed in 1.1.0). Migration 006 copied its tables, 007 moved them into `mod_profiles.settings.lists` and dropped them
- Pelican's plugin uninstall rolls a plugin's migrations back and drops its tables: data has to be copied before, and migration file names must not clash with another plugin's
