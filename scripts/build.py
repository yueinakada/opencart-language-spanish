#!/usr/bin/env python3
"""Build a deterministic OpenCart 4 extension archive; no network or dependencies."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
import json

ROOT = Path(__file__).resolve().parents[1]
manifest = json.loads((ROOT / 'package/install.json').read_text(encoding='utf-8'))
assert manifest['code'] == 'yuei_spanish_pe'
output = ROOT / 'dist' / 'yuei_spanish_pe.ocmod.zip'
output.parent.mkdir(exist_ok=True)
files = {p.relative_to(ROOT / 'package').as_posix(): p for p in (ROOT / 'package').rglob('*') if p.is_file()}
files |= {name: ROOT / name for name in ['LICENSE', 'NOTICE']}
with ZipFile(output, 'w', compression=ZIP_DEFLATED, compresslevel=9) as archive:
    for name, path in sorted(files.items()):
        if path.is_symlink() or '..' in Path(name).parts:
            raise ValueError(f'Unsafe archive entry: {name}')
        entry = ZipInfo(name, (2026, 1, 1, 0, 0, 0))
        entry.compress_type = ZIP_DEFLATED
        entry.external_attr = 0o100644 << 16
        archive.writestr(entry, path.read_bytes())
digest = hashlib.sha256(output.read_bytes()).hexdigest()
(output.parent / 'SHA256SUMS').write_text(f'{digest}  {output.name}\n', encoding='utf-8')
print(f'{output.name}: {len(files)} entries, SHA-256 {digest}')
