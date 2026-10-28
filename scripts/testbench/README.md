# Testbench helpers

- `profile.php <uuid_short>` - mount + render of the Mods page, every outgoing HTTP call with its time.
  `ssh testbench_3 'cd /opt/pelican && sudo docker compose exec -T panel sh -c "cat > /tmp/p.php && php /tmp/p.php 235c0f85"' < scripts/testbench/profile.php`
- `rows.py <password> <uuid_short>...` - tab labels / first column of the installed table, as text
- `shot.py <password> <uuid_short> [tab index]` - full-page screenshot to upd.png (only when the look matters)
- Restart a server: `php artisan tinker --execute="app(\App\Repositories\Daemon\DaemonServerRepository::class)->setServer(\App\Models\Server::where('uuid_short','<id>')->first())->power('restart');"`
- Server log, filtered: `sudo docker logs --since 5m <uuid> 2>&1 | sed "s/\x1b\[[0-9;]*m//g" | grep -aiE "<pattern>" | tail`
