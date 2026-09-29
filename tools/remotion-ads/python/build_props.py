#!/usr/bin/env python3
"""Construye edit_plan equitativo si no hay MIIA, y composition-props.json para Remotion."""
from __future__ import annotations

import argparse
import json
import math
import random
import shutil
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import commun as c
import styles as st


TRANSITIONS = ("fade", "slide_left", "wipe", "zoom_in", "slide_right")


def job_rng(job_dir: Path) -> random.Random:
    # A job keeps its edit stable on retry; UUID job folders vary between generations.
    return random.Random(Path(job_dir).name)


LEDGER_MUSICA = c.RAIZ / "public" / "music" / "library.json"


def copiar_musica(src: Path, dest: Path) -> None:
    """Copia la pista al public del job, siempre como mp3.

    Las pistas de `public/music` ya están normalizadas a -18 LUFS por
    `scripts/prepare_music.py`; cualquier otra (por ejemplo el `music.mp3`
    empaquetado en el zip remoto) se normaliza aquí para que el mix se
    escuche igual en local y en el bridge.
    """
    ledger = json.loads(LEDGER_MUSICA.read_text(encoding="utf-8")) if LEDGER_MUSICA.is_file() else {}
    if src.name in ledger.get("tracks", {}):
        shutil.copy2(src, dest)
        return
    ffmpeg = c.buscar_binario("ffmpeg")
    if not ffmpeg:
        shutil.copy2(src, dest)
        return
    subprocess.run(
        [ffmpeg, "-y", "-v", "error", "-i", str(src),
         "-af", "loudnorm=I=-18:TP=-1.5:LRA=11",
         "-c:a", "libmp3lame", "-b:a", "128k", str(dest)],
        check=True,
    )


def sortear_slots_video(rng: random.Random, total: int, cuantos: int, abrir_con_foto: bool) -> set[int]:
    """Elige al azar dónde caen los clips de video, sin dos videos seguidos."""
    if cuantos <= 0 or total <= 0:
        return set()
    cuantos = min(cuantos, total)
    candidatos = list(range(1, total)) if abrir_con_foto else list(range(total))
    if not candidatos:
        return {0}

    for _ in range(40):
        elegidos = set(rng.sample(candidatos, min(cuantos, len(candidatos))))
        if len(elegidos) == cuantos and all(i + 1 not in elegidos for i in elegidos):
            return elegidos

    # Fallback determinista: repartir de forma dispersa desde una posición al azar.
    elegidos = set()
    for i in rng.sample(candidatos, len(candidatos)):
        if len(elegidos) >= cuantos:
            break
        if i - 1 not in elegidos and i + 1 not in elegidos:
            elegidos.add(i)
    return elegidos


def sortear_pool(rng: random.Random, pool: list[dict], anterior: str) -> dict:
    """Saca un medio del pool evitando repetir el que se acaba de mostrar."""
    if not pool:
        raise RuntimeError("No quedan medios para el plan de cortes.")
    if len(pool) > 1 and Path(pool[0]["path"]).name.lower() == anterior:
        pool.append(pool.pop(0))
    elegido = pool.pop(0)
    return elegido


def fallback_edit_plan(job_dir: Path, duration: float, preset: str) -> dict:
    media = c.list_media(job_dir)
    if not media:
        raise RuntimeError("El job no tiene images/ ni videos/.")

    duration = max(1.0, float(duration))
    images = [m for m in media if m.get("media_type") == "image"]
    videos = [m for m in media if m.get("media_type") == "video"]
    rng = job_rng(job_dir)
    rng.shuffle(images)
    rng.shuffle(videos)

    estilo = st.resolver(preset)
    seg_por_clip, ratio_min, ratio_max = st.ritmo(estilo)
    transiciones = [t for t in (estilo.get("transitions") or TRANSITIONS) if t != "jump_cut"] or list(TRANSITIONS)

    # Ritmo: el estilo marca clips más cortos/agresivos o más largos/sobrios.
    objetivo = max(3, min(14, int(math.ceil(duration / seg_por_clip))))
    pool = list(images) + list(videos)
    rng.shuffle(pool)
    for _ in range(objetivo + 4):
        if len(pool) >= objetivo:
            break
        pool.extend(videos or images)
        rng.shuffle(pool)

    total = max(objetivo, 3)
    if videos:
        ratio = rng.uniform(ratio_min, ratio_max)
        cuantos = max(1, int(round(total * ratio)))
        slots = sortear_slots_video(rng, total, min(cuantos, max(1, (total - 1) // 2 or 1)), bool(images))
    else:
        slots = set()

    prompt = {}
    pp = job_dir / "prompt.json"
    if pp.is_file():
        raw = c.leer_json(pp)
        if isinstance(raw, dict):
            prompt = raw
    segments = prompt.get("segments") if isinstance(prompt.get("segments"), list) else []
    if segments:
        rng.shuffle(segments)

    cola = list(pool)
    medio_anterior = ""
    picks: list[dict] = []
    for i in range(total):
        if i in slots and videos:
            elegida = sortear_pool(rng, videos, medio_anterior)
        elif images and cola:
            elegida = sortear_pool(rng, images, medio_anterior)
        else:
            elegida = sortear_pool(rng, cola or videos or images, medio_anterior)
        medio_anterior = Path(elegida["path"]).name.lower()
        picks.append(elegida)

    slot = duration / len(picks)

    clips = []
    t = 0.0
    for i, m in enumerate(picks):
        end = duration if i == len(picks) - 1 else round(t + slot, 3)
        seg = segments[i] if i < len(segments) and isinstance(segments[i], dict) else {}
        tr_raw = str(seg.get("transition") or "").lower()
        if "fade" in tr_raw:
            transition = "fade"
        elif "slide_left" in tr_raw:
            transition = "slide_left"
        elif "slide_right" in tr_raw:
            transition = "slide_right"
        elif "wipe" in tr_raw:
            transition = "wipe"
        elif "zoom" in tr_raw:
            transition = "zoom_in"
        else:
            transition = rng.choice(transiciones)
        ken = "none" if m.get("media_type") == "video" else ("in" if i % 2 == 0 else "out")
        if m.get("media_type") == "video":
            transition = "jump_cut"
        clips.append({
            "start_s": round(t, 3),
            "end_s": round(end, 3),
            "media": m["path"],
            "media_type": m["media_type"],
            "ken_burns": ken,
            "transition": transition,
            "text_on_screen": str(seg.get("text_on_screen") or ""),
        })
        t = end

    return {
        "preset": preset,
        "style": estilo["id"],
        "duration_s": round(duration, 3),
        "clips": clips,
        "cta_text": str(
            (prompt.get("analysis") or {}).get("cta")
            if isinstance(prompt.get("analysis"), dict)
            else ""
        )
        or "Compra ahora",
        "source": "fallback",
    }


def build_composition_props(job_dir: Path, edit_plan: dict) -> dict:
    """Copia assets a un directorio aislado por job para staticFile()."""
    job_dir = Path(job_dir)
    pub = c.public_job_dir(job_dir)
    if pub.exists():
        shutil.rmtree(pub, ignore_errors=True)
    pub.mkdir(parents=True, exist_ok=True)
    (pub / "images").mkdir(exist_ok=True)
    (pub / "videos").mkdir(exist_ok=True)

    voice = c.find_voice(job_dir)
    music = c.find_music(job_dir)
    voice_src = ""
    music_src = None
    if voice:
        dest = pub / "voice.mp3"
        shutil.copy2(voice, dest)
        voice_src = f"_jobs/{job_dir.name}/voice.mp3"
    catalog_path = job_dir / "music_catalog.json"
    if not catalog_path.is_file():
        catalog_path = c.RAIZ / "music_catalog.json"
    catalog = json.loads(catalog_path.read_text(encoding="utf-8")) if catalog_path.is_file() else {"tracks": []}
    selection_path = job_dir / "music.json"
    selection = c.leer_json(selection_path) if selection_path.is_file() else {}
    selected_id = str(selection.get("music_id") or "random")
    music = None
    attribution = None
    if selected_id == "random" and catalog.get("tracks"):
        selected = job_rng(job_dir).choice(catalog["tracks"])
    else:
        selected = next((track for track in catalog.get("tracks", []) if track.get("id") == selected_id), None)
    if selected:
        packaged_music = job_dir / "music.mp3"
        music = packaged_music if packaged_music.is_file() else c.RAIZ / "public" / selected["file"]
        attribution = selected.get("credit")
    if selected_id == "none":
        music = None
    if music and music.is_file():
        dest = pub / "music.mp3"
        copiar_musica(music, dest)
        music_src = f"_jobs/{job_dir.name}/music.mp3"
    else:
        music = None

    path_to_url: dict[str, str] = {}
    for m in c.list_media(job_dir):
        src = Path(m["path"])
        if m["media_type"] == "image":
            dest = pub / "images" / src.name
            rel_url = f"_jobs/{job_dir.name}/images/" + src.name
        else:
            dest = pub / "videos" / src.name
            rel_url = f"_jobs/{job_dir.name}/videos/" + src.name
        if not dest.exists():
            shutil.copy2(src, dest)
        path_to_url[str(src.resolve())] = rel_url
        path_to_url[str(src)] = rel_url

    trans = c.trabajo(job_dir, "transcripcion.json")
    words = []
    if trans.is_file():
        words = c.leer_json(trans).get("palabras") or []

    clips = []
    for clip in edit_plan.get("clips") or []:
        media_path = Path(clip["media"])
        key = str(media_path.resolve())
        url = path_to_url.get(key) or path_to_url.get(str(media_path))
        if not url:
            name = media_path.name
            folder = "videos" if (clip.get("media_type") == "video") else "images"
            dest = pub / folder / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            if media_path.is_file():
                shutil.copy2(media_path, dest)
            url = f"_jobs/{job_dir.name}/{folder}/{name}"
        clips.append({
            "start_s": float(clip["start_s"]),
            "end_s": float(clip["end_s"]),
            "media": url,
            "media_type": clip.get("media_type") or "image",
            "ken_burns": clip.get("ken_burns") or "in",
            "transition": clip.get("transition") or "jump_cut",
            "text_on_screen": clip.get("text_on_screen") or "",
            "videoStartFrame": int(round(float(clip.get("video_start_s") or 0) * 30)),
        })

    duration = float(edit_plan.get("duration_s") or 5)
    if voice:
        d = c.duracion_audio(voice)
        if d > 0:
            duration = d

    estilo = st.resolver(edit_plan.get("style") or edit_plan.get("preset") or "product_presenter")

    return {
        "preset": estilo["id"],
        "styleId": estilo["id"],
        "style": estilo,
        "voiceSrc": voice_src,
        "musicSrc": music_src,
        "musicVolume": float(selection.get("music_volume", 0.3)),
        "musicAttribution": attribution,
        "words": words,
        "clips": clips,
        "durationInSeconds": round(duration, 3),
        "ctaText": edit_plan.get("cta_text") or "",
    }


def ensure_edit_plan(job_dir: Path, preset: str, force_fallback: bool = False) -> dict:
    job_dir = Path(job_dir)
    out = c.trabajo(job_dir, "edit_plan.json")
    if out.is_file() and not force_fallback:
        plan = c.leer_json(out)
        if isinstance(plan, dict) and plan.get("clips"):
            estilo = st.resolver(plan.get("style") or plan.get("preset") or preset)
            if not plan.get("style") or not st.existe(str(plan.get("style") or "")):
                plan["style"] = estilo["id"]
                plan["preset"] = estilo["id"]
                c.escribir_json(out, plan)
            return plan

    trans = c.trabajo(job_dir, "transcripcion.json")
    duration = 5.0
    if trans.is_file():
        duration = float(c.leer_json(trans).get("duracion_audio") or 5)
    voice = c.find_voice(job_dir)
    if voice:
        d = c.duracion_audio(voice)
        if d > 0:
            duration = d

    plan = fallback_edit_plan(job_dir, duration, preset)
    c.escribir_json(out, plan)
    return plan


def write_props(job_dir: Path, preset: str = "product_presenter") -> Path:
    job_dir = Path(job_dir)
    estilo = st.resolver(preset)
    plan = ensure_edit_plan(job_dir, estilo["id"])
    plan = ensure_all_media_in_plan(job_dir, plan)
    plan["preset"] = estilo["id"]
    plan["style"] = estilo["id"]
    props = build_composition_props(job_dir, plan)
    out = c.trabajo(job_dir, "composition-props.json")
    c.escribir_json(out, props)
    # Persistir plan ya corregido (con videos forzados si faltaban)
    c.escribir_json(c.trabajo(job_dir, "edit_plan.json"), plan)
    print(f"Props [{estilo['id']}] → {out}")
    return out


def ensure_all_media_in_plan(job_dir: Path, plan: dict) -> dict:
    """Coloca los medios al azar en cada generación: orden, tipo y tiempos."""
    if not isinstance(plan, dict):
        return plan
    clips = plan.get("clips")
    if not isinstance(clips, list) or not clips:
        return plan

    media = c.list_media(job_dir)
    images = [m for m in media if m.get("media_type") == "image"]
    videos = [m for m in media if m.get("media_type") == "video"]
    duration = float(plan.get("duration_s") or 0) or max(
        float(clips[-1].get("end_s") or 0), 1.0
    )

    estilo = st.resolver(plan.get("style") or plan.get("preset") or "product_presenter")
    _, ratio_min, ratio_max = st.ritmo(estilo)

    rng = job_rng(job_dir)
    images = list(images)
    videos = list(videos)
    rng.shuffle(images)
    rng.shuffle(videos)

    total = len(clips)
    duracion_slots: dict[int, float] = {
        i: max(0.0, float(clip.get("end_s") or 0) - float(clip.get("start_s") or 0))
        for i, clip in enumerate(clips)
    }

    chosen_video_slots: set[int] = set()
    if videos:
        ratio = rng.uniform(ratio_min, ratio_max)
        objetivo = min(duration * ratio, duration * 0.6)
        maximo = max(1, (total - 1) // 2) if images else total
        cuantos = min(maximo, max(1, int(round(total * ratio))))
        for _ in range(24):
            elegido = sortear_slots_video(rng, total, cuantos, bool(images))
            if not elegido:
                break
            if sum(duracion_slots[i] for i in elegido) >= objetivo or len(elegido) >= maximo:
                chosen_video_slots = elegido
                break
            chosen_video_slots = elegido
            cuantos = min(maximo, cuantos + 1)
        if not chosen_video_slots:
            chosen_video_slots = {rng.randrange(total)}

    image_cycle = images.copy()
    video_cycle = videos.copy()
    previous = ""
    video_ranges: dict[str, list[tuple[float, float]]] = {}
    for idx, clip in enumerate(clips):
        use_video = bool(videos) and (idx in chosen_video_slots or not images)
        pool = videos if use_video else images
        if not pool:
            pool = media
        cycle = video_cycle if use_video else image_cycle
        if not cycle:
            cycle.extend(pool)
            rng.shuffle(cycle)
        m = sortear_pool(rng, cycle, previous)
        previous = Path(m["path"]).name.lower()
        clip["media"] = m["path"]
        clip["media_type"] = m["media_type"]
        if m["media_type"] == "video":
            clip["ken_burns"] = "none"
            clip["transition"] = "jump_cut"
            clip_span = max(0.1, duracion_slots[idx])
            source_duration = c.duracion_audio(Path(m["path"]))
            max_start = max(0.0, source_duration - clip_span - 0.08)
            ranges = video_ranges.setdefault(str(m["path"]), [])
            candidates = [rng.uniform(0, max_start) for _ in range(20)] if max_start > 0 else [0.0]
            valid = [start for start in candidates if all(start + clip_span <= begin or start >= end for begin, end in ranges)]
            start = rng.choice(valid) if valid else (rng.uniform(0, max_start) if max_start > 0 else 0.0)
            clip["video_start_s"] = round(start, 3)
            ranges.append((start, start + clip_span))
        else:
            clip["ken_burns"] = clip.get("ken_burns") or ("in" if idx % 2 == 0 else "out")

    # Remotion: transforms/transiciones en OffthreadVideo pueden renderizar negro → corte directo.
    for clip in clips:
        if clip.get("media_type") == "video":
            clip["transition"] = "jump_cut"
            clip["ken_burns"] = "none"

    plan["clips"] = clips
    return plan


def ensure_videos_in_plan(job_dir: Path, plan: dict) -> dict:
    return ensure_all_media_in_plan(job_dir, plan)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("job_dir")
    ap.add_argument("--preset", default="social_ad",
                    choices=["random", *st.ids()])
    ap.add_argument("--force-fallback", action="store_true")
    a = ap.parse_args()
    job = Path(a.job_dir)
    rng = job_rng(job)
    preset = st.estilo_aleatorio(rng)["id"] if a.preset == "random" else st.resolver(a.preset)["id"]
    try:
        if a.force_fallback:
            ensure_edit_plan(job, preset, force_fallback=True)
        write_props(job, preset)
    except Exception as e:
        print(str(e), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
