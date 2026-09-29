#!/usr/bin/env python3
"""Orquesta: TTS → Whisper → MIIA edit plan → props → Remotion render."""
from __future__ import annotations

import argparse
import json
import platform
import shutil
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import build_props
import commun as c
import edit_plan_miia
import styles as st
import transcribir
import tts


def validar_runtime_remotion() -> None:
    """Comprueba esbuild antes de gastar tiempo en TTS y transcripción."""
    node = shutil.which("node") or shutil.which("node.exe")
    esbuild = c.RAIZ / "node_modules" / "esbuild" / "lib" / "main.js"
    if not node or not esbuild.is_file():
        raise c.FaltaDependencia(
            "Faltan las dependencias de Remotion. En esta máquina ejecuta "
            "`npm ci --include=optional` desde tools/remotion-ads."
        )

    proc = subprocess.run(
        [node, "-e", "require('esbuild').transformSync('const runtimeCheck = true')"],
        cwd=str(c.RAIZ),
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
        timeout=30,
    )
    if proc.returncode != 0:
        detail = (proc.stderr or proc.stdout or "").strip()
        raise c.FaltaDependencia(
            "Las dependencias de Remotion no corresponden a esta máquina "
            f"({platform.system()} {platform.machine()}); esbuild no pudo iniciar. "
            "Instálalas aquí con `npm ci --include=optional` desde "
            "tools/remotion-ads. No copies node_modules desde otro sistema."
            + (f" Detalle: {detail[-700:]}" if detail else "")
        )


def masterizar_audio(mp4: Path, objetivo_lufs: float = -14.0) -> str | None:
    """Iguala el volumen de la mezcla final (voz + música) sin tocar el video.

    La voz depende del proveedor de TTS y la música llega a -18 LUFS; sin este
    paso algunas generaciones salen mudas. Devuelve el LUFS medido.
    """
    ffmpeg = c.buscar_binario("ffmpeg")
    if not ffmpeg:
        print("  ! sin ffmpeg: se deja el audio tal cual")
        return None
    ffprobe = c.buscar_binario("ffprobe")
    if ffprobe:
        probe = subprocess.run(
            [ffprobe, "-v", "error", "-select_streams", "a:0", "-show_entries", "stream=codec_type", "-of", "csv=p=0", str(mp4)],
            capture_output=True, text=True, encoding="utf-8", errors="replace",
        )
        if "audio" not in (probe.stdout or ""):
            print("  ! el video no tiene pista de audio")
            return None

    def _correr(args: list[str]) -> subprocess.CompletedProcess:
        return subprocess.run([ffmpeg, "-hide_banner", "-nostdin", "-y", *args], capture_output=True, text=True, encoding="utf-8", errors="replace")

    medir = _correr(["-i", str(mp4), "-af", f"loudnorm=I={objetivo_lufs}:TP=-1.5:LRA=11:print_format=json", "-f", "null", "-"])
    import re
    bloque = re.search(r"\{[^{}]*\}", medir.stderr or "", re.S)
    if not bloque:
        print("  ! no se pudo medir el audio final")
        return None
    medido = {k: v.strip('"') for k, v in re.findall(r'"(\w+)"\s*:\s*"?(-?\d+(?:\.\d+)?|-?inf)"?', bloque.group(0))}
    if medido.get("input_i") in (None, "-inf"):
        print("  ! el audio final es silencio")
        return None

    tmp = mp4.with_name("final.master.mp4")
    filtros = (
        f"loudnorm=I={objetivo_lufs}:TP=-1.5:LRA=11"
        f":measured_I={medido.get('input_i')}"
        f":measured_TP={medido.get('input_tp', -1.5)}"
        f":measured_LRA={medido.get('input_lra', 11)}"
        f":measured_thresh={medido.get('input_thresh', -30)}"
        ":linear=true"
    )
    proc = _correr(["-i", str(mp4), "-map", "0:v", "-map", "0:a", "-c:v", "copy", "-af", filtros, "-c:a", "aac", "-b:a", "192k", "-ar", "48000", str(tmp)])
    if proc.returncode != 0 or not tmp.is_file() or tmp.stat().st_size < 4096:
        tmp.unlink(missing_ok=True)
        print(f"  ! falló la masterización: {(proc.stderr or '')[-300:]}")
        return None
    tmp.replace(mp4)
    return f"{float(medido['input_i']):.1f} LUFS -> {objetivo_lufs:.0f} LUFS"


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
        f"--public-dir={c.public_root(job_dir)}",
    ]
    print("Render Remotion…")
    env = dict(**__import__("os").environ)
    env["PYTHONIOENCODING"] = "utf-8"
    env["PYTHONUTF8"] = "1"
    try:
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
    finally:
        # Cada render tiene su propio staticFile root; liberarlo también si el render falla.
        c.limpiar_public_job(job_dir)


def run(job_dir: Path, preset: str, skip_miia: bool = False, skip_render: bool = False) -> Path | None:
    job_dir = Path(job_dir).resolve()
    if not job_dir.is_dir():
        raise FileNotFoundError(f"Job no existe: {job_dir}")

    estilo = st.resolver(preset)
    preset = estilo["id"]

    if not skip_render:
        validar_runtime_remotion()

    # Pasos 1–5 en Python; el 6/6 (ingest) lo marca Laravel.
    c.set_status(job_dir, "running", f"1/6 Generando voiceover (TTS)… · Estilo: {estilo.get('label', preset)}", step=1, steps=6)
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
    resumen = masterizar_audio(mp4)
    if resumen:
        print(f"  ✓ audio mezclado: {resumen}")
    c.set_status(
        job_dir,
        "done",
        "5/6 Video renderizado; pendiente importar…",
        step=5,
        steps=6,
        output=str(mp4),
        **({"audio_lufs": resumen} if resumen else {}),
    )
    return mp4


def main() -> int:
    ap = argparse.ArgumentParser(description="Pipeline Remotion Ads Multidrop")
    ap.add_argument("job_dir")
    ap.add_argument(
        "--preset",
        default="random",
        choices=["random", *st.ids()],
        help="Estilo de styles.json; 'random' elige uno por generación.",
    )
    ap.add_argument("--skip-miia", action="store_true")
    ap.add_argument("--skip-render", action="store_true")
    a = ap.parse_args()
    job = Path(a.job_dir)
    pedido = a.preset
    if pedido == "random":
        pedido = st.estilo_aleatorio(build_props.job_rng(job))["id"]
    try:
        run(job, pedido, a.skip_miia, a.skip_render)
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
