#!/usr/bin/env python3
"""TTS con edge-tts cuando no hay voiceover subido."""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import commun as c

# Voz ES-MX natural
DEFAULT_VOICE = "es-MX-DaliaNeural"


def _import_asyncio():
    """Import diferido: en Windows falla si falta SystemRoot en el entorno."""
    try:
        import asyncio
        return asyncio
    except OSError as e:
        winerr = getattr(e, "winerror", None)
        if winerr == 10106 or "10106" in str(e):
            raise RuntimeError(
                "Windows no pudo inicializar Winsock (WinError 10106) al cargar asyncio. "
                "Suele pasar si el proceso se lanzó sin la variable SystemRoot. "
                "Comprueba que Laravel herede el entorno al ejecutar Python, o ejecuta "
                "como admin: netsh winsock reset && reinicia."
            ) from e
        raise


def script_from_prompt(prompt: dict) -> str:
    parts: list[str] = []
    hook = (prompt.get("hook") or "").strip()
    if hook:
        parts.append(hook)
    segments = prompt.get("segments") or []
    if isinstance(segments, list) and segments:
        for seg in segments:
            if not isinstance(seg, dict):
                continue
            vo = (seg.get("voiceover") or "").strip()
            if vo:
                parts.append(vo)
    if not parts:
        script = (prompt.get("script") or "").strip()
        # Quitar encabezados tipo === SEGMENTO ===
        lines = []
        for line in script.splitlines():
            s = line.strip()
            if not s or s.startswith("=") or s.startswith("#"):
                continue
            if re.match(r"^(VOZ|HOOK|CTA|TRANSICI[OÓ]N|TALENT|C[AÁ]MARA|VISUAL|AUDIO)\s*:", s, re.I):
                _, _, rest = s.partition(":")
                rest = rest.strip()
                if rest:
                    lines.append(rest)
                continue
            lines.append(s)
        parts.append(" ".join(lines) if lines else script)
    text = " ".join(parts)
    text = re.sub(r"\s+", " ", text).strip()
    return text[:4500]


async def _synthesize(text: str, out: Path, voice: str) -> None:
    try:
        import edge_tts
    except ImportError as e:
        raise c.FaltaDependencia(
            "Falta edge-tts. Instala: python -m pip install edge-tts"
        ) from e
    communicate = edge_tts.Communicate(text, voice)
    out.parent.mkdir(parents=True, exist_ok=True)
    await communicate.save(str(out))


def ensure_voice(job_dir: Path, voice: str = DEFAULT_VOICE, force: bool = False) -> Path:
    existing = c.find_voice(job_dir)
    if existing and not force:
        return existing

    prompt_path = job_dir / "prompt.json"
    if not prompt_path.is_file():
        raise FileNotFoundError("Falta prompt.json para generar TTS.")
    prompt = c.leer_json(prompt_path)
    text = script_from_prompt(prompt if isinstance(prompt, dict) else {})
    if len(text) < 8:
        raise RuntimeError("El script del prompt está vacío; no se puede hacer TTS.")

    out = job_dir / "voice.mp3"
    print(f"Generando TTS ({voice})…")
    asyncio = _import_asyncio()
    asyncio.run(_synthesize(text, out, voice))
    if not out.is_file() or out.stat().st_size < 256:
        raise RuntimeError("edge-tts no generó voice.mp3")
    print(f"  → {out}")
    return out


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("job_dir")
    ap.add_argument("--voice", default=DEFAULT_VOICE)
    ap.add_argument("--force", action="store_true")
    a = ap.parse_args()
    try:
        ensure_voice(Path(a.job_dir), a.voice, a.force)
    except (c.FaltaDependencia, RuntimeError, FileNotFoundError) as e:
        print(str(e), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
