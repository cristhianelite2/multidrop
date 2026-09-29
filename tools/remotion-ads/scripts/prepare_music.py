#!/usr/bin/env python3
"""Normaliza la biblioteca de música de Remotion Ads.

Deja cada pista en un nivel conocido para que el mix sea predecible:
recorta a los primeros `--seconds`, iguala la sonoridad (EBU R128) a
`--target-lufs` sin pasar de `--peak` en picos, y aplica fundidos para que el
loop no se oiga.

Uso:
    python scripts/prepare_music.py --incoming /ruta/descargas   # mete y normaliza
    python scripts/prepare_music.py                              # solo verifica/normaliza
    python scripts/prepare_music.py --force                      # reprocesa todo

Cada archivo se procesa una sola vez: el resultado se guarda en
`public/music/library.json` junto con los parámetros usados.
"""

from __future__ import annotations

import argparse
import json
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
MUSIC_DIR = RAIZ / "public" / "music"
LEDGER = MUSIC_DIR / "library.json"
FFMPEG = shutil.which("ffmpeg") or shutil.which("ffmpeg.exe")


def medir(path: Path) -> tuple[float, float, float]:
    """Devuelve (duracion_s, mean_volume_dB, max_volume_dB)."""
    proc = subprocess.run(
        [FFMPEG, "-hide_banner", "-i", str(path), "-af", "volumedetect", "-f", "null", "-"],
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
    )
    salida = proc.stderr or ""
    dur = 0.0
    dur_m = re.search(r"Duration:\s*(\d+):(\d+):(\d+\.\d+)", salida)
    if dur_m:
        dur = int(dur_m.group(1)) * 3600 + int(dur_m.group(2)) * 60 + float(dur_m.group(3))
    if not dur:
        dur_m = re.search(r"time=(\d+\.\d+)", salida)
        dur = float(dur_m.group(1)) if dur_m else 0.0
    mean = float(re.search(r"mean_volume:\s*(-?\d+(?:\.\d+)?) dB", salida).group(1))
    peak = float(re.search(r"max_volume:\s*(-?\d+(?:\.\d+)?) dB", salida).group(1))
    return dur, mean, peak


def medir_loudness(path: Path, segundos: int) -> dict:
    """Pasada 1 de loudnorm: devuelve los valores medidos (EBU R128)."""
    proc = subprocess.run(
        [
            FFMPEG, "-hide_banner", "-i", str(path), "-t", str(segundos),
            "-af", "loudnorm=I=-18:TP=-1.5:LRA=11:print_format=json", "-f", "null", "-",
        ],
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
    )
    salida = proc.stderr or ""
    bloque = re.search(r"\{[^{}]*\}", salida, re.S)
    if not bloque:
        raise RuntimeError(f"ffmpeg no midió loudness de {path.name}")
    return {k: float(v) for k, v in re.findall(r'"(\w+)"\s*:\s*"?(-?\d+(?:\.\d+)?|-?inf)"?', bloque.group(0))}


def procesar(src: Path, dest: Path, segundos: int, target_lufs: float, peak: float) -> dict:
    dur, mean, actual_peak = medir(src)
    if dur < segundos:
        print(f"  ! {src.name} dura {dur:.0f}s (< {segundos}s): se deja entera")
        segundos = max(1, int(dur))
    medido = medir_loudness(src, segundos)
    # loudnorm con medición: deja todas las pistas al mismo LUFS ( perceptions iguales ).
    filtros = (
        f"loudnorm=I={target_lufs}:TP={peak}:LRA=11"
        f":measured_I={medido.get('input_i', -18)}"
        f":measured_TP={medido.get('input_tp', peak)}"
        f":measured_LRA={medido.get('input_lra', 11)}"
        f":measured_thresh={medido.get('input_thresh', -30)}"
        ":linear=true,afade=t=in:st=0:d=0.6"
        f",afade=t=out:st={max(0.0, segundos - max(0.5, min(2.0, segundos * 0.04))):.2f}"
        f":d={max(0.5, min(2.0, segundos * 0.04)):.2f}"
    )
    with tempfile.NamedTemporaryFile(suffix=".mp3", delete=False) as tmp:
        tmp_path = Path(tmp.name)
    cmd = [
        FFMPEG, "-y", "-v", "error", "-i", str(src), "-t", str(segundos),
        "-af", filtros, "-c:a", "libmp3lame", "-b:a", "128k", "-ar", "44100", "-ac", "2",
        str(tmp_path),
    ]
    proc = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8", errors="replace")
    if proc.returncode != 0 or not tmp_path.is_file() or tmp_path.stat().st_size < 4096:
        tmp_path.unlink(missing_ok=True)
        raise RuntimeError(f"ffmpeg falló con {src.name}: {(proc.stderr or '')[-400:]}")
    dest.parent.mkdir(parents=True, exist_ok=True)
    shutil.move(str(tmp_path), str(dest))
    dest.chmod(0o644)  # ffmpeg nace con umask restrictivo; el worker lee como www-data
    _, mean_final, peak_final = medir(dest)
    return {
        "segundos": segundos,
        "lufs_origen": round(medido.get("input_i", 0.0), 2),
        "mean_db": mean_final,
        "peak_db": peak_final,
        "kb": round(dest.stat().st_size / 1024),
    }


def main() -> int:
    ap = argparse.ArgumentParser(description="Normaliza la biblioteca de música de Remotion Ads")
    ap.add_argument("--dir", default=str(MUSIC_DIR), help="Carpeta de la biblioteca (por defecto public/music)")
    ap.add_argument("--incoming", help="Carpeta con mp3 nuevos para incorporar")
    ap.add_argument("--seconds", type=int, default=120, help="Duración máxima conservada (s)")
    ap.add_argument("--target-lufs", type=float, default=-18.0, help="Sonoridad objetivo (LUFS)")
    ap.add_argument("--peak", type=float, default=-1.5, help="Pico máximo (dBFS)")
    ap.add_argument("--force", action="store_true", help="Reprocesar aunque ya esté en library.json")
    a = ap.parse_args()

    if not FFMPEG:
        print("No se encuentra ffmpeg en PATH.", file=sys.stderr)
        return 1

    music_dir = Path(a.dir).resolve()
    music_dir.mkdir(parents=True, exist_ok=True)
    ledger = json.loads(LEDGER.read_text(encoding="utf-8")) if LEDGER.is_file() else {"tracks": {}, "params": {}}
    params = {"seconds": a.seconds, "target_lufs": a.target_lufs, "peak": a.peak}
    ledger["params"] = params
    cambiados = 0

    if a.incoming:
        for src in sorted(Path(a.incoming).resolve().glob("*.mp3")):
            slug = re.sub(r"-mp3$", "", re.sub(r"[^a-z0-9]+", "-", src.stem.lower())).strip("-")
            dest = music_dir / f"{slug}.mp3"
            info = procesar(src, dest, a.seconds, a.target_lufs, a.peak)
            ledger["tracks"][dest.name] = info
            cambiados += 1
            print(f"  + {dest.name}: {info['segundos']}s {info['lufs_origen']} LUFS -> {info['mean_db']} dB pico {info['peak_db']} ({info['kb']} KB)")

    for src in sorted(music_dir.glob("*.mp3")):
        previo = ledger["tracks"].get(src.name)
        if previo and not a.force and ledger.get("params") == params:
            print(f"  = {src.name}: ya normalizado ({previo['mean_db']} dB)")
            continue
        info = procesar(src, src, a.seconds, a.target_lufs, a.peak)
        ledger["tracks"][src.name] = info
        cambiados += 1
        print(f"  * {src.name}: {info['segundos']}s {info['lufs_origen']} LUFS -> {info['mean_db']} dB pico {info['peak_db']} ({info['kb']} KB)")

    LEDGER.write_text(json.dumps(ledger, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    LEDGER.chmod(0o644)
    total = sum(i["kb"] for i in ledger["tracks"].values())
    print(f"Listo: {len(ledger['tracks'])} pistas, {total/1024:.1f} MB en total ({cambiados} procesadas).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
