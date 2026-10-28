# Access Lists

Edits a game server's admin, ban and whitelist files from the Pelican panel.

![Access Lists on a Valheim server](../../assets/screenshots/access-lists.png)

## Install

Upload `access-lists.zip` from the [latest release](https://github.com/Marschux/Pelican-Tools/releases/latest) under **Admin → Plugins → Import from File**; updates show up there too. Or copy this folder to `/var/www/pelican/plugins/access-lists/`, then:

```bash
cd /var/www/pelican
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan filament:cache-components
systemctl restart php8.4-fpm   # match your installed php version
```

## Setup

**Admin → Access Lists → Create**, pick a preset and assign the eggs. Presets: Minecraft, Valheim,
TeamSpeak, Counter-Strike 2, Global Offensive, Source, Condition Zero and 1.6. Other games are
added in the same form: a file path, plain text lines or JSON, and for JSON the fields and a
template for new entries (placeholders `{name}`, `{uuid}`, `{ip}`, `{now}`).

## Editing

The server gets an **Access lists** page: pick a list, edit its entries as tags, save. "Edit as
text" shows the raw file. Fields the editor does not show (ban reasons, dates) are kept.

- Minecraft and Counter-Strike rewrite these files themselves while running; edit them with the
  server stopped, or use the game's commands.
- TeamSpeak's lists only control ServerQuery access; client bans live in its database.

## Permissions

Reading needs `file.read-content`, saving `file.update`, creating a missing file `file.create`.
