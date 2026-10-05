# Pelican Eggs & Plugins

Custom and modified eggs plus custom plugins for the Pelican panel.

![Pelican Panel](assets/pelican-panel.png)

## Eggs

All eggs sit flat in `eggs/`.

| Game | Egg file | Server type |
|---|---|---|
| Counter-Strike 1.6 | `eggs/egg-counter-strike-16.json` | Source |
| Counter-Strike: Condition Zero | `eggs/egg-counter-strike-condition-zero.json` | Source |
| Counter-Strike 2 | `eggs/egg-counter-strike-2.json` | Source |
| Minecraft Paper | `eggs/egg-minecraft-paper.json` | Minecraft |
| TeamSpeak-6 Server | `eggs/egg-teamspeak-6-server.json` | Voice |
| Valheim Server | `eggs/egg-valheim-server.json` | SteamCMD |

## Plugins

Source code lives in `plugins/<name>/`, the deploy artifact is `plugins/<name>.zip`.

| Plugin | Purpose | Panel |
|---|---|---|
| Access Lists | Edit a server's admin, ban and whitelist files from the panel | Admin + Server |
| Game Query | Query players, map and server info | Admin + Server |
| Server Navigation | Hide sidebar entries per server | Admin + Server |
| Server Resources | Resources of host, nodes and servers on the admin dashboard | Admin |

After every plugin change, bump the version in `plugin.json` and rebuild the ZIP:

```
python build.py game-query
```

## Sources

- [Pelican Project](https://pelican.dev/)
- [Pelican Eggs](https://github.com/pelican-eggs)
