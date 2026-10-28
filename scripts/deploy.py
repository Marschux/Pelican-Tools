#!/usr/bin/env python3
"""Copies plugins/<name>/ to the testbench panel, clears caches and runs the plugin's self-check.

    python scripts/deploy.py mod-manager

plugin.json stays untouched: overwriting it resets the plugin to "not installed".
"""
import io
import pathlib
import subprocess
import sys
import tarfile

from build import SKIP_DIRS, SKIP_SUFFIX, TEXT_SUFFIX

HOST = 'testbench_3'
VOLUME = '/var/lib/docker/volumes/pelican_pelican-data/_data/plugins'
PANEL = 'cd /opt/pelican && sudo docker compose'


def ssh(command: str, data: bytes = b'') -> None:
    subprocess.run(['ssh', HOST, command], input=data, check=True)


def deploy(name: str) -> None:
    source = pathlib.Path(__file__).parent.parent / 'plugins' / name
    if not source.is_dir():
        sys.exit(f'{source} does not exist')

    tops = sorted(p.name for p in source.iterdir() if p.name != 'plugin.json' and p.suffix not in SKIP_SUFFIX)

    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode='w:gz') as tar:
        for path in sorted(source.rglob('*')):
            rel = path.relative_to(source)
            if not path.is_file() or rel.parts[0] not in tops or SKIP_DIRS & set(rel.parts) or path.suffix in SKIP_SUFFIX:
                continue
            data = path.read_bytes()
            if path.suffix in TEXT_SUFFIX:
                data = data.replace(b'\r\n', b'\n')
            info = tarfile.TarInfo(rel.as_posix())
            info.size = len(data)
            info.mode = 0o644
            tar.addfile(info, io.BytesIO(data))

    target = f'{VOLUME}/{name}'
    # the docker volume is root-only, so everything runs under sudo
    ssh(f'sudo sh -c \'test -f {target}/plugin.json || {{ echo "{name} is not installed on the panel"; exit 1; }}'
        f' && cd {target} && rm -rf {" ".join(tops)}'
        f' && tar xzf - --no-same-owner && chown -R 82:82 .\'', buffer.getvalue())
    print(f'{name}: copied {", ".join(tops)}')

    # views are cached by Laravel, PHP classes by opcache
    ssh(f'{PANEL} exec -T panel php artisan view:clear -q && {PANEL} restart panel')

    for test in source.glob('test_*.php'):
        ssh(f'{PANEL} exec -T -w /var/www/html/plugins/{name} panel'
            f' php -d zend.assertions=1 -d assert.exception=1 {test.name}')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit('usage: python scripts/deploy.py <plugin>')
    try:
        deploy(sys.argv[1])
    except subprocess.CalledProcessError as e:
        sys.exit(f'failed (exit {e.returncode})')
