#!/usr/bin/env python3
"""Comprueba que el catálogo y la biblioteca de música cuadran."""

import json
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
cat = json.loads((RAIZ / "music_catalog.json").read_text(encoding="utf-8"))
tracks = cat["tracks"]
ids = [t["id"] for t in tracks]
print(f"pistas: {len(ids)} | ids únicos: {len(set(ids)) == len(ids)}")
ok = True
for t in tracks:
    p = RAIZ / "public" / t["file"]
    existe = p.is_file()
    ok = ok and existe
    campos = all(t.get(k) for k in ("id", "title", "artist", "mood", "file", "source_url", "license", "credit"))
    if not campos:
        ok = False
    print(f"  {'OK   ' if existe and campos else 'FALTA'} {t['id']:<20} {t['mood']:<20} {p.name} ({p.stat().st_size // 1024 if existe else 0} KB)")

huerfanos = sorted(p.name for p in (RAIZ / "public" / "music").glob("*.mp3") if p.name not in {Path(t["file"]).name for t in tracks})
if huerfanos:
    print("mp3 sin catalogar:", huerfanos)
print("RESULTADO:", "OK" if ok else "FALLA")
sys.exit(0 if ok else 1)
