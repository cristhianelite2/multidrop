<?php

namespace App\Domain\AI;

use Illuminate\Support\Facades\Log;

/**
 * MIIA decide clips/transiciones a partir de Whisper + medios + segments del prompt.
 */
class RemotionEditPlanService
{
    public function __construct(protected AiTaskRouter $ai) {}

    /**
     * @param  array<string, mixed>  $prompt
     * @param  list<array{path: string, rel?: string, media_type: string, name: string}>  $media
     * @param  array{palabras?: list<array<string, mixed>>, frases?: list<array<string, mixed>>, duracion_audio?: float|int}  $transcript
     * @return array{success: bool, plan?: array<string, mixed>, error?: string, provider?: string}
     */
    public function generate(array $prompt, array $media, array $transcript, string $preset = 'product_presenter'): array
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

        $payload = [
            'preset' => $preset,
            'duration_s' => $duration,
            'hook' => $prompt['hook'] ?? '',
            'segments' => $prompt['segments'] ?? [],
            'media' => $mediaForAi,
            'words_timeline' => $wordsBrief,
            'allowed_transitions' => ['jump_cut', 'fade', 'zoom_in'],
            'allowed_ken_burns' => ['in', 'out', 'none'],
        ];

        $system = <<<'TXT'
Eres editor de anuncios verticales 9:16 (TikTok). Recibes timeline de palabras (Whisper), lista de medios del producto y segmentos creativos.
Devuelve SOLO JSON válido (sin markdown) con esta forma:
{
  "preset": "product_presenter|quick_transition",
  "duration_s": number,
  "cta_text": "string corto",
  "clips": [
    {
      "start_s": number,
      "end_s": number,
      "media_id": number,
      "ken_burns": "in|out|none",
      "transition": "jump_cut|fade|zoom_in",
      "text_on_screen": "string"
    }
  ]
}
Reglas:
- Los clips deben cubrir 0..duration_s sin huecos grandes (>0.15s).
- media_id debe existir en media[].id
- Prefiere cortes en pausas naturales del habla (saltos en words_timeline).
- Para quick_transition: más clips cortos (1.5–3s). Para product_presenter: 2.5–4s.
- Máximo 14 clips. Mínimo 3 si hay duración > 6s.
- OBLIGATORIO: si en media[] hay items con media_type="video", úsalos. Al menos el 30% del tiempo total debe usar videos de producto (clips de ≥2.5s). En videos usa ken_burns="none".
- Alterna fotos y video; no dejes el video solo al final ni lo omitas.
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

        $plan = $this->normalizePlan($parsed, $mediaForAi, $duration, $preset);
        $plan = $this->ensureProductVideosInPlan($plan, $mediaForAi, $duration);
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
     * @param  array{preset: string, duration_s: float, cta_text: string, clips: list<array<string, mixed>>, source?: string}  $plan
     * @param  list<array{id: int, name: string, media_type: string, path: string}>  $media
     * @return array{preset: string, duration_s: float, cta_text: string, clips: list<array<string, mixed>>, source?: string}
     */
    protected function ensureProductVideosInPlan(array $plan, array $media, float $duration): array
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

        $minVideo = max(3.0, $duration * 0.28);
        if ($videoSeconds >= $minVideo - 0.05) {
            return $plan;
        }

        $v = $videos[0];
        $n = count($clips);
        // Sustituir ~35% de clips (desde el tercio central) por el video de producto.
        $replace = max(1, (int) ceil($n * 0.35));
        $startIdx = max(0, (int) floor($n * 0.25));
        for ($i = 0; $i < $replace; $i++) {
            $idx = min($n - 1, $startIdx + $i);
            $clips[$idx]['media'] = $v['path'];
            $clips[$idx]['media_type'] = 'video';
            $clips[$idx]['ken_burns'] = 'none';
            $clips[$idx]['transition'] = 'jump_cut';
        }

        $plan['clips'] = $clips;

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  list<array{id: int, name: string, media_type: string, path: string}>  $media
     * @return array{preset: string, duration_s: float, cta_text: string, clips: list<array<string, mixed>>, source?: string}
     */
    protected function normalizePlan(array $parsed, array $media, float $duration, string $preset): array
    {
        $byId = [];
        foreach ($media as $m) {
            $byId[(int) $m['id']] = $m;
        }

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
            if (! in_array($tr, ['jump_cut', 'fade', 'zoom_in'], true)) {
                $tr = 'jump_cut';
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

        $outPreset = (string) ($parsed['preset'] ?? $preset);
        if (! in_array($outPreset, ['product_presenter', 'quick_transition'], true)) {
            $outPreset = $preset;
        }

        return [
            'preset' => $outPreset,
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
