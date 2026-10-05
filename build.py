#!/usr/bin/env python3
"""Rebuilds plugins/<name>.zip from plugins/<name>/.

The ZIP is the deploy artifact and is checked in. If it drifts from the source, you upload
an old version and then hunt for the bug in the code - exactly what happened with the
predecessor plugin. So always build through this script, never by hand.

    python build.py game-query
"""
import json
import pathlib
import sys
import zipfile

SKIP_DIRS = {'.git', '__pycache__', 'node_modules', 'vendor'}
SKIP_SUFFIX = {'.zip', '.pyc'}


def build(name: str) -> None:
    root = pathlib.Path(__file__).parent / "plugins"
    source = root / name

    if not source.is_dir():
        sys.exit(f'{source} does not exist')

    version = json.loads((source / 'plugin.json').read_text(encoding='utf-8'))['version']
    target = root / f'{name}.zip'

    files = sorted(
        p for p in source.rglob('*')
        if p.is_file()
        and p.suffix not in SKIP_SUFFIX
        and not SKIP_DIRS & set(p.relative_to(source).parts)
    )

    # write deterministically, so an unchanged source produces no diff
    with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as zf:
        for path in files:
            info = zipfile.ZipInfo(f'{name}/{path.relative_to(source).as_posix()}')
            info.date_time = (1980, 1, 1, 0, 0, 0)
            info.external_attr = 0o644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            zf.writestr(info, path.read_bytes())

    print(f'{target.name}: {len(files)} files, version {version}')


if __name__ == '__main__':
    build(sys.argv[1] if len(sys.argv) > 1 else 'game-query')
