<?php

namespace App\Domain\AI;

use App\Services\Marketing\RemotionStyleCatalog;
use Illuminate\Support\Facades\Log;

/**
 * MIIA decide clips/transiciones a partir de Whisper + medios + segments del prompt.
 */
class RemotionEditPlanService
{
    public function __construct(
        protected AiTaskRouter $ai,
        protected RemotionStyleCatalog $styles,
    ) {}

    /**
     * @param  array<string, mixed>  $prompt
     * @param  list<array{path: string, rel?: string, media_type: string, name: string}>  $media
     * @param  array{palabras?: list<array<string, mixed>>, frases?: list<array<string, mixed>>, duracion_audio?: float|int}  $transcript
     * @return array{success: bool, plan?: array<string, mixed>, error?: string, provider?: string}
     */
    public function generate(array $prompt, array $media, array $transcript, string $preset = 'random'): array
    {
        if (! $this->ai->hasMiia()) {
            return [
                'success' => false,
                'error' => 'Configura la API Key de MIIA en Admin → General.',
                'provider' => 'miia',
            ];
        }

        if ($media === []) {
            return ['success' => false, 'error' => 'No hay imágenes ni videos en el job.'];
        }

        $duration = (float) ($transcript['duracion_audio'] ?? 0);
        if ($duration <= 0 && ! empty($transcript['palabras']) && is_array($transcript['palabras'])) {
            $last = $transcript['palabras'][array_key_last($transcript['palabras'])];
            $duration = (float) ($last['fin'] ?? 0);
        }
        $duration = max(1.0, $duration);

        $mediaForAi = [];
        foreach ($media as $i => $m) {
            $mediaForAi[] = [
                'id' => $i,
                'name' => $m['name'] ?? basename((string) ($m['path'] ?? '')),
                'media_type' => $m['media_type'] ?? 'image',
                'path' => $m['path'] ?? '',
            ];
        }

        $wordsBrief = [];
        foreach (array_slice($transcript['palabras'] ?? [], 0, 400) as $w) {
            if (! is_array($w)) {
                continue;
            }
            $wordsBrief[] = [
                't' => round((float) ($w['inicio'] ?? 0), 2),
                'w' => (string) ($w['texto'] ?? ''),
            ];
        }

        $estilo = $this->styles->resolve($preset);
        $preset = $estilo['id'];
        $style = $estilo['style'];
        $transiciones = array_values(array_filter(
            (array) ($style['transitions'] ?? []),
            fn ($t) => is_string($t) && $t !== 'jump_cut'
        ));
        if ($transiciones === []) {
            $transiciones = ['fade', 'slide_left', 'wipe', 'zoom_in'];
        }
        $clipSeg = max(1.4, min(4.0, (float) ($style['clip_seconds'] ?? 2.6)));
        $ratioBajo = (float) (($style['video_ratio'] ?? [0.3, 0.4])[0] ?? 0.3);
        $ratioAlto = (float) (($style['video_ratio'] ?? [0.3, 0.4])[1] ?? 0.4);

        $payload = [
            'preset' => $preset,
            'style' => $preset,
            'style_label' => $estilo['label'],
            'style_look' => [
                'captions' => $style['captions'] ?? null,
                'overlay' => $style['overlay'] ?? null,
                'decorations' => $style['decorations'] ?? [],
                'energy' => $style['energy'] ?? null,
            ],
            'duration_s' => $duration,
            'hook' => $prompt['hook'] ?? '',
            'segments' => $prompt['segments'] ?? [],
            'product' => $prompt['product'] ?? null,
            'media' => $mediaForAi,
            'words_timeline' => $wordsBrief,
            'allowed_transitions' => array_merge($transiciones, ['jump_cut']),
            'allowed_ken_burns' => ['in', 'out', 'none'],
            'clip_seconds_target' => $clipSeg,
            'video_ratio_target' => [$ratioBajo, $ratioAlto],
        ];

        $transicionesTexto = implode('|', array_merge($transiciones, ['jump_cut']));
        $minimoRatio = (int) round($ratioBajo * 100);
        $maximoRatio = (int) round($ratioAlto * 100);

        $system = <<<TXT
Eres editor de anuncios verticales 9:16 (TikTok). Recibes timeline de palabras (Whisper), lista de medios del producto y segmentos creativos.
El estilo visual ya está elegido por el sistema ("{$estilo['label']}" / {$preset}); tu trabajo es el ritmo de cortes, no el estilo.
Devuelve SOLO JSON válido (sin markdown) con esta forma:
{
  "preset": "{$preset}",
  "style": "{$preset}",
  "duration_s": number,
  "cta_text": "string corto",
  "clips": [
    {
      "start_s": number,
      "end_s": number,
      "media_id": number,
      "ken_burns": "in|out|none",
      "transition": "{$transicionesTexto}",
      "text_on_screen": "string"
    }
  ]
}
Reglas:
- Los clips deben cubrir 0..duration_s sin huecos grandes (>0.15s).
- media_id debe existir en media[].id
- Prefiere cortes en pausas naturales del habla (saltos en words_timeline).
- Duración objetivo por clip ≈ {$clipSeg}s; mantén el ritmo del estilo sin fragmentar en cortes de menos de 1.2s.
- Entre fotos usa transiciones de la lista ({$transicionesTexto}); reserva jump_cut para entradas/salidas de clips de video o un corte claramente intencional.
- Máximo 14 clips. Mínimo 3 si hay duración > 6s.
- OBLIGATORIO: si en media[] hay items con media_type="video", úsalos. Entre el {$minimoRatio}% y el {$maximoRatio}% del tiempo total debe usar videos de producto. En videos usa ken_burns="none".
- Alterna fotos y video; no dejes el video solo al final ni lo omitas.
- Usa únicamente los datos de product, hook y segments. No inventes beneficios, especificaciones, precios ni descuentos; si un dato no está presente, omítelo.
TXT;

        $result = $this->ai->chat('remotion_edit_plan', [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ], [
            'temperature' => 0.4,
            'response_format' => ['type' => 'json_object'],
        ]);

        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'error' => (string) ($result['error'] ?? 'MIIA no respondió.'),
                'provider' => $result['provider'] ?? 'miia',
            ];
        }

        $parsed = $this->parseJson((string) ($result['content'] ?? ''));
        if ($parsed === []) {
            return [
                'success' => false,
                'error' => 'MIIA devolvió JSON inválido para el edit plan.',
                'provider' => $result['provider'] ?? 'miia',
            ];
        }

        $plan = $this->normalizePlan($parsed, $mediaForAi, $duration, $preset, $transiciones);
        $plan = $this->ensureProductVideosInPlan($plan, $mediaForAi, $duration, $ratioBajo, $ratioAlto);
        if ($plan['clips'] === []) {
            return [
                'success' => false,
                'error' => 'El edit plan de MIIA no tenía clips utilizables.',
                'provider' => $result['provider'] ?? 'miia',
            ];
        }

        $plan['source'] = 'miia';

        return [
            'success' => true,
            'plan' => $plan,
            'provider' => $result['provider'] ?? 'miia',
        ];
    }

    /**
     * Si el producto tiene videos y el plan casi no los usa, inserta/reemplaza clips.
     *
     * @param  array{preset: string, style?: string, duration_s: float, cta_text: string, clips: list<array<string, mixed>>, source?: string}  $plan
     * @param  list<array{id: int, name: string, media_type: string, path: string}>  $media
     * @return array{preset: string, style?: string, duration_s: float, cta_text: string, clips: list<array<string, mixed>>, source?: string}
     */
    protected function ensureProductVideosInPlan(array $plan, array $media, float $duration, float $ratioBajo = 0.28, float $ratioAlto = 0.42): array
    {
        $videos = array_values(array_filter($media, fn ($m) => ($m['media_type'] ?? '') === 'video'));
        if ($videos === [] || empty($plan['clips']) || ! is_array($plan['clips'])) {
            return $plan;
        }

        $clips = $plan['clips'];
        $videoSeconds = 0.0;
        foreach ($clips as $clip) {
            if (($clip['media_type'] ?? '') === 'video') {
                $videoSeconds += max(0.0, (float) ($clip['end_s'] ?? 0) - (float) ($clip['start_s'] ?? 0));
            }
        }

        $minVideo = max(2.0, $duration * $ratioBajo);
        if ($videoSeconds >= $minVideo - 0.05) {
            return $plan;
        }

        $n = count($clips);
        // Sustituir clips por videos de producto, repartidos y sin encadenar dos videos.
        $replace = max(1, (int) ceil($n * min(0.6, max(0.25, $ratioAlto))));
        $paso = max(2, (int) floor($n / max(1, $replace)));
        $arranque = max(0, min($n - 1, (int) floor($n * 0.2)));
        for ($i = 0, $puestos = 0; $i < $n && $puestos < $replace; $i++) {
            $pos = ($arranque + $i * $paso) % $n;
            if (($clips[$pos]['media_type'] ?? '') === 'video') {
                continue;
            }
            $v = $videos[$puestos % count($videos)];
            $clips[$pos]['media'] = $v['path'];
            $clips[$pos]['media_type'] = 'video';
            $clips[$pos]['ken_burns'] = 'none';
            $clips[$pos]['transition'] = 'jump_cut';
            $puestos++;
        }

        $plan['clips'] = $clips;

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  list<array{id: int, name: string, media_type: string, path: string}>  $media
     * @param  list<string>  $transiciones
     * @return array{preset: string, style: string, duration_s: float, cta_text: string, clips: list<array<string, mixed>>}
     */
    protected function normalizePlan(array $parsed, array $media, float $duration, string $preset, array $transiciones = []): array
    {
        $byId = [];
        foreach ($media as $m) {
            $byId[(int) $m['id']] = $m;
        }

        $permitidas = array_values(array_unique(array_merge($transiciones, ['jump_cut'])));
        $clips = [];
        $rawClips = is_array($parsed['clips'] ?? null) ? $parsed['clips'] : [];
        foreach ($rawClips as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['media_id'] ?? -1);
            if (! isset($byId[$id])) {
                $id = (int) array_key_first($byId);
            }
            $m = $byId[$id];
            $start = max(0.0, (float) ($row['start_s'] ?? 0));
            $end = max($start + 0.2, (float) ($row['end_s'] ?? ($start + 2)));
            $tr = strtolower((string) ($row['transition'] ?? 'jump_cut'));
            if (! in_array($tr, $permitidas, true)) {
                $tr = $permitidas[0] ?? 'jump_cut';
            }
            $ken = strtolower((string) ($row['ken_burns'] ?? 'in'));
            if (! in_array($ken, ['in', 'out', 'none'], true)) {
                $ken = 'in';
            }
            $isVideo = ($m['media_type'] ?? '') === 'video';
            $clips[] = [
                'start_s' => round($start, 3),
                'end_s' => round(min($duration, $end), 3),
                'media' => $m['path'],
                'media_type' => $m['media_type'],
                'ken_burns' => $isVideo ? 'none' : $ken,
                // fade/zoom + OffthreadVideo suele renderizar negro en Remotion
                'transition' => $isVideo ? 'jump_cut' : $tr,
                'text_on_screen' => mb_substr(trim((string) ($row['text_on_screen'] ?? '')), 0, 80),
            ];
        }

        usort($clips, fn ($a, $b) => $a['start_s'] <=> $b['start_s']);

        if ($clips !== []) {
            $clips[0]['start_s'] = 0.0;
            $clips[array_key_last($clips)]['end_s'] = round($duration, 3);
        }

        return [
            'preset' => $preset,
            'style' => $preset,
            'duration_s' => round($duration, 3),
            'cta_text' => mb_substr(trim((string) ($parsed['cta_text'] ?? 'Compra ahora')), 0, 40) ?: 'Compra ahora',
            'clips' => $clips,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseJson(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $m)) {
            $content = trim($m[1]);
        }
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($content, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        Log::info('RemotionEditPlanService: JSON parse fail', ['snippet' => mb_substr($content, 0, 400)]);

        return [];
    }
}
