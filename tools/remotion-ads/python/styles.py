#!/usr/bin/env python3
"""Catálogo de estilos de producto compartido con la composición Remotion.

`styles.json` es la única fuente de verdad: Laravel elige un id al azar por
generación, `build_props` lo resuelve y lo vuelca en composition-props.json, y
`src/styles.ts` lo importa para los tipos. Los estilos nacen de las plantillas
de https://github.com/maxtron777/remotion-business-templates adaptadas a 9:16.
"""
from __future__ import annotations

import json
import random
from pathlib import Path

import commun as c

ARCHIVO = c.RAIZ / "styles.json"

_cache: list[dict] | None = None


def _normalizar(raw: dict) -> dict:
    estilo = dict(raw)
    estilo["accent_2"] = estilo.get("accent_2") or estilo.get("accent")
    ratio = estilo.get("video_ratio") or [0.28, 0.42]
    estilo["video_ratio"] = [float(ratio[0]), float(ratio[1])]
    estilo["clip_seconds"] = float(estilo.get("clip_seconds") or 2.6)
    estilo["energy"] = float(estilo.get("energy") or 0.6)
    estilo["decorations"] = list(estilo.get("decorations") or [])
    estilo["transitions"] = list(estilo.get("transitions") or ["fade", "wipe", "slide_left"])
    return estilo


def catalogo() -> list[dict]:
    global _cache
    if _cache is None:
        data: dict = {}
        if ARCHIVO.is_file():
            try:
                data = json.loads(ARCHIVO.read_text(encoding="utf-8"))
            except (OSError, ValueError):
                data = {}
        defaults = data.get("defaults") or {}
        estilos = [_normalizar({**defaults, **raw}) for raw in (data.get("styles") or [])]
        _cache = estilos or [_normalizar({**defaults, "id": "social_ad", "label": "Social Ad"})]
    return _cache


def ids() -> list[str]:
    return [str(estilo["id"]) for estilo in catalogo()]


def etiquetas() -> dict[str, str]:
    return {str(estilo["id"]): str(estilo.get("label") or estilo["id"]) for estilo in catalogo()}


def existe(preset: str) -> bool:
    return str(preset) in ids()


def resolver(preset: str) -> dict:
    """Devuelve el estilo pedido; si no existe, el primero del catálogo."""
    objetivo = str(preset or "").strip()
    for estilo in catalogo():
        if estilo["id"] == objetivo:
            return estilo
    return catalogo()[0]


def estilo_aleatorio(rng: random.Random | None = None) -> dict:
    estilos = catalogo()
    elegir = (rng or random).choice
    return dict(elegir(estilos))


def ritmo(estilo: dict) -> tuple[int, float]:
    """Clips objetivo y ratio de video dentro del rango del estilo."""
    seg = max(1.4, min(4.0, float(estilo.get("clip_seconds") or 2.6)))
    bajo, alto = estilo.get("video_ratio") or [0.28, 0.42]
    return seg, max(0.0, min(0.6, float(bajo))), max(0.0, min(0.6, float(alto)))


def archivo() -> Path:
    return ARCHIVO
