# Multidrop Remotion Ads

Ads verticales 9:16 (TikTok/Reels) con Remotion, Whisper (palabra a palabra) y MIIA (plan de cortes).

## Requisitos

- Node 18+
- Python 3.10+ (`pip install -r python/requirements.txt`)
- ffmpeg en PATH
- Desde Laravel: MIIA configurada para el edit plan

## Instalación

```bash
cd tools/remotion-ads
npm install
python -m venv .venv
# Windows:
.venv\Scripts\pip install -r python/requirements.txt
# Linux/Mac:
.venv/bin/pip install -r python/requirements.txt
```

Laravel usa automáticamente `.venv` si existe.

## Pipeline CLI

```bash
# jobDir = carpeta con prompt.json + images/ + videos/ (+ voice.mp3 opcional)
python python/run_pipeline.py path/to/jobDir --preset product_presenter
```

Pasos: TTS si falta VO → Whisper → `php artisan marketing:remotion-edit-plan` (si hay) o fallback equitativo → Remotion render → `out/final.mp4`.

## Studio

```bash
npm run studio
```

## Presets

- `product_presenter` — producto full-bleed + viñeta + captions karaoke
- `quick_transition` — cortes rápidos / zoom
