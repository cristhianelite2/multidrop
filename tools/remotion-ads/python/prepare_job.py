#!/usr/bin/env python3
"""Valida/crea estructura mínima de un job (para pruebas CLI)."""
from __future__ import annotations

import argparse
import json
import shutil
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import commun as c


def prepare(job_dir: Path, prompt: dict | None = None) -> Path:
    job_dir.mkdir(parents=True, exist_ok=True)
    (job_dir / "images").mkdir(exist_ok=True)
    (job_dir / "videos").mkdir(exist_ok=True)
    (job_dir / "trabajo").mkdir(exist_ok=True)
    (job_dir / "out").mkdir(exist_ok=True)
    pp = job_dir / "prompt.json"
    if prompt is not None:
        c.escribir_json(pp, prompt)
    elif not pp.is_file():
        c.escribir_json(
            pp,
            {
                "hook": "Mira esto antes de comprar otro gadget inútil",
                "script": "Este producto resuelve el problema en segundos. Calidad real, envío rápido. Compra ahora.",
                "segments": [
                    {"index": 1, "start": 0, "end": 3, "voiceover": "Mira esto antes de comprar otro gadget inútil", "transition": "jump cut"},
                    {"index": 2, "start": 3, "end": 6, "voiceover": "Este producto resuelve el problema en segundos", "transition": "fade"},
                    {"index": 3, "start": 6, "end": 9, "voiceover": "Calidad real, envío rápido. Compra ahora.", "transition": "zoom"},
                ],
                "language": "es",
                "style": "DynamicProductTemplate",
            },
        )
    c.set_status(job_dir, "prepared", "Job listo")
    return job_dir


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("job_dir")
    ap.add_argument("--from-zip", default=None, help="ZIP exportado del prompt Multidrop")
    a = ap.parse_args()
    job = Path(a.job_dir)
    prepare(job)
    if a.from_zip:
        z = Path(a.from_zip)
        if not z.is_file():
            print(f"ZIP no encontrado: {z}", file=sys.stderr)
            return 1
        import zipfile
        with zipfile.ZipFile(z, "r") as zf:
            zf.extractall(job)
        # prompt.txt → no JSON; dejar prompt.json si ya existe
        print(f"Extraído {z} → {job}")
    print(job)
    return 0


if __name__ == "__main__":
    sys.exit(main())
