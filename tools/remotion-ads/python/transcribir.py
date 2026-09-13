#!/usr/bin/env python3
"""Transcribe VO con faster-whisper (tiempos por palabra)."""
from __future__ import annotations

import argparse
import sys
import time
import warnings
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import commun as c

MODELO_POR_DEFECTO = "small"
PISTA_ESTILO = (
    "Anuncio en español de producto para ecommerce y TikTok. "
    "Habla de oferta, envío, calidad, precio, comprar ahora y beneficios del producto."
)


def extraer_voz(origen: Path, destino: Path) -> Path:
    if destino.is_file() and destino.stat().st_size > 1024:
        return destino
    c.correr_ffmpeg([
        "-i", str(origen),
        "-vn", "-ac", "1", "-ar", "16000",
        "-c:a", "pcm_s16le", str(destino),
    ])
    return destino


def _cargar_whisper(modelo: str):
    try:
        from faster_whisper import WhisperModel
    except ImportError as e:
        raise c.FaltaDependencia(
            "Falta faster-whisper. Instala: python -m pip install faster-whisper"
        ) from e
    import os
    hilos = max(1, min(os.cpu_count() or 4, 8))
    return WhisperModel(modelo, device="cpu", compute_type="int8", cpu_threads=hilos)


def con_whisper(audio: Path, modelo: str = MODELO_POR_DEFECTO, idioma: str | None = "es"):
    modelo_cargado = _cargar_whisper(modelo)
    warnings.filterwarnings("ignore", message=".*divide by zero.*")
    with warnings.catch_warnings():
        warnings.simplefilter("ignore")
        segmentos, info = modelo_cargado.transcribe(
            str(audio),
            language=idioma,
            word_timestamps=True,
            vad_filter=True,
            vad_parameters={"min_silence_duration_ms": 400},
            condition_on_previous_text=False,
            initial_prompt=PISTA_ESTILO,
            beam_size=5,
        )
        palabras, frases = [], []
        for seg in segmentos:
            texto = (seg.text or "").strip()
            if texto:
                frases.append({
                    "inicio": round(seg.start, 3),
                    "fin": round(seg.end, 3),
                    "texto": texto,
                })
            for p in seg.words or []:
                limpio = (p.word or "").strip()
                if limpio:
                    palabras.append({
                        "inicio": round(p.start, 3),
                        "fin": round(p.end, 3),
                        "texto": limpio,
                        "prob": round(float(p.probability), 3),
                    })
    return {
        "motor": f"whisper-local ({modelo})",
        "idioma": getattr(info, "language", idioma or "es"),
        "palabras": palabras,
        "frases": frases,
    }


def transcribir(job_dir: Path, modelo: str | None = None, idioma: str | None = "es", refrescar: bool = False):
    job_dir = Path(job_dir)
    salida = c.trabajo(job_dir, "transcripcion.json")
    if salida.is_file() and not refrescar:
        previo = c.leer_json(salida)
        print(f"Ya estaba transcrito ({len(previo.get('palabras', []))} palabras).")
        return previo

    voice = c.find_voice(job_dir)
    if not voice:
        raise FileNotFoundError("No hay voice.mp3 / voz en el job.")

    wav = c.trabajo(job_dir, "voz.wav")
    audio = extraer_voz(voice, wav)
    print("Transcribiendo con whisper…")
    t0 = time.time()
    datos = con_whisper(audio, modelo or MODELO_POR_DEFECTO, idioma)
    dur = c.duracion_audio(voice) or c.duracion_audio(audio)
    if not dur and datos["palabras"]:
        dur = float(datos["palabras"][-1]["fin"])
    datos["duracion_audio"] = round(float(dur), 3)
    c.escribir_json(salida, datos)
    print(
        f"  ✓ {len(datos['palabras'])} palabras "
        f"({time.time() - t0:.0f}s) → {salida}"
    )
    return datos


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("job_dir")
    ap.add_argument("--modelo", default=None)
    ap.add_argument("--idioma", default="es")
    ap.add_argument("--refrescar", action="store_true")
    a = ap.parse_args()
    try:
        transcribir(Path(a.job_dir), a.modelo, a.idioma, a.refrescar)
    except (c.FaltaDependencia, RuntimeError, FileNotFoundError) as e:
        print(str(e), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
