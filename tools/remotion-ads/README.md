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
npm ci --include=optional
python -m venv .venv
# Windows:
.venv\Scripts\pip install -r python/requirements.txt
# Linux/Mac:
.venv/bin/pip install -r python/requirements.txt
```

Laravel usa automáticamente `.venv` si existe.

### Instalación en servidor Linux

Instala las dependencias directamente en el servidor donde corre Remotion. No copies `node_modules` desde Windows o macOS: paquetes como `esbuild` y el compositor de Remotion incluyen binarios específicos de cada plataforma.

```bash
cd /var/www/html/tools/remotion-ads
npm ci --include=optional
node -e "require('esbuild').transformSync('const runtimeCheck = true'); console.log('esbuild OK')"
```

`npm ci` respeta `package-lock.json` y `--include=optional` instala el binario Linux requerido aunque npm esté configurado para omitir dependencias opcionales. Ejecuta la instalación con el mismo usuario que lanza el pipeline de Laravel/Remotion.

El `postinstall` del proyecto marca como ejecutables `remotion`, `ffmpeg` y `ffprobe` del compositor Linux. Esto es necesario cuando el código vive en un volumen montado que no conserva permisos Unix; sin ello, Remotion puede fallar al leer los canales y la duración de voz o video.

El pipeline ejecuta esta comprobación automáticamente antes de iniciar TTS y Whisper; si falta el binario compatible con el sistema, detiene el job y muestra el comando de reparación antes de consumir recursos.

#### Permisos de escritura

Laravel prepara el job en `tools/remotion-ads/jobs/<uuid>` y Python necesita, además, escribir los assets estáticos del render. Si `tools/remotion-ads/public` no es escribible por el usuario que corre PHP (en el servidor suele ser `www-data`, con el código desplegado por otro usuario), el pipeline no falla: usa `jobs/<uuid>/_public` como raíz estática de ese render y replica allí `public/elements`. Para que no haga falta ese reserva, deja la carpeta en manos del usuario del web server:

```bash
chown -R www-data:www-data /var/www/html/tools/remotion-ads/public /var/www/html/tools/remotion-ads/jobs
chmod -R u+rwX,g+rwX /var/www/html/tools/remotion-ads/public /var/www/html/tools/remotion-ads/jobs
```

Para forzar otra ubicación, define `REMOTION_PUBLIC_DIR` en el entorno del proceso Python (por ejemplo `storage_path('app/remotion-ads/public')`): tiene que ser escribible y contendrá `elements/` más `_jobs/<uuid>/` por render.

## Pipeline CLI

```bash
# jobDir = carpeta con prompt.json + images/ + videos/ (+ voice.mp3 opcional)
python python/run_pipeline.py path/to/jobDir --preset random
```

Pasos: TTS si falta VO → Whisper → `php artisan marketing:remotion-edit-plan` (si hay) o fallback local → Remotion render → `out/final.mp4`.

`--preset random` (por defecto) elige un estilo del catálogo en cada generación; también acepta un id concreto (`--preset spec_cards`).

## Studio

```bash
npm run studio
```

## Estilos (`styles.json`)

`styles.json` es la fuente única del catálogo: lo leen `App\Services\Marketing\RemotionStyleCatalog` (Laravel), `python/styles.py` y `src/styles.ts`. Cada generación elige un estilo al azar y el orden de imágenes/videos también se sortea (el RNG se siembra con el UUID del job, así un reintento mantiene el mismo corte).

| id | Label | Base |
| --- | --- | --- |
| `social_ad` | Social Ad | `templates/social-ad` |
| `product_showcase` | Product Showcase | `templates/property-listing` |
| `testimonial_card` | Testimonial Card | `templates/testimonial` |
| `branded_intro` | Branded Intro | `templates/branded-intro` |
| `spec_cards` | Spec Cards | `templates/weekly-recap` |

Origen visual: https://github.com/maxtron777/remotion-business-templates (adaptado a 9:16).

Cada estilo define: overlay, acento, captions (`bold_karaoke`, `pill_accent`, `light_card`, `minimal_line`, `mono_stat`), CTA (`solid_pill`, `chip_circle`, `outline`, `card_solid`, `banner_full`), decoraciones, transiciones, ritmo (`clip_seconds`) y rango de video (`video_ratio`).

Los `preset_base` heredados se mantienen para compatibilidad interna:

- `product_presenter` — producto full-bleed + viñeta + captions karaoke
- `quick_transition` — cortes rápidos / zoom

## Añadir un estilo

1. Añade el objeto a `styles` en `styles.json` (no hace falta tocar PHP, Python ni TS).
2. Reinicia Laravel para vaciar la caché del catálogo (`php artisan cache:clear`).

## Música (`music_catalog.json`)

13 pistas CC BY 3.0 de Kevin MacLeod en `public/music/`, todas **normalizadas a -18 LUFS** para que el mix suene igual sin importar la pista. La mezcla final (voz + música) se masteriza a **-14 LUFS** al terminar el render, así que ninguna generación sale muda.

| id | Título | Ambiente |
| --- | --- | --- |
| `local_forecast` | Local Forecast - Elevator | Jazz ligero |
| `gymnopedie_no_1/2/3` | Gymnopedie No. 1/2/3 | Piano tranquilo, melancólico, meditativo |
| `canon_in_d_major` | Canon in D Major | Clásica suave |
| `celtic_impulse` | Celtic Impulse | Épica celta |
| `earth_prelude` | Earth Prelude | Preludio ambiental |
| `gypsy_shoegazer` | Gypsy Shoegazer (No Voices) | Gitano acústico |
| `himalayan_atmosphere` | Himalayan Atmosphere | Ambiente oriental |
| `miris_magic_dance` | Miri's Magic Dance | Magia suave |
| `tea_roots` | Tea Roots | Acústica serena |
| `the_voices` | The Voices | Coro etéreo |
| `tectonic` | Tectonic | Épica tensa |

Niveles medidos en el render: voz ≈ -19 LUFS, música ≈ -30 LUFS (10 dBC por debajo de la voz, audible pero sin taparla). El control "Volumen de música" del modal va de 0 a 60% (30% por defecto).

### Añadir o re-normalizar una pista

```bash
# 1. Deja el mp3 (CC BY 3.0 / dominio público) en cualquier carpeta.
# 2. Recorta, normaliza a -18 LUFS y codifica a 128 kbps:
python scripts/prepare_music.py --incoming /ruta/los/mp3
# 3. Registra la pista en music_catalog.json con su crédito y licencia.
python scripts/check_music_catalog.py   # verifica catálogo vs. archivos
```

`scripts/prepare_music.py` guarda lo que hizo en `public/music/library.json` y no reprocesa lo ya normalizado (usa `--force` si cambias los parámetros). El `credit` de la pista elegida se copia al video para poder atribuirlo al publicarlo.

Si el job trae un `music.mp3` empaquetado (zip remoto) o un archivo que no viene de la biblioteca, `build_props.py` lo normaliza a -18 LUFS al copiarlo, de modo que el bridge suene igual que en local.

