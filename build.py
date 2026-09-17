#!/usr/bin/env python3
"""Baut plugins/<name>.zip aus plugins/<name>/ neu.

Die ZIP ist das Deploy-Artefakt und wird mit eingecheckt. Weicht sie vom Quellstand ab,
laedt man eine alte Version hoch und sucht den Fehler anschliessend im Code - genau das
ist beim Vorgaenger-Plugin passiert. Also immer hierueber bauen, nie von Hand.

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
        sys.exit(f'{source} gibt es nicht')

    version = json.loads((source / 'plugin.json').read_text(encoding='utf-8'))['version']
    target = root / f'{name}.zip'

    files = sorted(
        p for p in source.rglob('*')
        if p.is_file()
        and p.suffix not in SKIP_SUFFIX
        and not SKIP_DIRS & set(p.relative_to(source).parts)
    )

    # deterministisch schreiben, damit eine unveraenderte Quelle keinen Diff erzeugt
    with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as zf:
        for path in files:
            info = zipfile.ZipInfo(f'{name}/{path.relative_to(source).as_posix()}')
            info.date_time = (1980, 1, 1, 0, 0, 0)
            info.external_attr = 0o644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            zf.writestr(info, path.read_bytes())

    print(f'{target.name}: {len(files)} Dateien, Version {version}')


if __name__ == '__main__':
    build(sys.argv[1] if len(sys.argv) > 1 else 'game-query')
