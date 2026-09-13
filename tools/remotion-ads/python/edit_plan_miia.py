#!/usr/bin/env python3
"""Invoca Artisan Laravel para que MIIA genere edit_plan.json."""
from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import commun as c


def call_miia_edit_plan(job_dir: Path, preset: str) -> bool:
    php = c.which_php()
    root = c.multidrop_root()
    artisan = root / "artisan"
    if not php or not artisan.is_file():
        print("Artisan/php no disponible; se usará edit_plan fallback.")
        return False

    cmd = [
        php,
        str(artisan),
        "marketing:remotion-edit-plan",
        str(job_dir.resolve()),
        f"--preset={preset}",
    ]
    print("MIIA edit plan vía Artisan…")
    proc = subprocess.run(cmd, cwd=str(root), capture_output=True, text=True)
    if proc.returncode != 0:
        err = (proc.stderr or proc.stdout or "").strip()[:600]
        print(f"  MIIA falló ({proc.returncode}): {err}")
        return False
    plan_path = c.trabajo(job_dir, "edit_plan.json")
    if not plan_path.is_file():
        print("  Artisan no escribió edit_plan.json")
        return False
    print(f"  → {plan_path}")
    return True


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("job_dir")
    ap.add_argument("--preset", default="product_presenter")
    a = ap.parse_args()
    ok = call_miia_edit_plan(Path(a.job_dir), a.preset)
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
