#!/usr/bin/env python3
"""Builds every plugin ZIP and the updates.json a GitHub release carries next to them.

Each plugin.json points its update_url at releases/latest/download/updates.json, so the panel
only sees a version once its ZIP is out. The download links name the tag, so the checksum
always belongs to that exact file.

    python scripts/release.py v2026.10.28 <out dir>
    gh release create v2026.10.28 plugins/*.zip <out dir>/updates.json
"""
import hashlib
import json
import pathlib
import sys

sys.path.insert(0, str(pathlib.Path(__file__).parent))
from build import build  # noqa: E402

REPO = 'https://github.com/Marschux/Pelican-Tools'
PLUGINS = pathlib.Path(__file__).parent.parent / 'plugins'


def main(tag: str, out: pathlib.Path) -> None:
    updates = {}

    for source in sorted(p for p in PLUGINS.iterdir() if (p / 'plugin.json').is_file()):
        build(source.name)
        meta = json.loads((source / 'plugin.json').read_text(encoding='utf-8'))
        expected = f'{REPO}/releases/latest/download/updates.json'
        if meta.get('update_url') != expected:
            sys.exit(f'{source.name}: update_url must be {expected}')
        updates[meta['id']] = {'*': {
            'version': meta['version'],
            'download_url': f'{REPO}/releases/download/{tag}/{source.name}.zip',
            'sha256': hashlib.sha256((PLUGINS / f'{source.name}.zip').read_bytes()).hexdigest(),
        }}

    out.mkdir(parents=True, exist_ok=True)
    (out / 'updates.json').write_bytes((json.dumps(updates, indent=4) + '\n').encode())
    print(f'{out / "updates.json"}: {", ".join(f"{k} {v["*"]["version"]}" for k, v in updates.items())}')


if __name__ == '__main__':
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    main(sys.argv[1], pathlib.Path(sys.argv[2]))
