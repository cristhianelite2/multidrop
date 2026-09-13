#!/usr/bin/env python3
"""Utilidades compartidas del pipeline Remotion Ads (adaptado del kit-editor-video)."""
from __future__ import annotations

import json
import os
import platform
import shutil
import subprocess
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
JOBS = RAIZ / "jobs"
ES_WINDOWS = platform.system() == "Windows"


class FaltaDependencia(RuntimeError):
    pass


_cache_binarios: dict[str, str | None] = {}


def _arranca(ruta: str) -> bool:
    try:
        proc = subprocess.run(
            [ruta, "-version"], capture_output=True, timeout=20
        )
        return proc.returncode == 0
    except (OSError, subprocess.SubprocessError):
        return False


def buscar_binario(nombre: str) -> str | None:
    if nombre in _cache_binarios:
        return _cache_binarios[nombre]
    sufijo = ".exe" if ES_WINDOWS else ""
    vistos: set[str] = set()
    candidatos: list[str] = []
    for carpeta in os.environ.get("PATH", "").split(os.pathsep):
        if not carpeta or carpeta in vistos:
            continue
        vistos.add(carpeta)
        ruta = Path(carpeta) / (nombre + sufijo)
        if ruta.is_file():
            candidatos.append(str(ruta))
    # XAMPP / common Windows ffmpeg
    extras = [
        r"C:\ffmpeg\bin",
        r"F:\xampp82\apache\bin",
        str(Path(os.environ.get("LOCALAPPDATA", "")) / "Microsoft" / "WinGet" / "Links"),
    ]
    for carpeta in extras:
        ruta = Path(carpeta) / (nombre + sufijo)
        if ruta.is_file():
            candidatos.append(str(ruta))
    elegido = next((c for c in candidatos if _arranca(c)), None)
    _cache_binarios[nombre] = elegido
    return elegido


def exigir_ffmpeg() -> str:
    ruta = buscar_binario("ffmpeg")
    if ruta:
        return ruta
    raise FaltaDependencia("Falta ffmpeg en PATH.")


def correr_ffmpeg(args: list[str], timeout: int = 600) -> None:
    binario = exigir_ffmpeg()
    cmd = [binario, "-hide_banner", "-loglevel", "error", "-y", *args]
    proc = subprocess.run(cmd, capture_output=True, timeout=timeout)
    if proc.returncode != 0:
        err = (proc.stderr or b"").decode(errors="replace")[:800]
        raise RuntimeError(f"ffmpeg falló: {err}")


def leer_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def escribir_json(path: Path, data) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(data, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )


def duracion_audio(path: Path) -> float:
    ffprobe = buscar_binario("ffprobe")
    if not ffprobe:
        # fallback via ffmpeg
        return 0.0
    proc = subprocess.run(
        [
            ffprobe,
            "-v",
            "error",
            "-show_entries",
            "format=duration",
            "-of",
            "default=noprint_wrappers=1:nokey=1",
            str(path),
        ],
        capture_output=True,
        text=True,
        timeout=60,
    )
    try:
        return float((proc.stdout or "").strip())
    except ValueError:
        return 0.0


def trabajo(job_dir: Path, nombre: str) -> Path:
    d = job_dir / "trabajo"
    d.mkdir(parents=True, exist_ok=True)
    return d / nombre


def status_path(job_dir: Path) -> Path:
    return job_dir / "status.json"


def set_status(job_dir: Path, state: str, message: str = "", step: int | None = None, steps: int | None = None, **extra) -> None:
    data = {"state": state, "message": message, **extra}
    if step is not None:
        data["step"] = int(step)
    if steps is not None:
        data["steps"] = int(steps)
    escribir_json(status_path(job_dir), data)


def file_url(path: Path) -> str:
    """Remotion acepta file:/// en Windows y Unix."""
    resolved = path.resolve()
    return resolved.as_uri()


def list_media(job_dir: Path) -> list[dict]:
    items: list[dict] = []
    for folder, mtype, exts in (
        ("images", "image", {".jpg", ".jpeg", ".png", ".webp", ".gif"}),
        ("videos", "video", {".mp4", ".webm", ".mov", ".m4v"}),
    ):
        base = job_dir / folder
        if not base.is_dir():
            continue
        for p in sorted(base.iterdir()):
            if p.is_file() and p.suffix.lower() in exts:
                items.append(
                    {
                        "path": str(p.resolve()),
                        "rel": f"{folder}/{p.name}",
                        "media_type": mtype,
                        "name": p.name,
                    }
                )
    return items


def find_voice(job_dir: Path) -> Path | None:
    for name in ("voice.mp3", "voice.wav", "voice.m4a", "voz.mp3", "voz.wav"):
        p = job_dir / name
        if p.is_file() and p.stat().st_size > 256:
            return p
    audio_dir = job_dir / "audio"
    if audio_dir.is_dir():
        for p in sorted(audio_dir.iterdir()):
            if p.suffix.lower() in {".mp3", ".wav", ".m4a"} and p.stat().st_size > 256:
                return p
    return None


def find_music(job_dir: Path) -> Path | None:
    for name in ("music.mp3", "musica.mp3", "bgm.mp3"):
        p = job_dir / name
        if p.is_file() and p.stat().st_size > 256:
            return p
    return None


def which_php() -> str | None:
    return shutil.which("php")


def multidrop_root() -> Path:
    # tools/remotion-ads -> multidrop
    return RAIZ.parent.parent
