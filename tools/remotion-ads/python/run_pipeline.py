#!/usr/bin/env python3
"""Orquesta: TTS → Whisper → MIIA edit plan → props → Remotion render."""
from __future__ import annotations

import argparse
import json
import shutil
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import build_props
import commun as c
import edit_plan_miia
import transcribir
import tts


def render_remotion(job_dir: Path, props_path: Path) -> Path:
    out_dir = job_dir / "out"
    out_dir.mkdir(parents=True, exist_ok=True)
    out_mp4 = out_dir / "final.mp4"
    npx = shutil.which("npx.cmd") or shutil.which("npx")
    if not npx:
        raise c.FaltaDependencia("Falta npx/npm en PATH.")

    cmd = [
        npx,
        "remotion",
        "render",
        "src/index.ts",
        "ProductAd",
        str(out_mp4),
        f"--props={props_path}",
    ]
    print("Render Remotion…")
    env = dict(**__import__("os").environ)
    env["PYTHONIOENCODING"] = "utf-8"
    env["PYTHONUTF8"] = "1"
    proc = subprocess.run(
        cmd,
        cwd=str(c.RAIZ),
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
        env=env,
    )
    if proc.returncode != 0:
        err = (proc.stderr or proc.stdout or "")[-2000:]
        raise RuntimeError(f"Remotion render falló:\n{err}")
    if not out_mp4.is_file() or out_mp4.stat().st_size < 1024:
        raise RuntimeError("Remotion no generó out/final.mp4")
    print(f"  ✓ {out_mp4}")
    return out_mp4


def run(job_dir: Path, preset: str, skip_miia: bool = False, skip_render: bool = False) -> Path | None:
    job_dir = Path(job_dir).resolve()
    if not job_dir.is_dir():
        raise FileNotFoundError(f"Job no existe: {job_dir}")

    # Pasos 1–5 en Python; el 6/6 (ingest) lo marca Laravel.
    c.set_status(job_dir, "running", "1/6 Generando voiceover (TTS)…", step=1, steps=6)
    tts.ensure_voice(job_dir)

    c.set_status(job_dir, "running", "2/6 Transcribiendo audio con Whisper…", step=2, steps=6)
    transcribir.transcribir(job_dir)

    c.set_status(job_dir, "running", "3/6 Plan de cortes con MIIA…", step=3, steps=6)
    used_miia = False
    if not skip_miia:
        used_miia = edit_plan_miia.call_miia_edit_plan(job_dir, preset)
    if not used_miia:
        c.set_status(
            job_dir,
            "running",
            "3/6 Plan de cortes (fallback local)…",
            step=3,
            steps=6,
        )
        build_props.ensure_edit_plan(job_dir, preset, force_fallback=True)

    c.set_status(job_dir, "running", "4/6 Construyendo props de Remotion…", step=4, steps=6)
    props_path = build_props.write_props(job_dir, preset)

    if skip_render:
        c.set_status(
            job_dir,
            "props_ready",
            "Props listos (sin render)",
            step=4,
            steps=6,
            props=str(props_path),
        )
        return None

    c.set_status(job_dir, "running", "5/6 Renderizando video con Remotion…", step=5, steps=6)
    mp4 = render_remotion(job_dir, props_path)
    c.set_status(
        job_dir,
        "done",
        "5/6 Video renderizado; pendiente importar…",
        step=5,
        steps=6,
        output=str(mp4),
    )
    return mp4


def main() -> int:
    ap = argparse.ArgumentParser(description="Pipeline Remotion Ads Multidrop")
    ap.add_argument("job_dir")
    ap.add_argument(
        "--preset",
        default="product_presenter",
        choices=["product_presenter", "quick_transition"],
    )
    ap.add_argument("--skip-miia", action="store_true")
    ap.add_argument("--skip-render", action="store_true")
    a = ap.parse_args()
    try:
        run(Path(a.job_dir), a.preset, a.skip_miia, a.skip_render)
    except (c.FaltaDependencia, RuntimeError, FileNotFoundError) as e:
        job = Path(a.job_dir)
        try:
            c.set_status(job, "failed", str(e))
        except Exception:
            pass
        print(str(e), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
