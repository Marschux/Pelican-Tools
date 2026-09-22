# Pelican Eggs & Plugins

Eigene und angepasste Eggs sowie eigene Plugins fuer das Pelican-Panel.

![Pelican Panel](assets/pelican-panel.png)

## Eggs

Alle Eggs liegen flach in `eggs/`.

| Gamename | Egg-Datei | Serverart |
|---|---|---|
| Counter-Strike 1.6 | `eggs/egg-counter-strike-16.json` | Source |
| Counter-Strike: Condition Zero | `eggs/egg-counter-strike-condition-zero.json` | Source |
| Counter-Strike 2 | `eggs/egg-counter-strike-2.json` | Source |
| Minecraft Paper | `eggs/egg-minecraft-paper.json` | Minecraft |
| TeamSpeak-6 Server | `eggs/egg-teamspeak-6-server.json` | Voice |
| Valheim Server | `eggs/egg-valheim-server.json` | SteamCMD |

## Plugins

Quellcode unter `plugins/<name>/`, das Deploy-Artefakt ist `plugins/<name>.zip`.

| Plugin | Zweck | Panel |
|---|---|---|
| Access Lists | Admin-, Bann- und Whitelist-Dateien eines Servers im Panel bearbeiten | Admin + Server |
| Game Query | Spieler, Map und Serverinfos abfragen | Admin + Server |
| Server Navigation | Seitenleisten-Eintraege pro Server ausblenden | Admin + Server |
| Server Resources | Ressourcen von Host, Nodes und Servern auf dem Admin-Dashboard | Admin |

Nach jeder Plugin-Aenderung Version in `plugin.json` hochzaehlen und die ZIP neu bauen:

```
python build.py game-query
```

## Quellen

- [Pelican Project](https://pelican.dev/)
- [Pelican Eggs](https://github.com/pelican-eggs)
