#!/usr/bin/env python3
"""Construye edit_plan equitativo si no hay MIIA, y composition-props.json para Remotion."""
from __future__ import annotations

import argparse
import math
import shutil
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import commun as c


TRANSITIONS = ("jump_cut", "fade", "zoom_in")


def fallback_edit_plan(job_dir: Path, duration: float, preset: str) -> dict:
    media = c.list_media(job_dir)
    if not media:
        raise RuntimeError("El job no tiene images/ ni videos/.")

    duration = max(1.0, float(duration))
    images = [m for m in media if m.get("media_type") == "image"]
    videos = [m for m in media if m.get("media_type") == "video"]

    # Intercalar: priorizar videos de producto (deben verse en el anuncio final).
    ordered: list[dict] = []
    if videos:
        # Abrir con 1–2 fotos, luego video, luego resto intercalado.
        if images:
            ordered.append(images[0])
        if len(images) > 1:
            ordered.append(images[1])
        for v in videos:
            ordered.append(v)
        for img in images[2:]:
            ordered.append(img)
            if videos:
                ordered.append(videos[0])
    else:
        ordered = list(images) if images else list(media)

    n = max(1, len(ordered))
    target_clips = max(n, min(12, max(3, int(math.ceil(duration / 2.5)))))
    picks = [ordered[i % n] for i in range(target_clips)]

    # Garantizar al menos ~30% del tiempo en videos si hay alguno.
    if videos:
        vid_slots = max(1, int(math.ceil(len(picks) * 0.35)))
        for i in range(vid_slots):
            idx = min(len(picks) - 1, 2 + i) if len(picks) > 2 else i % len(picks)
            picks[idx] = videos[i % len(videos)]

    slot = duration / len(picks)

    prompt = {}
    pp = job_dir / "prompt.json"
    if pp.is_file():
        raw = c.leer_json(pp)
        if isinstance(raw, dict):
            prompt = raw
    segments = prompt.get("segments") if isinstance(prompt.get("segments"), list) else []

    clips = []
    t = 0.0
    for i, m in enumerate(picks):
        end = duration if i == len(picks) - 1 else round(t + slot, 3)
        seg = segments[i] if i < len(segments) and isinstance(segments[i], dict) else {}
        tr_raw = str(seg.get("transition") or "").lower()
        if "fade" in tr_raw:
            transition = "fade"
        elif "zoom" in tr_raw:
            transition = "zoom_in"
        else:
            transition = TRANSITIONS[i % len(TRANSITIONS)] if preset == "quick_transition" else "jump_cut"
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
    """Copia assets a tools/remotion-ads/public/_job para staticFile()."""
    job_dir = Path(job_dir)
    pub = c.RAIZ / "public" / "_job"
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
        voice_src = "_job/voice.mp3"
    if music:
        dest = pub / ("music" + music.suffix.lower())
        shutil.copy2(music, dest)
        music_src = "_job/" + dest.name

    path_to_url: dict[str, str] = {}
    for m in c.list_media(job_dir):
        src = Path(m["path"])
        if m["media_type"] == "image":
            dest = pub / "images" / src.name
            rel_url = "_job/images/" + src.name
        else:
            dest = pub / "videos" / src.name
            rel_url = "_job/videos/" + src.name
        if not dest.exists():
            shutil.copy2(src, dest)
        path_to_url[str(src.resolve())] = rel_url
        path_to_url[str(src)] = rel_url

    # También dejar copia en job/remotion-public (debug)
    job_pub = job_dir / "remotion-public"
    if job_pub.exists():
        shutil.rmtree(job_pub, ignore_errors=True)
    shutil.copytree(pub, job_pub)

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
            url = f"_job/{folder}/{name}"
        clips.append({
            "start_s": float(clip["start_s"]),
            "end_s": float(clip["end_s"]),
            "media": url,
            "media_type": clip.get("media_type") or "image",
            "ken_burns": clip.get("ken_burns") or "in",
            "transition": clip.get("transition") or "jump_cut",
            "text_on_screen": clip.get("text_on_screen") or "",
        })

    duration = float(edit_plan.get("duration_s") or 5)
    if voice:
        d = c.duracion_audio(voice)
        if d > 0:
            duration = d

    return {
        "preset": edit_plan.get("preset") or "product_presenter",
        "voiceSrc": voice_src,
        "musicSrc": music_src,
        "musicVolume": 0.12,
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
    plan = ensure_edit_plan(job_dir, preset)
    plan = ensure_all_media_in_plan(job_dir, plan)
    plan["preset"] = preset
    props = build_composition_props(job_dir, plan)
    out = c.trabajo(job_dir, "composition-props.json")
    c.escribir_json(out, props)
    # Persistir plan ya corregido (con videos forzados si faltaban)
    c.escribir_json(c.trabajo(job_dir, "edit_plan.json"), plan)
    print(f"Props → {out}")
    return out


def ensure_all_media_in_plan(job_dir: Path, plan: dict) -> dict:
    """Garantiza que cada imagen/video del job aparezca ≥1 vez y que los videos no usen fade."""
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

    def basename_of(clip: dict) -> str:
        return Path(str(clip.get("media") or "")).name.lower()

    used_names = {basename_of(cl) for cl in clips}

    # Insertar medios faltantes reemplazando slots (sin tocar el primero si es hook).
    missing = [m for m in images + videos if Path(m["path"]).name.lower() not in used_names]
    if missing and clips:
        start = 1 if len(clips) > 1 else 0
        for i, m in enumerate(missing):
            idx = min(len(clips) - 1, start + i)
            clips[idx]["media"] = m["path"]
            clips[idx]["media_type"] = m["media_type"]
            clips[idx]["ken_burns"] = "none" if m["media_type"] == "video" else clips[idx].get("ken_burns") or "in"
            clips[idx]["transition"] = "jump_cut" if m["media_type"] == "video" else clips[idx].get("transition") or "jump_cut"

    # Tiempo mínimo en video de producto
    used_video = 0.0
    for clip in clips:
        if clip.get("media_type") == "video":
            used_video += max(0.0, float(clip.get("end_s") or 0) - float(clip.get("start_s") or 0))
    if videos and used_video < max(3.0, duration * 0.28) - 0.05:
        v = videos[0]
        n = len(clips)
        replace = max(1, int(math.ceil(n * 0.35)))
        start_idx = max(0, n // 4)
        for i in range(replace):
            idx = min(n - 1, start_idx + i)
            clips[idx]["media"] = v["path"]
            clips[idx]["media_type"] = "video"
            clips[idx]["ken_burns"] = "none"
            clips[idx]["transition"] = "jump_cut"

    # Remotion: fade/zoom en OffthreadVideo suele salir negro → jump_cut en videos
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
    ap.add_argument("--preset", default="product_presenter",
                    choices=["product_presenter", "quick_transition"])
    ap.add_argument("--force-fallback", action="store_true")
    a = ap.parse_args()
    job = Path(a.job_dir)
    try:
        if a.force_fallback:
            ensure_edit_plan(job, a.preset, force_fallback=True)
        write_props(job, a.preset)
    except Exception as e:
        print(str(e), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
