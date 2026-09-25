#!/usr/bin/env python3
"""Arma un proyecto HyperFrames (Catalog Pop) a partir de product.json + medios.

Reglas creativas Multidrop — estilo CATÁLOGO KINETIC (opuesto al Studio Signal oscuro):
- Duración objetivo ≤30s (9:16).
- Fondo papel / estudio luminoso; tipografía bold sobre producto grande.
- Producto siempre en tarjeta con sombra suave (nunca full-bleed oscuro).
- Beats: SHOW → WHY → PROOF → BUY (vitrina, no UGC cinematic).
"""
from __future__ import annotations

import hashlib
import json
import re
import shutil
from pathlib import Path


# ---------------------------------------------------------------------------
# Paletas Catalog Pop — claves alineadas con config multidrop.visual_styles
# ---------------------------------------------------------------------------
STYLE_PALETTES: dict[str, dict[str, str]] = {
    "signal": {  # default — paper + coral pop
        "bg0": "#f3eee4",
        "bg1": "#fffaf2",
        "ink": "#1a1511",
        "muted": "rgba(26,21,17,0.58)",
        "accent": "#e23d2d",
        "accent2": "#0f766e",
        "panel": "#ffffff",
        "panel_ink": "#1a1511",
        "line": "rgba(26,21,17,0.12)",
        "glow": "rgba(226,61,45,0.14)",
        "label": "POP",
        "energy": "media",
    },
    "tech": {
        "bg0": "#eef2f8",
        "bg1": "#f7f9fc",
        "ink": "#0b1220",
        "muted": "rgba(11,18,32,0.58)",
        "accent": "#2563eb",
        "accent2": "#0ea5e9",
        "panel": "#ffffff",
        "panel_ink": "#0b1220",
        "line": "rgba(11,18,32,0.12)",
        "glow": "rgba(37,99,235,0.14)",
        "label": "TECH",
        "energy": "media",
    },
    "beauty": {
        "bg0": "#f7eef2",
        "bg1": "#fff7fa",
        "ink": "#1a1014",
        "muted": "rgba(26,16,20,0.58)",
        "accent": "#db2777",
        "accent2": "#f59e0b",
        "panel": "#ffffff",
        "panel_ink": "#1a1014",
        "line": "rgba(26,16,20,0.12)",
        "glow": "rgba(219,39,119,0.14)",
        "label": "GLOW",
        "energy": "baja",
    },
    "home": {
        "bg0": "#eef3ea",
        "bg1": "#f7faf4",
        "ink": "#101612",
        "muted": "rgba(16,22,18,0.58)",
        "accent": "#15803d",
        "accent2": "#ca8a04",
        "panel": "#ffffff",
        "panel_ink": "#101612",
        "line": "rgba(16,22,18,0.12)",
        "glow": "rgba(21,128,61,0.14)",
        "label": "HOME",
        "energy": "baja",
    },
    "sport": {
        "bg0": "#f2efe8",
        "bg1": "#faf8f3",
        "ink": "#121214",
        "muted": "rgba(18,18,20,0.58)",
        "accent": "#ea580c",
        "accent2": "#2563eb",
        "panel": "#ffffff",
        "panel_ink": "#121214",
        "line": "rgba(18,18,20,0.12)",
        "glow": "rgba(234,88,12,0.14)",
        "label": "SPORT",
        "energy": "alta",
    },
    "luxury": {
        "bg0": "#f0ebe3",
        "bg1": "#faf6f0",
        "ink": "#121014",
        "muted": "rgba(18,16,20,0.58)",
        "accent": "#a16207",
        "accent2": "#44403c",
        "panel": "#ffffff",
        "panel_ink": "#121014",
        "line": "rgba(18,16,20,0.12)",
        "glow": "rgba(161,98,7,0.14)",
        "label": "LUXE",
        "energy": "baja",
    },
    "candy": {
        "bg0": "#fff0f5",
        "bg1": "#fff7fb",
        "ink": "#3b1020",
        "muted": "rgba(59,16,32,0.55)",
        "accent": "#ec4899",
        "accent2": "#8b5cf6",
        "panel": "#ffffff",
        "panel_ink": "#3b1020",
        "line": "rgba(59,16,32,0.12)",
        "glow": "rgba(236,72,153,0.16)",
        "label": "CANDY",
        "energy": "alta",
    },
    "citrus": {
        "bg0": "#fff8e7",
        "bg1": "#fffdf5",
        "ink": "#1f1708",
        "muted": "rgba(31,23,8,0.55)",
        "accent": "#f59e0b",
        "accent2": "#84cc16",
        "panel": "#ffffff",
        "panel_ink": "#1f1708",
        "line": "rgba(31,23,8,0.12)",
        "glow": "rgba(245,158,11,0.16)",
        "label": "CITRUS",
        "energy": "alta",
    },
    "ocean": {
        "bg0": "#e8f6f6",
        "bg1": "#f4fcfc",
        "ink": "#0c2426",
        "muted": "rgba(12,36,38,0.55)",
        "accent": "#0d9488",
        "accent2": "#0284c7",
        "panel": "#ffffff",
        "panel_ink": "#0c2426",
        "line": "rgba(12,36,38,0.12)",
        "glow": "rgba(13,148,136,0.14)",
        "label": "OCEAN",
        "energy": "media",
    },
    "ink": {
        "bg0": "#f4f4f2",
        "bg1": "#fafaf8",
        "ink": "#0a0a0a",
        "muted": "rgba(10,10,10,0.55)",
        "accent": "#dc2626",
        "accent2": "#171717",
        "panel": "#ffffff",
        "panel_ink": "#0a0a0a",
        "line": "rgba(10,10,10,0.14)",
        "glow": "rgba(220,38,38,0.12)",
        "label": "INK",
        "energy": "media",
    },
    "pastel": {
        "bg0": "#f3f0ff",
        "bg1": "#faf8ff",
        "ink": "#2e1a47",
        "muted": "rgba(46,26,71,0.55)",
        "accent": "#a78bfa",
        "accent2": "#67e8f9",
        "panel": "#ffffff",
        "panel_ink": "#2e1a47",
        "line": "rgba(46,26,71,0.12)",
        "glow": "rgba(167,139,250,0.16)",
        "label": "SOFT",
        "energy": "baja",
    },
    "neon": {
        "bg0": "#f5f7ff",
        "bg1": "#fbfdff",
        "ink": "#0b1020",
        "muted": "rgba(11,16,32,0.55)",
        "accent": "#22d3ee",
        "accent2": "#e879f9",
        "panel": "#ffffff",
        "panel_ink": "#0b1020",
        "line": "rgba(11,16,32,0.12)",
        "glow": "rgba(34,211,238,0.18)",
        "label": "NEON",
        "energy": "alta",
    },
    "editorial": {
        "bg0": "#f7f5f0",
        "bg1": "#fffcf7",
        "ink": "#111111",
        "muted": "rgba(17,17,17,0.55)",
        "accent": "#111111",
        "accent2": "#b45309",
        "panel": "#ffffff",
        "panel_ink": "#111111",
        "line": "rgba(17,17,17,0.16)",
        "glow": "rgba(180,83,9,0.1)",
        "label": "EDIT",
        "energy": "baja",
    },
    "mono": {
        "bg0": "#f0f0f0",
        "bg1": "#fafafa",
        "ink": "#111111",
        "muted": "rgba(17,17,17,0.5)",
        "accent": "#111111",
        "accent2": "#737373",
        "panel": "#ffffff",
        "panel_ink": "#111111",
        "line": "rgba(17,17,17,0.14)",
        "glow": "rgba(17,17,17,0.08)",
        "label": "MONO",
        "energy": "media",
    },
}

CATEGORY_KEYWORDS: dict[str, tuple[str, ...]] = {
    "tech": (
        "tech", "gadget", "electronic", "electrón", "phone", "celular", "laptop",
        "auricular", "headphone", "usb", "smart", "wifi", "bluetooth", "cable",
        "cargador", "tablet", "camera", "cámara", "drone", "console", "gaming",
    ),
    "beauty": (
        "beauty", "belleza", "skin", "piel", "makeup", "maquillaje", "cosmetic",
        "perfume", "serum", "crema", "hair", "cabello", "nail", "uña", "lip",
        "labial", "skincare", "facial",
    ),
    "home": (
        "home", "hogar", "kitchen", "cocina", "decor", "furniture", "mueble",
        "lamp", "lámpara", "organizer", "organizador", "garden", "jardín",
        "clean", "limpieza", "bedding", "cama",
    ),
    "sport": (
        "sport", "deporte", "fitness", "gym", "yoga", "bike", "bici", "run",
        "correr", "outdoor", "camping", "hike", "entrenamiento", "protein",
    ),
    "luxury": (
        "luxury", "lujo", "premium", "jewelry", "joyería", "watch", "reloj",
        "leather", "cuero", "gold", "oro", "diamond", "diamante", "designer",
    ),
    "candy": ("candy", "dulce", "kids", "niño", "juguete", "toy", "gato", "cute"),
    "citrus": ("citrus", "limón", "naranja", "fresh", "fresco", "verano"),
    "ocean": ("ocean", "mar", "agua", "beach", "playa", "swim", "aqua"),
    "ink": ("ink", "editorial", "magazine", "bold", "tipograf"),
    "pastel": ("pastel", "soft", "suave", "baby", "bebé"),
    "neon": ("neon", "neón", "gaming", "rgb", "cyber"),
    "editorial": ("editorial", "magazine", "moda", "fashion"),
    "mono": ("mono", "minimal", "black", "white", "stark"),
}


def esc(text: str) -> str:
    return (
        (text or "")
        .replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
    )


def clean_text(value: object, limit: int = 220) -> str:
    text = re.sub(r"\s+", " ", str(value or "")).strip()
    if len(text) > limit:
        text = text[: limit - 1].rstrip() + "…"
    return text


def punch_line(value: object, limit: int = 42) -> str:
    """Copia corta para on-screen: corta en palabra si es posible."""
    text = clean_text(value, limit + 12)
    if len(text) <= limit:
        return text
    cut = text[:limit].rsplit(" ", 1)[0].rstrip(".,;:—-")
    return (cut or text[:limit]).rstrip() + "…"


NOISE_DETAIL_RE = re.compile(
    r"(producto qu[ií]mico|pcb|origen|cn\(|sku|asin|ean|upc|peso del paquete|"
    r"n[uú]mero de modelo|modelo\s*#|certificaci[oó]n|high concern)",
    re.I,
)
WEAK_HOOK_RE = re.compile(
    r"^(video|nuevo|new|hot|sale|oferta|promo|bestseller|top)$",
    re.I,
)
JUNK_HOOK_RE = re.compile(
    r"(pierdes tiempo|inicio\s*[—\-|]|pasarela|carrito|checkout|"
    r"tambi[eé]n te puede|te estamos llevando|baza\s*$|cat[aá]logo\s*$|"
    r"sin resultados|md-checkout|:root)",
    re.I,
)


def is_useful_line(text: str, min_len: int = 12) -> bool:
    t = clean_text(text, 200)
    if len(t) < min_len:
        return False
    if WEAK_HOOK_RE.match(t):
        return False
    if JUNK_HOOK_RE.search(t):
        return False
    if NOISE_DETAIL_RE.search(t):
        return False
    return True


def hook_from_sources(prompt: dict, product: dict) -> str:
    """Hook punchy: prioriza copy MIIA (hook/segments), nunca chrome de tienda."""
    analysis = prompt.get("analysis") if isinstance(prompt.get("analysis"), dict) else {}
    candidates: list[object] = [
        prompt.get("hook"),
        analysis.get("problem"),
        analysis.get("product_angle"),
        analysis.get("value_prop"),
    ]
    segments = prompt.get("segments") or []
    if isinstance(segments, list):
        for seg in segments:
            if not isinstance(seg, dict):
                continue
            if str(seg.get("type") or "").lower() == "hook":
                candidates.insert(0, seg.get("text_on_screen") or seg.get("voiceover"))
                break
    candidates.extend([product.get("badge"), product.get("description")])
    for raw in candidates:
        line = punch_line(raw or "", 44)
        if is_useful_line(line, 12):
            return line
    name = clean_text(product.get("name") or "Producto", 80)
    bits = re.split(r"[,|/–—]", name)
    lead = punch_line(bits[0] if bits else name, 44)
    if is_useful_line(lead, 10):
        return lead
    return "Míralo de cerca"


def price_label(product: dict) -> str:
    price = product.get("price")
    currency = str(product.get("currency") or "USD").upper()
    if price is None or price == "":
        return ""
    try:
        amount = float(price)
        if amount == int(amount):
            formatted = f"{int(amount):,}".replace(",", ".")
        else:
            formatted = f"{amount:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")
    except (TypeError, ValueError):
        formatted = str(price)
    symbols = {"USD": "US$", "MXN": "MX$", "EUR": "€", "COP": "COL$", "ARS": "AR$", "CLP": "CLP$"}
    return f"{symbols.get(currency, currency + ' ')}{formatted}"


def compare_price_label(product: dict) -> str:
    raw = product.get("compare_at_price")
    if raw in (None, "", 0, "0"):
        return ""
    try:
        current = float(product.get("price") or 0)
        compare = float(raw)
        if compare <= current:
            return ""
    except (TypeError, ValueError):
        return ""
    fake = dict(product)
    fake["price"] = raw
    return price_label(fake)


def creative_direction(prompt: dict) -> dict:
    """Dirección creativa MIIA (prompt root o analysis)."""
    root = prompt.get("creative_direction")
    if isinstance(root, dict) and root:
        return root
    analysis = prompt.get("analysis") if isinstance(prompt.get("analysis"), dict) else {}
    nested = analysis.get("creative_direction")
    return nested if isinstance(nested, dict) else {}


def miia_energy(prompt: dict, palette: dict[str, str] | None = None) -> str:
    """baja | media | alta — MIIA talent.energy o default del estilo visual."""
    cd = creative_direction(prompt)
    talent = cd.get("talent") if isinstance(cd.get("talent"), dict) else {}
    raw = str(talent.get("energy") or cd.get("energy") or "").strip().lower()
    if any(x in raw for x in ("alta", "high", "punch", "energetic", "intensa")):
        return "alta"
    if any(x in raw for x in ("baja", "low", "calm", "suave", "soft")):
        return "baja"
    if palette and palette.get("energy") in ("alta", "media", "baja"):
        return str(palette["energy"])
    return "media"


def product_corpus(product: dict, prompt: dict) -> str:
    parts: list[str] = []
    for key in ("name", "category", "description", "badge", "cta"):
        parts.append(str(product.get(key) or ""))
    tags = product.get("tags") or product.get("categories") or []
    if isinstance(tags, list):
        parts.extend(str(t) for t in tags)
    elif isinstance(tags, str):
        parts.append(tags)
    parts.append(str(prompt.get("hook") or ""))
    parts.append(str(prompt.get("style") or ""))
    parts.append(str(prompt.get("script_style") or ""))
    analysis = prompt.get("analysis") if isinstance(prompt.get("analysis"), dict) else {}
    for key in ("product_angle", "summary", "category", "tone"):
        parts.append(str(analysis.get(key) or ""))
    cd = creative_direction(prompt)
    lighting = cd.get("lighting") if isinstance(cd.get("lighting"), dict) else {}
    channel = cd.get("channel") if isinstance(cd.get("channel"), dict) else {}
    for key in ("mood", "color_grade", "key"):
        parts.append(str(lighting.get(key) or ""))
    parts.append(str(channel.get("tone") or ""))
    return " ".join(parts).lower()


def pick_palette(product: dict, prompt: dict) -> tuple[str, dict[str, str]]:
    """Paleta: visual_style forzado del admin manda; si no, MIIA/keywords."""
    forced = str(product.get("visual_style") or prompt.get("visual_style") or "").strip().lower()
    if forced in STYLE_PALETTES:
        return forced, STYLE_PALETTES[forced]

    cd = creative_direction(prompt)
    lighting = cd.get("lighting") if isinstance(cd.get("lighting"), dict) else {}
    channel = cd.get("channel") if isinstance(cd.get("channel"), dict) else {}
    miia_blob = " ".join(
        [
            str(lighting.get("color_grade") or ""),
            str(lighting.get("mood") or ""),
            str(channel.get("tone") or ""),
            str(prompt.get("style") or ""),
            str(prompt.get("script_style") or ""),
        ]
    ).lower()

    miia_hints: list[tuple[str, tuple[str, ...]]] = [
        ("tech", ("tech", "cool", "blue", "cyber", "neon", "digital", "gadget")),
        ("beauty", ("beauty", "glow", "pink", "rose", "soft", "pastel", "skin", "glam")),
        ("home", ("home", "green", "natural", "organic", "fresh", "earth", "cozy")),
        ("sport", ("sport", "orange", "energy", "bold", "action", "dynamic", "fitness")),
        ("luxury", ("luxury", "luxe", "gold", "premium", "elegant", "warm", "champagne")),
        ("candy", ("candy", "cute", "playful", "pink", "sweet")),
        ("citrus", ("citrus", "yellow", "lemon", "lime", "sunny")),
        ("ocean", ("ocean", "teal", "aqua", "sea", "cyan")),
        ("ink", ("ink", "editorial", "bold type", "high contrast")),
        ("pastel", ("pastel", "lilac", "lavender", "soft")),
        ("neon", ("neon", "electric", "cyber", "rgb")),
        ("editorial", ("magazine", "editorial", "fashion")),
        ("mono", ("mono", "minimal", "black and white", "stark")),
        ("signal", ("coral", "pop", "magazine", "catalog", "warm paper", "bright")),
    ]
    for style, words in miia_hints:
        if any(w in miia_blob for w in words):
            return style, STYLE_PALETTES[style]

    corpus = product_corpus(product, prompt)
    scores: dict[str, int] = {k: 0 for k in CATEGORY_KEYWORDS}
    for style, words in CATEGORY_KEYWORDS.items():
        for w in words:
            if w in corpus:
                scores[style] += 1
    best = max(scores, key=lambda k: scores[k])
    if scores[best] <= 0:
        return "signal", STYLE_PALETTES["signal"]
    return best, STYLE_PALETTES.get(best, STYLE_PALETTES["signal"])


def scene_labels(style_key: str, product: dict, prompt: dict) -> dict[str, str]:
    """Etiquetas: SHOW → WHY → PROOF → BUY; captions MIIA pueden renombrar el cierre."""
    analysis = prompt.get("analysis") if isinstance(prompt.get("analysis"), dict) else {}
    cd = creative_direction(prompt)
    captions = cd.get("captions") if isinstance(cd.get("captions"), dict) else {}
    brand = cd.get("brand") if isinstance(cd.get("brand"), dict) else {}

    angle_label = punch_line(analysis.get("angle_label") or analysis.get("angle_type") or "", 14)
    angle_map = {
        "economico": "AHORRO",
        "económico": "AHORRO",
        "bonito": "ESTILO",
        "innovador": "NUEVO",
    }
    proof = angle_map.get(angle_label.lower(), angle_label.upper() if angle_label else "PLUS")
    defaults = {
        "signal": ("SHOW", "WHY", proof or "PLUS", "BUY"),
        "tech": ("SHOW", "WHY", proof or "TECH", "GO"),
        "beauty": ("SHOW", "GLOW", proof or "LOOK", "TUYO"),
        "home": ("SHOW", "HOME", proof or "PLUS", "PEDIR"),
        "sport": ("SHOW", "GEAR", proof or "EDGE", "YA"),
        "luxury": ("SHOW", "PIEZA", proof or "LUXE", "RESERVA"),
        "candy": ("SHOW", "FUN", proof or "CUTE", "YA"),
        "citrus": ("SHOW", "FRESH", proof or "ZING", "PEDIR"),
        "ocean": ("SHOW", "FLOW", proof or "CALM", "GO"),
        "ink": ("SHOW", "BOLD", proof or "INK", "BUY"),
        "pastel": ("SHOW", "SOFT", proof or "DREAM", "TUYO"),
        "neon": ("SHOW", "LIT", proof or "NEON", "GO"),
        "editorial": ("SHOW", "LOOK", proof or "EDIT", "SHOP"),
        "mono": ("SHOW", "FORM", proof or "PURE", "BUY"),
    }
    a, b, c, d = defaults.get(style_key, defaults["signal"])

    # Overlay emphasis de MIIA → etiqueta de proof más fiel al guion
    emphasis = captions.get("emphasis_words")
    if isinstance(emphasis, list) and emphasis:
        word = punch_line(str(emphasis[0]), 12).upper()
        if word and is_useful_line(word, 3):
            c = word
    cap_style = punch_line(captions.get("style") or "", 14).upper()
    if cap_style and is_useful_line(cap_style, 4):
        b = cap_style[:14]
    cta_chip = punch_line(brand.get("cta") or prompt.get("cta") or "", 10).upper()
    if cta_chip and is_useful_line(cta_chip, 3):
        d = cta_chip[:14]

    return {"open": a[:18], "hero": b[:14], "proof": c[:16], "close": d[:14]}


def bullets_from_product(product: dict, prompt: dict) -> list[str]:
    """3 bullets: overlays MIIA primero, luego valor/ángulo, luego specs de ficha."""
    items: list[str] = []
    analysis = prompt.get("analysis") if isinstance(prompt.get("analysis"), dict) else {}

    def push(raw: object, limit: int = 48) -> None:
        line = punch_line(raw or "", limit)
        if is_useful_line(line, 10) and line not in items and len(items) < 3:
            items.append(line)

    # 1) Segmentos MIIA (text_on_screen) — el copy que debe verse
    prefer_types = ("value", "proof", "benefit", "solution", "demo", "problem")
    segments = prompt.get("segments") or []
    if isinstance(segments, list):
        typed = [s for s in segments if isinstance(s, dict)]
        for want in prefer_types:
            for seg in typed:
                if str(seg.get("type") or "").lower() != want:
                    continue
                push(seg.get("text_on_screen") or seg.get("voiceover") or "")
                if len(items) >= 3:
                    return items[:3]
        for seg in typed:
            push(seg.get("text_on_screen") or "")
            if len(items) >= 3:
                return items[:3]

    push(analysis.get("value_prop") or analysis.get("product_angle") or "")
    angle = punch_line(analysis.get("angle_label") or analysis.get("angle_type") or "", 16)
    if angle:
        push(f"Porque es {angle.lower()}")

    details = product.get("details") or []
    if isinstance(details, list) and len(items) < 3:
        for row in details:
            if isinstance(row, dict):
                label = clean_text(row.get("label") or row.get("name") or "", 28)
                value = clean_text(row.get("value") or row.get("text") or "", 32)
                if label and value and not NOISE_DETAIL_RE.search(f"{label} {value}"):
                    push(f"{label}: {value}")
            else:
                push(row)
            if len(items) >= 3:
                break

    for d in ("Solución simple al problema", "Se nota la diferencia", "Listo para pedir hoy"):
        if len(items) >= 3:
            break
        if d not in items:
            items.append(d)
    return items[:3]


def script_beats(prompt: dict, product: dict, hook: str) -> list[str]:
    """4 beats on-screen: guion MIIA (segments/beats) manda; scrape solo rellena huecos."""
    beats: list[str] = []
    analysis = prompt.get("analysis") if isinstance(prompt.get("analysis"), dict) else {}

    def push(raw: object, limit: int = 52, front: bool = False) -> None:
        line = punch_line(raw or "", limit)
        if not is_useful_line(line):
            return
        if line in beats:
            return
        if front:
            beats.insert(0, line)
        else:
            beats.append(line)

    # 1) Beats ya derivados de segmentos MIIA en Laravel
    stored = analysis.get("beats")
    if isinstance(stored, list) and stored:
        for b in stored:
            push(b)
            if len(beats) >= 4:
                return beats[:4]

    # 2) Overlays por tipo de segmento (orden de narrativa)
    type_order = ("hook", "problem", "solution", "value", "proof", "demo", "benefit", "cta")
    segments = prompt.get("segments") or []
    typed = [s for s in segments if isinstance(s, dict)] if isinstance(segments, list) else []
    by_type: dict[str, str] = {}
    for seg in typed:
        stype = str(seg.get("type") or "segment").lower()
        line = punch_line(seg.get("text_on_screen") or seg.get("voiceover") or "", 52)
        if is_useful_line(line) and stype not in by_type:
            by_type[stype] = line
    for stype in type_order:
        if stype in by_type:
            push(by_type[stype])
            if len(beats) >= 4:
                return beats[:4]
    for seg in typed:
        push(seg.get("text_on_screen") or seg.get("voiceover") or "")
        if len(beats) >= 4:
            return beats[:4]

    # 3) Campos analysis / hook MIIA
    if hook:
        push(hook, front=True)
    push(analysis.get("problem") or "")
    push(analysis.get("value_prop") or analysis.get("product_angle") or "")
    push(analysis.get("summary") or "")

    angle = (analysis.get("angle_type") or analysis.get("angle_label") or "").lower()
    name_short = punch_line(product.get("name") or "esto", 28)
    fillers = {
        "economico": ["Más por menos, sin rodeos", "Calidad real a buen precio", "Pídelo hoy y ahorra"],
        "bonito": ["Se ve premium al instante", "Diseño que se nota de cerca", "Dale ese upgrade visual"],
        "innovador": [
            f"{name_short} lo hace fácil" if len(name_short) > 6 else "Una solución más inteligente",
            "Simple, rápido, al grano",
            "Pruébalo hoy",
        ],
    }
    for cand in fillers.get(angle, fillers["innovador"]):
        if len(beats) >= 4:
            break
        push(cand)
    while len(beats) < 4:
        beats.append(
            ["Resuelve el problema", "Se siente innovador", "Fácil desde el día uno", "Cómpralo ahora"][len(beats)]
        )
    return beats[:4]


def script_sentences(prompt: dict, limit: int = 4) -> list[str]:
    """Guion completo (prompt.script): líneas on-screen derivadas del guion.

    Divide por saltos de línea o fin de frase y reparte hasta `limit` líneas
    (una por beat: open / hero / proof / close). Vacío si no hay guion útil.
    """
    raw = str(prompt.get("script") or "").strip()
    if not raw:
        return []
    lines: list[str] = []
    for part in re.split(r"[\n.;]+", raw):
        line = punch_line(part, 54)
        if not is_useful_line(line, 8):
            continue
        if line in lines:
            continue
        lines.append(line)
        if len(lines) >= limit:
            break
    return lines


def load_video_plan(job_dir: Path) -> dict | None:
    """Carga video_plan.json (schema multidrop.hyperframes.plan.v1) si es válido.

    Si no existe o no cumple el contrato, devuelve None y el pipeline usa el
    flujo anterior (product.json + prompt.json) sin cambios.
    """
    plan_path = job_dir / "video_plan.json"
    if not plan_path.is_file():
        return None
    try:
        plan = json.loads(plan_path.read_text(encoding="utf-8"))
    except (json.JSONDecodeError, OSError):
        return None
    if not isinstance(plan, dict) or plan.get("schema") != "multidrop.hyperframes.plan.v1":
        return None
    if not isinstance(plan.get("scenes"), list) or not plan.get("scenes"):
        return None
    return plan


def plan_copy(plan: dict) -> dict:
    """Extrae el copy/creative del plan validado (audience/tone/hook/benefit/CTA/features)."""
    creative = plan.get("creative") or {}
    hook = clean_text(creative.get("hook") or "", 90)
    cta = punch_line(creative.get("cta") or "Compra ahora", 22)
    benefit = clean_text(creative.get("mainBenefit") or "", 120)

    bullets: list[str] = []
    open_beat = ""
    for scene in plan.get("scenes") or []:
        if not isinstance(scene, dict):
            continue
        purpose = str(scene.get("purpose") or "").lower()
        for el in scene.get("elements") or []:
            if not isinstance(el, dict):
                continue
            etype = str(el.get("type") or "").lower()
            if etype == "feature":
                t = clean_text(el.get("text") or "", 46)
                if is_useful_line(t, 6) and t not in bullets:
                    bullets.append(t)
            elif etype == "feature_list":
                for item in el.get("items") or []:
                    t = clean_text(str(item), 46)
                    if is_useful_line(t, 6) and t not in bullets:
                        bullets.append(t)
            if not open_beat and purpose in ("hook", "intro", "open", "reveal") and etype in ("headline", "kinetic_subtitle"):
                open_beat = punch_line(el.get("text") or "", 42)

    return {
        "hook": hook,
        "cta": cta,
        "main_benefit": benefit,
        "bullets": bullets[:3],
        "open_beat": open_beat,
        "audience": clean_text(creative.get("audience") or "", 40),
        "tone": clean_text(creative.get("tone") or "", 24),
    }


def write_project(job_dir: Path, project_dir: Path) -> dict:
    product = json.loads((job_dir / "product.json").read_text(encoding="utf-8"))
    prompt = {}
    prompt_path = job_dir / "prompt.json"
    if prompt_path.is_file():
        prompt = json.loads(prompt_path.read_text(encoding="utf-8"))

    options_path = job_dir / "options.json"
    if options_path.is_file():
        try:
            options = json.loads(options_path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            options = {}
        if isinstance(options, dict) and options.get("visual_style") and not product.get("visual_style"):
            product["visual_style"] = options["visual_style"]

    images_src = job_dir / "images"
    assets = project_dir / "public" / "media"
    if assets.exists():
        shutil.rmtree(assets, ignore_errors=True)
    assets.mkdir(parents=True, exist_ok=True)

    image_files: list[str] = []
    if images_src.is_dir():
        for name in sorted(images_src.iterdir()):
            if not name.is_file():
                continue
            if name.suffix.lower() not in {".jpg", ".jpeg", ".png", ".webp", ".gif"}:
                continue
            dest_name = f"img{len(image_files) + 1}{name.suffix.lower()}"
            shutil.copy2(name, assets / dest_name)
            image_files.append(f"public/media/{dest_name}")
            if len(image_files) >= 4:
                break

    videos_src = job_dir / "videos"
    video_files: list[str] = []
    if videos_src.is_dir():
        for name in sorted(videos_src.iterdir()):
            if not name.is_file():
                continue
            if name.suffix.lower() not in {".mp4", ".webm", ".mov", ".m4v"}:
                continue
            dest_name = f"vid{len(video_files) + 1}{name.suffix.lower()}"
            shutil.copy2(name, assets / dest_name)
            video_files.append(f"public/media/{dest_name}")
            if len(video_files) >= 2:
                break

    for listed in product.get("product_videos") or []:
        listed_name = str(listed).strip()
        if not listed_name:
            continue
        src = videos_src / listed_name
        if src.is_file() and src.suffix.lower() in {".mp4", ".webm", ".mov", ".m4v"}:
            dest_name = f"vid{len(video_files) + 1}{src.suffix.lower()}"
            dest = assets / dest_name
            if not any(dest_name in v for v in video_files):
                shutil.copy2(src, dest)
                video_files.append(f"public/media/{dest_name}")
            if len(video_files) >= 2:
                break

    seen_v: set[str] = set()
    uniq_videos: list[str] = []
    for v in video_files:
        if v not in seen_v:
            seen_v.add(v)
            uniq_videos.append(v)
    video_files = uniq_videos[:2]
    force_video = bool(video_files) or bool(product.get("has_product_video"))

    name = clean_text(product.get("name") or "Producto", 48)
    store = clean_text(product.get("store_name") or "Multidrop", 32)
    hook = hook_from_sources(prompt, product)
    # Script de cierre: último overlay útil MIIA o script del brief
    script_raw = ""
    segments = prompt.get("segments") or []
    if isinstance(segments, list):
        for seg in reversed(segments):
            if not isinstance(seg, dict):
                continue
            stype = str(seg.get("type") or "").lower()
            if stype in ("cta", "benefit", "value", "proof"):
                cand = punch_line(seg.get("text_on_screen") or seg.get("voiceover") or "", 56)
                if is_useful_line(cand, 10):
                    script_raw = cand
                    break
    if not script_raw:
        script_raw = punch_line(prompt.get("script") or product.get("description") or "", 56)
    script = script_raw if is_useful_line(script_raw, 10) else hook
    price = price_label(product)
    compare = compare_price_label(product)
    cd = creative_direction(prompt)
    brand = cd.get("brand") if isinstance(cd.get("brand"), dict) else {}
    cta = punch_line(
        prompt.get("cta")
        or product.get("cta")
        or brand.get("cta")
        or "Compra ahora",
        22,
    )
    bullets = bullets_from_product(product, prompt)
    beats = script_beats(prompt, product, hook)

    # VideoPlan (schema multidrop.hyperframes.plan.v1): cuando existe, el copy
    # (hook/beneficio/CTAs/features) y la paleta del plan dirigen el render.
    plan = load_video_plan(job_dir)
    plan_over = plan_copy(plan) if plan else {}

    if plan:
        if plan_over["hook"]:
            hook = plan_over["hook"]
        if plan_over["main_benefit"]:
            script = punch_line(plan_over["main_benefit"], 56)
        if plan_over["cta"] and plan_over["cta"] != "Compra ahora":
            cta = plan_over["cta"]
        if plan_over["bullets"]:
            bullets = plan_over["bullets"]
        plan_beats = [
            plan_over["open_beat"] or punch_line(hook, 42) or (beats[0] if beats else hook),
            punch_line(hook, 42) or (beats[1] if len(beats) > 1 else ""),
            punch_line((plan_over["bullets"][0] if plan_over["bullets"] else plan_over["main_benefit"]) or "", 42) or (beats[2] if len(beats) > 2 else ""),
            plan_over["cta"],
        ]
        beats = [b for b in plan_beats if b] or beats

    # Guion completo del brief (prompt.script): cuando el usuario lo escribió,
    # sus frases se reparten entre los 4 beats y mandan sobre el copy del plan.
    guion = script_sentences(prompt)
    if guion:
        script = guion[-1]
        core = list(guion[:3])
        while len(core) < 3:
            core.append(script)
        beats = core + [script]

    lang = clean_text(prompt.get("language") or product.get("language") or "es", 8) or "es"
    rating = product.get("rating")
    try:
        rating_txt = f"{float(rating):.1f}" if rating not in (None, "") else ""
    except (TypeError, ValueError):
        rating_txt = ""

    style_key, palette = pick_palette(product, prompt)
    # El estilo visual forzado del admin (product.json) manda SIEMPRE sobre la
    # paleta del plan; el plan solo define paleta cuando no hay estilo forzado.
    forced_key = str(product.get("visual_style") or prompt.get("visual_style") or "").strip().lower()
    if forced_key not in STYLE_PALETTES and plan and isinstance(plan.get("palette_key"), str) and plan["palette_key"] in STYLE_PALETTES:
        style_key = plan["palette_key"]
        palette = STYLE_PALETTES[style_key]
    labels = scene_labels(style_key, product, prompt)
    energy = miia_energy(prompt, palette)

    # Variación determinista por job: la semilla deriva del job dir, así cada
    # generación nueva rota el orden de imágenes y alterna la geometría de las
    # tarjetas (proof a la izquierda/derecha), pero el resume conserva lo mismo.
    job_seed = int.from_bytes(hashlib.sha256(job_dir.name.encode("utf-8")).digest()[:4], "big")
    ordered = list(image_files)
    if len(ordered) > 1:
        rot = job_seed % len(ordered)
        ordered = ordered[rot:] + ordered[:rot]
    imgs = (ordered + ["", "", "", ""])[:4]
    layout_variant = job_seed % 2
    vids = (video_files + ["", ""])[:2]
    duration = 29.0
    if plan and isinstance(plan.get("video"), dict):
        try:
            plan_dur = float(plan["video"].get("duration") or 29.0)
            duration = max(8.0, min(60.0, plan_dur))
        except (TypeError, ValueError):
            pass
    html = build_html(
        lang=lang,
        store=store,
        name=name,
        hook=hook,
        script=script,
        price=price,
        compare=compare,
        cta=cta,
        bullets=bullets,
        beats=beats,
        rating=rating_txt,
        imgs=imgs,
        vids=vids,
        force_video=force_video,
        duration=duration,
        palette=palette,
        labels=labels,
        style_key=style_key,
        energy=energy,
        layout_variant=layout_variant,
    )

    project_dir.mkdir(parents=True, exist_ok=True)
    (project_dir / "index.html").write_text(html, encoding="utf-8")
    (project_dir / "hyperframes.json").write_text(
        json.dumps(
            {
                "name": re.sub(r"[^a-z0-9-]+", "-", name.lower()).strip("-")[:48] or "product-ad",
                "skill": "product-launch-video",
                "created_by": "multidrop-hyperframes-ads",
            },
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )
    (project_dir / "BRIEF.md").write_text(
        "\n".join(
            [
                "---",
                "workflow: product-launch-video",
                "flow: companion",
                "storyboard: no",
                f"title: {name}",
                "aspect: 9:16",
                f"duration_seconds: {int(duration)}",
                f"style: catalog-pop/{style_key}",
                "---",
                "",
                f"# {name}",
                "",
                f"Promo vertical Catalog Pop para {store}.",
                "Fondo claro, producto grande, tipografía kinetic.",
                "Guion/estilos dirigidos por MIIA (segments + creative_direction).",
                "Video de producto una sola vez en hero si existe.",
                f"Hook: {hook}",
                f"CTA: {cta}",
                f"Energy: {energy}",
                f"Videos: {len(video_files)}",
                f"Palette: {style_key}",
                "",
            ]
        ),
        encoding="utf-8",
    )
    (project_dir / "package.json").write_text(
        json.dumps(
            {
                "name": "multidrop-product-ad",
                "private": True,
                "scripts": {
                    "render": "npx hyperframes@0.8.49 render --quality looks --output ../out/final.mp4",
                    "check": "npx hyperframes@0.8.49 check",
                },
            },
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )

    meta = {
        "name": name,
        "store": store,
        "images": image_files,
        "videos": video_files,
        "force_video": force_video,
        "duration": duration,
        "price": price,
        "cta": cta,
        "layout": "catalog-pop-inset",
        "style": f"catalog-pop/{style_key}",
        "energy": energy,
        "beats": beats,
        "labels": labels,
        "layout_variant": layout_variant,
        "source": "video_plan" if plan else ("miia" if (prompt.get("segments") or creative_direction(prompt)) else "fallback"),
    }
    (job_dir / "composition_meta.json").write_text(
        json.dumps(meta, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    return meta


def product_card(
    src: str,
    el_id: str,
    variant: str = "hero",
    *,
    start: float | None = None,
    duration: float | None = None,
) -> str:
    """Producto siempre dentro de una tarjeta con márgenes; nunca full-bleed."""
    cls = f"product-card product-card--{variant}"
    timing = ""
    if start is not None and duration is not None:
        cls += " clip"
        timing = f' data-start="{start}" data-duration="{duration}" data-track-index="2"'
    if not src:
        return (
            f'<div id="{el_id}-wrap" class="{cls}"{timing}>'
            f'<div id="{el_id}" class="product-fallback" aria-hidden="true"></div></div>'
        )
    return (
        f'<div id="{el_id}-wrap" class="{cls}"{timing}>'
        f'<div class="product-card__shine" aria-hidden="true"></div>'
        f'<div class="product-card__rim" aria-hidden="true"></div>'
        f'<img id="{el_id}" class="product-photo" src="{esc(src)}" alt="" />'
        f"</div>"
    )


def product_visual(
    *,
    video_src: str,
    image_src: str,
    el_id: str,
    variant: str,
    video_start: float,
    video_duration: float,
    prefer_video: bool,
) -> str:
    """Si hay video de producto, SIEMPRE se usa (enmarcado). Si no, foto en tarjeta."""
    cls = f"product-card product-card--{variant} product-card--video clip"
    if prefer_video and video_src:
        return (
            f'<div id="{el_id}-wrap" class="{cls}" '
            f'data-start="{video_start}" data-duration="{video_duration}" data-track-index="2">'
            f'<div class="product-card__shine" aria-hidden="true"></div>'
            f'<div class="product-card__rim" aria-hidden="true"></div>'
            f'<video id="{el_id}" class="product-video" src="{esc(video_src)}" '
            f'muted playsinline></video>'
            f"</div>"
        )
    return product_card(
        image_src,
        el_id,
        variant,
        start=video_start,
        duration=video_duration,
    )


def build_html(
    *,
    lang: str,
    store: str,
    name: str,
    hook: str,
    script: str,
    price: str,
    compare: str,
    cta: str,
    bullets: list[str],
    beats: list[str],
    rating: str,
    imgs: list[str],
    vids: list[str],
    force_video: bool,
    duration: float,
    palette: dict[str, str],
    labels: dict[str, str],
    style_key: str,
    energy: str = "media",
    layout_variant: int = 0,
) -> str:
    b0, b1, b2 = [esc(x) for x in bullets]
    t0, t1, t2, t3 = [esc(x) for x in beats]
    img1, img2, img3, img4 = imgs
    vid1, _vid2 = vids
    alt = bool(layout_variant % 2)
    hero_cls = "hero product-card--hero--alt" if alt else "hero"
    proof_cls = "stack product-card--stack--alt" if alt else "stack"
    thumb_cls = "thumb product-card--thumb--alt" if alt else "thumb"
    copy_alt = " copy-proof--alt" if alt else ""
    proof_from_x = -48 if alt else 48
    proof_rotate = -4 if alt else 4
    proof_rotate_to = -1.6 if alt else 1.6
    use_video = bool(force_video and vid1) or bool(vid1)
    has_media = bool(img1 or vid1)
    price_html = ""
    if price:
        compare_html = f'<span class="price-was">{esc(compare)}</span>' if compare else ""
        price_html = (
            f'<div id="price-row" class="price-row">{compare_html}'
            f'<span id="price" class="price-now">{esc(price)}</span></div>'
        )
    rating_html = (
        f'<div id="rating" class="rating-chip">★ {esc(rating)}</div>' if rating else ""
    )

    # Timing ≤29s — 4 beats claros (sin galería repetitiva)
    # open 0–4.0 | hero 3.7–13.8 | proof 13.5–21.2 | close 20.9–29.0
    T_OPEN, D_OPEN = 0.0, 4.0
    T_HERO, D_HERO = 3.7, 10.1
    T_PROOF, D_PROOF = 13.5, 7.7
    T_CLOSE, D_CLOSE = 20.9, 8.1

    # Intensidad de motion según talento.energy de MIIA
    if energy == "alta":
        ease_in, ease_punch = "power4.out", "back.out(1.8)"
        y_big, y_mid, rot = 72, 36, -3.2
        dur_scale = 0.85
    elif energy == "baja":
        ease_in, ease_punch = "power2.out", "power2.out"
        y_big, y_mid, rot = 36, 18, -1.0
        dur_scale = 1.15
    else:
        ease_in, ease_punch = "power3.out", "back.out(1.4)"
        y_big, y_mid, rot = 56, 28, -2.0
        dur_scale = 1.0

    def t(base: float, offset: float = 0.0) -> str:
        return f"{round(base + offset, 2)}"

    def d(seconds: float) -> str:
        return f"{round(seconds * dur_scale, 2)}"

    hero_visual = product_visual(
        video_src=vid1,
        image_src=img1,
        el_id="hero-photo",
        variant=hero_cls,
        video_start=T_HERO,
        video_duration=D_HERO,
        prefer_video=use_video,
    )
    proof_visual = product_card(
        img2 or img3 or img1,
        "proof-photo",
        proof_cls,
        start=T_PROOF,
        duration=D_PROOF,
    )
    close_visual = product_card(
        img1 or img2 or img4,
        "close-photo",
        thumb_cls,
        start=T_CLOSE,
        duration=D_CLOSE,
    )

    p = palette
    lbl_open = esc(labels["open"])
    lbl_hero = esc(labels["hero"])
    lbl_proof = esc(labels["proof"])
    lbl_close = esc(labels["close"])
    style_tag = esc(p.get("label") or style_key.upper())

    close_price = (
        f'<p id="close-price" class="price-now close-price">{esc(price)}</p>' if price else ""
    )

    return f"""<!doctype html>
<html lang="{esc(lang)}">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=1080, height=1920" />
    <title>{esc(name)} — HyperFrames Ad</title>
    <script src="https://cdn.jsdelivr.net/npm/gsap@3.14.2/dist/gsap.min.js"></script>
    <style>
      /* Catalog Pop — light magazine kinetic (variante: {esc(style_key)}) */
      :root {{
        --bg0: {p["bg0"]};
        --bg1: {p["bg1"]};
        --ink: {p["ink"]};
        --muted: {p["muted"]};
        --accent: {p["accent"]};
        --accent2: {p["accent2"]};
        --panel: {p["panel"]};
        --panel-ink: {p["panel_ink"]};
        --line: {p["line"]};
        --glow: {p["glow"]};
      }}
      * {{ box-sizing: border-box; }}
      body {{
        margin: 0;
        background: var(--bg0);
        color: var(--ink);
        font-family: "Fraunces", "Georgia", serif;
      }}
      #root {{
        position: relative;
        width: 100%;
        height: 100%;
        overflow: hidden;
        background:
          radial-gradient(80% 50% at 80% 0%, var(--glow), transparent 55%),
          radial-gradient(60% 40% at 0% 100%, rgba(15,118,110,0.08), transparent 50%),
          linear-gradient(180deg, var(--bg1) 0%, var(--bg0) 100%);
      }}
      .clip {{
        position: absolute;
        inset: 0;
        overflow: hidden;
      }}
      .atmosphere {{
        position: absolute;
        inset: 0;
        pointer-events: none;
      }}
      .slash {{
        position: absolute;
        width: 140%;
        height: 8px;
        left: -20%;
        background: linear-gradient(90deg, transparent, var(--accent), transparent);
        opacity: 0.35;
        transform: rotate(-12deg);
        z-index: 1;
      }}
      .slash-a {{ top: 28%; }}
      .slash-b {{ top: 72%; opacity: 0.18; }}
      .orb {{
        position: absolute;
        border-radius: 50%;
        filter: blur(2px);
        z-index: 0;
      }}
      .orb-a {{
        width: 520px;
        height: 520px;
        top: -140px;
        right: -180px;
        background: radial-gradient(circle, var(--glow), transparent 68%);
      }}
      .orb-b {{
        width: 380px;
        height: 380px;
        bottom: 60px;
        left: -160px;
        background: radial-gradient(circle, rgba(15,118,110,0.10), transparent 70%);
      }}
      .grid-dots {{
        position: absolute;
        inset: 0;
        opacity: 0.18;
        background-image: radial-gradient(rgba(26,21,17,0.18) 1px, transparent 1px);
        background-size: 32px 32px;
        mask-image: linear-gradient(180deg, transparent, #000 18%, #000 82%, transparent);
        z-index: 1;
      }}
      .accent-bar {{
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 14px;
        background: var(--accent);
        z-index: 4;
      }}
      .ghost-word {{
        position: absolute;
        left: -2%;
        bottom: 16%;
        font-family: "Archivo Black", sans-serif;
        font-size: 200px;
        font-weight: 400;
        line-height: 0.8;
        letter-spacing: -0.04em;
        color: rgba(26,21,17,0.05);
        text-transform: uppercase;
        white-space: nowrap;
        z-index: 1;
        pointer-events: none;
      }}
      .grain {{
        position: absolute;
        inset: 0;
        opacity: 0.04;
        background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
        mix-blend-mode: multiply;
        z-index: 30;
        pointer-events: none;
      }}
      .top-bar {{
        position: absolute;
        top: 64px;
        left: 56px;
        right: 56px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        z-index: 6;
      }}
      .brand {{
        font-family: "Space Mono", monospace;
        font-size: 22px;
        letter-spacing: 0.18em;
        text-transform: uppercase;
        font-weight: 400;
        color: var(--ink);
      }}
      .tag {{
        font-family: "Space Mono", monospace;
        font-size: 18px;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: var(--accent);
        border: 2px solid var(--accent);
        padding: 10px 16px;
        background: rgba(255,255,255,0.65);
      }}
      .product-card {{
        position: absolute;
        z-index: 3;
        overflow: hidden;
        background: var(--panel);
        border-radius: 28px;
        box-shadow:
          0 24px 60px rgba(26,21,17,0.14),
          0 0 0 1px rgba(26,21,17,0.06);
      }}
      .product-card--hero {{
        left: 8%;
        right: 8%;
        top: 180px;
        height: 880px;
        max-height: 46%;
        transform: rotate(-0.6deg);
      }}
      .product-card--stack {{
        right: 7%;
        left: auto;
        width: 54%;
        top: 210px;
        height: 720px;
        max-height: 40%;
        transform: rotate(1.2deg);
      }}
      .product-card--thumb {{
        top: auto;
        bottom: 500px;
        left: 26%;
        right: 26%;
        height: 360px;
        max-height: 20%;
        transform: rotate(-0.5deg);
      }}
      .product-card--hero--alt {{
        left: 14%;
        right: 14%;
        height: 820px;
        transform: rotate(0.6deg);
      }}
      .product-card--stack--alt {{
        left: 7%;
        right: auto;
        transform: rotate(-1.2deg);
      }}
      .product-card--thumb--alt {{
        left: 10%;
        right: 72%;
        bottom: 560px;
      }}
      .product-card__shine {{
        position: absolute;
        inset: 0;
        background: linear-gradient(125deg, rgba(255,255,255,0.55), transparent 42%);
        z-index: 2;
        pointer-events: none;
      }}
      .product-card__rim {{
        position: absolute;
        inset: 18px;
        border: 1px solid rgba(26,21,17,0.06);
        border-radius: 18px;
        z-index: 2;
        pointer-events: none;
      }}
      .product-photo {{
        position: absolute;
        inset: 40px;
        width: calc(100% - 80px);
        height: calc(100% - 80px);
        object-fit: contain;
        display: block;
        z-index: 1;
      }}
      .product-video {{
        position: absolute;
        inset: 28px;
        width: calc(100% - 56px);
        height: calc(100% - 56px);
        object-fit: contain;
        display: block;
        z-index: 1;
        background: #f7f3ec;
        border-radius: 14px;
      }}
      .product-card--video {{
        background: #fff;
      }}
      .product-fallback {{
        position: absolute;
        inset: 0;
        background: linear-gradient(145deg, var(--panel), #e8e0d4);
      }}
      .copy-open {{
        position: absolute;
        left: 56px;
        right: 56px;
        top: 420px;
        z-index: 6;
      }}
      .copy-hero {{
        position: absolute;
        left: 56px;
        right: 56px;
        bottom: 100px;
        z-index: 6;
      }}
      .copy-proof {{
        position: absolute;
        left: 56px;
        right: 52%;
        top: 280px;
        z-index: 6;
      }}
      .copy-proof--alt {{
        left: auto;
        right: 56px;
      }}
      .kicker {{
        display: block;
        margin: 0 0 18px;
        font-family: "Space Mono", monospace;
        font-size: 20px;
        letter-spacing: 0.22em;
        text-transform: uppercase;
        color: var(--accent);
      }}
      h1, h2 {{
        margin: 0;
        font-family: "Archivo Black", sans-serif;
        font-weight: 400;
        letter-spacing: -0.02em;
        text-transform: uppercase;
        color: var(--ink);
      }}
      h1.open-title {{
        font-size: 92px;
        line-height: 0.92;
        max-width: 11ch;
        margin-bottom: 28px;
      }}
      h1.hero-title {{
        font-size: 64px;
        line-height: 0.95;
        max-width: 13ch;
        margin-bottom: 16px;
      }}
      h2.close-title {{
        font-size: 56px;
        line-height: 0.95;
        max-width: 12ch;
      }}
      .lede {{
        margin: 0;
        font-size: 30px;
        line-height: 1.35;
        font-weight: 500;
        color: var(--muted);
        max-width: 20ch;
        font-family: "Montserrat", sans-serif;
      }}
      .lede-lg {{
        font-size: 34px;
        max-width: 18ch;
        color: var(--ink);
      }}
      .price-row {{
        display: flex;
        align-items: baseline;
        gap: 16px;
        margin-top: 22px;
      }}
      .price-now {{
        font-family: "Archivo Black", sans-serif;
        font-size: 54px;
        font-weight: 400;
        letter-spacing: -0.02em;
        color: var(--accent2);
      }}
      .price-was {{
        font-size: 24px;
        color: rgba(26,21,17,0.35);
        text-decoration: line-through;
        font-family: "Montserrat", sans-serif;
      }}
      .rating-chip {{
        display: inline-flex;
        margin-top: 14px;
        font-family: "Space Mono", monospace;
        font-size: 18px;
        letter-spacing: 0.08em;
        color: var(--muted);
      }}
      .features {{
        display: grid;
        gap: 0;
        margin-top: 36px;
      }}
      .feature {{
        display: flex;
        gap: 18px;
        align-items: flex-start;
        padding: 20px 0;
        border-top: 1px solid var(--line);
      }}
      .feature:last-child {{
        border-bottom: 1px solid var(--line);
      }}
      .dot {{
        font-family: "Archivo Black", sans-serif;
        font-size: 28px;
        color: var(--accent);
        line-height: 1;
        flex: 0 0 auto;
        margin-top: 2px;
      }}
      .feature p {{
        margin: 0;
        font-size: 28px;
        line-height: 1.25;
        font-weight: 700;
        font-family: "Montserrat", sans-serif;
        color: var(--ink);
      }}
      .proof-caption {{
        margin: 0 0 8px;
        font-family: "Archivo Black", sans-serif;
        font-size: 44px;
        line-height: 1.05;
        text-transform: uppercase;
        letter-spacing: -0.02em;
        max-width: 10ch;
        color: var(--ink);
      }}
      .endcard {{
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        justify-content: flex-end;
        align-items: center;
        text-align: center;
        gap: 18px;
        padding: 0 56px 140px;
        z-index: 6;
      }}
      .cta {{
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 440px;
        margin-top: 10px;
        padding: 28px 48px;
        border-radius: 999px;
        background: var(--accent);
        color: #fff;
        font-family: "Archivo Black", sans-serif;
        font-size: 36px;
        font-weight: 400;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        box-shadow: 0 16px 36px rgba(226,61,45,0.28);
      }}
      .end-sub {{
        margin: 0;
        font-size: 28px;
        color: var(--muted);
        max-width: 20ch;
        font-family: "Montserrat", sans-serif;
      }}
      .close-price {{
        margin: 0;
        font-size: 48px;
      }}
      .store-foot {{
        margin: 6px 0 0;
        font-family: Space Mono, monospace;
        font-size: 16px;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        opacity: 0.55;
      }}
      .style-chip {{
        position: absolute;
        top: 120px;
        right: 56px;
        font-family: Space Mono, monospace;
        font-size: 14px;
        letter-spacing: 0.24em;
        text-transform: uppercase;
        color: var(--muted);
        z-index: 5;
      }}
    </style>
  </head>
  <body>
    <div
      id="root"
      data-composition-id="product-ad"
      data-start="0"
      data-width="1080"
      data-height="1920"
      data-duration="{duration}"
    >
      <div class="grain" aria-hidden="true"></div>

      <!-- 01 OPEN — hook punch, sin producto -->
      <section id="open" class="clip" data-start="{T_OPEN}" data-duration="{D_OPEN}" data-track-index="1">
        <div class="atmosphere">
          <div class="accent-bar"></div>
          <div id="orb-a" class="orb orb-a"></div>
          <div class="grid-dots"></div>
          <div id="slash-a" class="slash slash-a"></div>
          <div id="ghost-open" class="ghost-word">{style_tag}</div>
        </div>
        <div class="top-bar">
          <div id="brand" class="brand">{esc(store)}</div>
          <div id="open-tag" class="tag">{lbl_open}</div>
        </div>
        <div class="style-chip" id="style-chip">{style_tag}</div>
        <div class="copy-open">
          <p id="kicker" class="kicker">Por qué importa</p>
          <h1 id="open-title" class="open-title">{esc(hook)}</h1>
          <p id="open-lede" class="lede lede-lg">{t0}</p>
        </div>
      </section>

      <!-- 02 HERO — producto (video una vez) + nombre + precio -->
      <section id="hero" class="clip" data-start="{T_HERO}" data-duration="{D_HERO}" data-track-index="1">
        <div class="atmosphere">
          <div class="accent-bar"></div>
          <div id="orb-b" class="orb orb-b"></div>
          <div id="slash-b" class="slash slash-b"></div>
        </div>
        <div class="top-bar">
          <div class="brand">{esc(store)}</div>
          <div class="tag">{lbl_hero}</div>
        </div>
        <div class="copy-hero">
          <h1 id="title" class="hero-title">{esc(name)}</h1>
          <p id="hook" class="lede">{t1}</p>
          {price_html}
          {rating_html}
        </div>
      </section>
      {hero_visual}

      <!-- 03 PROOF — layout asimétrico: copy izq + card der -->
      <section id="proof" class="clip" data-start="{T_PROOF}" data-duration="{D_PROOF}" data-track-index="1">
        <div class="atmosphere">
          <div class="accent-bar"></div>
          <div class="grid-dots"></div>
          <div id="ghost-proof" class="ghost-word" style="bottom:8%;font-size:180px;">WHY</div>
        </div>
        <div class="top-bar">
          <div class="brand">{esc(store)}</div>
          <div class="tag">{lbl_proof}</div>
        </div>
        <div class="copy-proof{copy_alt}">
          <p id="proof-caption" class="proof-caption">{t2}</p>
          <div class="features">
            <div id="f1" class="feature"><span class="dot">01</span><p>{b0}</p></div>
            <div id="f2" class="feature"><span class="dot">02</span><p>{b1}</p></div>
            <div id="f3" class="feature"><span class="dot">03</span><p>{b2}</p></div>
          </div>
        </div>
      </section>
      {proof_visual}

      <!-- 04 CLOSE — CTA -->
      <section id="close" class="clip" data-start="{T_CLOSE}" data-duration="{D_CLOSE}" data-track-index="1">
        <div class="atmosphere">
          <div class="accent-bar"></div>
          <div id="orb-c" class="orb orb-a" style="top:auto;bottom:-80px;left:-100px;right:auto;"></div>
          <div id="slash-c" class="slash slash-a" style="top:72%;"></div>
        </div>
        <div class="top-bar">
          <div class="brand">{esc(store)}</div>
          <div class="tag">{lbl_close}</div>
        </div>
        <div class="endcard">
          <p id="close-script" class="end-sub">{esc(script)}</p>
          <h2 id="close-title" class="close-title">{esc(name)}</h2>
          {close_price}
          <div id="cta" class="cta">{esc(cta)}</div>
          <p id="close-store" class="store-foot">{esc(store)}</p>
        </div>
      </section>
      {close_visual}
    </div>
    <script>
      const tl = gsap.timeline({{ paused: true }});
      const hasMedia = {str(has_media).lower()};
      const useVideo = {str(use_video).lower()};
      const energy = "{esc(energy)}";

      // Ambient decor (finite, seek-safe — no infinite repeats)
      tl.fromTo("#orb-a", {{ scale: 0.92, opacity: 0.7 }}, {{ scale: 1.05, opacity: 1, duration: {d(3.6)}, ease: "sine.inOut" }}, 0.0);
      tl.fromTo("#slash-a", {{ x: -80, opacity: 0 }}, {{ x: 0, opacity: 0.55, duration: {d(0.7)}, ease: "{ease_in}" }}, 0.15);

      // Open — intensidad según MIIA talent.energy
      tl.fromTo("#brand", {{ y: -24, opacity: 0 }}, {{ y: 0, opacity: 1, duration: {d(0.45)}, ease: "{ease_in}" }}, 0.12);
      tl.fromTo("#open-tag", {{ x: 24, opacity: 0 }}, {{ x: 0, opacity: 1, duration: {d(0.4)}, ease: "{ease_in}" }}, 0.2);
      tl.fromTo("#style-chip", {{ opacity: 0 }}, {{ opacity: 1, duration: {d(0.35)}, ease: "sine.out" }}, 0.25);
      tl.fromTo("#kicker", {{ y: 18, opacity: 0 }}, {{ y: 0, opacity: 1, duration: {d(0.4)}, ease: "power2.out" }}, 0.35);
      tl.fromTo("#open-title", {{ y: {y_big}, opacity: 0, rotate: {rot} }}, {{ y: 0, opacity: 1, rotate: 0, duration: {d(0.7)}, ease: "{ease_in}" }}, 0.45);
      tl.fromTo("#open-lede", {{ y: {y_mid}, opacity: 0 }}, {{ y: 0, opacity: 1, duration: {d(0.5)}, ease: "power2.out" }}, 0.85);
      tl.fromTo("#ghost-open", {{ x: -40, opacity: 0 }}, {{ x: 0, opacity: 1, duration: {d(1.0)}, ease: "sine.out" }}, 0.2);

      // Hero — product reveal + Ken Burns only on stills
      tl.fromTo("#hero-photo-wrap", {{ y: {y_big}, opacity: 0, rotate: {round(rot * 2, 1)} }}, {{ y: 0, opacity: 1, rotate: -1.2, duration: {d(0.85)}, ease: "{ease_in}" }}, {t(T_HERO, 0.12)});
      if (!useVideo && hasMedia && document.querySelector("#hero-photo") && document.querySelector("#hero-photo").tagName === "IMG") {{
        tl.fromTo("#hero-photo", {{ scale: 1.08 }}, {{ scale: 1, duration: {t(0, D_HERO - 0.4)}, ease: "none" }}, {t(T_HERO, 0.2)});
      }}
      tl.fromTo("#title", {{ y: 32, opacity: 0 }}, {{ y: 0, opacity: 1, duration: {d(0.55)}, ease: "{ease_in}" }}, {t(T_HERO, 0.55)});
      tl.fromTo("#hook", {{ y: 20, opacity: 0 }}, {{ y: 0, opacity: 1, duration: {d(0.45)}, ease: "power2.out" }}, {t(T_HERO, 0.8)});
      if (document.querySelector("#price-row")) {{
        tl.fromTo("#price-row", {{ y: 18, opacity: 0 }}, {{ y: 0, opacity: 1, duration: {d(0.4)}, ease: "power2.out" }}, {t(T_HERO, 1.05)});
      }}
      if (document.querySelector("#rating")) {{
        tl.fromTo("#rating", {{ opacity: 0 }}, {{ opacity: 1, duration: {d(0.35)}, ease: "sine.out" }}, {t(T_HERO, 1.25)});
      }}
      tl.fromTo("#slash-b", {{ x: 60, opacity: 0 }}, {{ x: 0, opacity: 0.28, duration: {d(0.6)}, ease: "power2.out" }}, {t(T_HERO, 0.2)});

      // Proof — staggered benefits + card from right
      tl.fromTo("#proof-photo-wrap", {{ x: {proof_from_x}, opacity: 0, rotate: {proof_rotate} }}, {{ x: 0, opacity: 1, rotate: {proof_rotate_to}, duration: {d(0.7)}, ease: "{ease_in}" }}, {t(T_PROOF, 0.1)});
      tl.fromTo("#proof-caption", {{ y: 24, opacity: 0 }}, {{ y: 0, opacity: 1, duration: {d(0.45)}, ease: "{ease_in}" }}, {t(T_PROOF, 0.35)});
      tl.fromTo("#f1", {{ x: -20, opacity: 0 }}, {{ x: 0, opacity: 1, duration: {d(0.4)}, ease: "power2.out" }}, {t(T_PROOF, 0.55)});
      tl.fromTo("#f2", {{ x: -20, opacity: 0 }}, {{ x: 0, opacity: 1, duration: {d(0.4)}, ease: "power2.out" }}, {t(T_PROOF, 0.75)});
      tl.fromTo("#f3", {{ x: -20, opacity: 0 }}, {{ x: 0, opacity: 1, duration: {d(0.4)}, ease: "power2.out" }}, {t(T_PROOF, 0.95)});
      tl.fromTo("#ghost-proof", {{ opacity: 0 }}, {{ opacity: 1, duration: {d(0.8)}, ease: "sine.out" }}, {t(T_PROOF, 0.15)});

      // Close / CTA
      tl.fromTo("#close-photo-wrap", {{ y: 36, opacity: 0, scale: 0.96 }}, {{ y: 0, opacity: 1, scale: 1, duration: {d(0.6)}, ease: "{ease_in}" }}, {t(T_CLOSE, 0.12)});
      tl.fromTo("#close-script", {{ y: 18, opacity: 0 }}, {{ y: 0, opacity: 1, duration: {d(0.4)}, ease: "power2.out" }}, {t(T_CLOSE, 0.35)});
      tl.fromTo("#close-title", {{ y: 28, opacity: 0 }}, {{ y: 0, opacity: 1, duration: {d(0.5)}, ease: "{ease_in}" }}, {t(T_CLOSE, 0.55)});
      if (document.querySelector("#close-price")) {{
        tl.fromTo("#close-price", {{ scale: 0.92, opacity: 0 }}, {{ scale: 1, opacity: 1, duration: {d(0.4)}, ease: "{ease_punch}" }}, {t(T_CLOSE, 0.8)});
      }}
      tl.fromTo("#cta", {{ y: 24, opacity: 0, scale: 0.94 }}, {{ y: 0, opacity: 1, scale: 1, duration: {d(0.5)}, ease: "{ease_punch}" }}, {t(T_CLOSE, 1.05)});
      tl.fromTo("#close-store", {{ opacity: 0 }}, {{ opacity: 0.55, duration: {d(0.35)}, ease: "sine.out" }}, {t(T_CLOSE, 1.4)});
      tl.fromTo("#slash-c", {{ x: -50, opacity: 0 }}, {{ x: 0, opacity: 0.45, duration: {d(0.55)}, ease: "power2.out" }}, {t(T_CLOSE, 0.2)});

      window.__timelines["product-ad"] = tl;
    </script>
  </body>
</html>
"""


if __name__ == "__main__":
    import sys

    job = Path(sys.argv[1]).resolve()
    project = job / "project"
    write_project(job, project)
    print(json.dumps({"ok": True, "project": str(project)}))
