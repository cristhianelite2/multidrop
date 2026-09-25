<?php

namespace App\Services\Marketing\VideoPlan;

/**
 * VideoPlanSanitizer: corrige de forma controlada el plan de Miia usando
 * únicamente el catálogo permitido. Es el último intento antes de declarar
 * el plan inválido (validator) y ejecutar el fallback.
 *
 * NO modifica la estrategia creativa: solo reencamina valores no soportados
 * a opciones equivalentes del catálogo y normaliza números/ids.
 */
class VideoPlanSanitizer
{
    public function __construct(protected VideoPlanCatalog $catalog) {}

    /**
     * @param  array<string, mixed>|null  $plan
     * @param  array<string, mixed>  $brief
     * @return array{plan: array<string, mixed>, fixes: list<string>}
     */
    public function sanitize(?array $plan, array $brief): array
    {
        $fixes = [];
        if (! is_array($plan) || $plan === []) {
            return ['plan' => [], 'fixes' => ['plan vacío -> no sanitizable']];
        }

        $plan['version'] = '1.0';
        $video = is_array($plan['video'] ?? null) ? $plan['video'] : [];
        $video = array_merge($this->catalog->video(), array_filter($video, fn ($v) => $v !== null && $v !== ''));
        $plan['video'] = [
            'width' => (int) ($video['width'] ?? 1080),
            'height' => (int) ($video['height'] ?? 1920),
            'fps' => (int) ($video['fps'] ?? 30),
            'duration' => (float) ($video['duration'] ?? 29),
        ];

        $template = (string) ($plan['template'] ?? '');
        if (! $this->catalog->isTemplate($template)) {
            $fixes[] = "template '{$template}' fuera de catálogo -> '{$this->catalog->defaultTemplate()}'";
            $plan['template'] = $this->catalog->defaultTemplate();
        }

        $creative = is_array($plan['creative'] ?? null) ? $plan['creative'] : [];
        foreach (['audience', 'tone'] as $field) {
            $creative[$field] = trim((string) ($creative[$field] ?? ''));
        }
        $creative['hook'] = $this->sanitizeOnScreenText(trim((string) ($creative['hook'] ?? $brief['creative']['hook'] ?? '')));
        $creative['cta'] = $this->sanitizeOnScreenText(trim((string) ($creative['cta'] ?? $brief['creative']['cta'] ?? '')));
        if ($creative['cta'] === '') {
            $creative['cta'] = 'Compra ahora';
            $fixes[] = 'cta vacío -> valor por defecto';
        }
        $creative['mainBenefit'] = $this->sanitizeOnScreenText(trim((string) ($creative['mainBenefit'] ?? '')));
        $plan['creative'] = $creative;

        $audio = is_array($plan['audio'] ?? null) ? $plan['audio'] : [];
        $plan['audio'] = [
            'voiceover' => isset($audio['voiceover']) ? (string) $audio['voiceover'] : null,
            'music' => isset($audio['music']) ? (string) $audio['music'] : null,
        ];

        $assetIds = array_values(array_unique(array_merge(
            array_map(fn ($a) => $a['id'] ?? '', $brief['assets']['images'] ?? []),
            array_map(fn ($a) => $a['id'] ?? '', $brief['assets']['videos'] ?? [])
        )));

        $scenes = is_array($plan['scenes'] ?? null) ? $plan['scenes'] : [];
        $sceneIds = [];
        $sanitizedScenes = [];
        foreach ($scenes as $idx => $scene) {
            if (! is_array($scene)) {
                $fixes[] = "scenes[{$idx}] no es objeto -> ignorada";
                continue;
            }
            $s = $this->sanitizeScene($scene, $assetIds, $fixes, $sceneIds, $idx);
            $sanitizedScenes[] = $s;
        }

        // Orden estable por start.
        usort($sanitizedScenes, function (array $a, array $b) {
            $sa = (float) ($a['start'] ?? 0);
            $sb = (float) ($b['start'] ?? 0);

            return $sa <=> $sb;
        });

        $plan['scenes'] = $sanitizedScenes;

        $dialogue = [];
        foreach ((array) ($plan['dialogue'] ?? []) as $idx => $line) {
            if (! is_array($line)) {
                continue;
            }
            $text = trim((string) ($line['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $dialogue[] = [
                'speaker' => trim((string) ($line['speaker'] ?? 'narrator')) ?: 'narrator',
                'text' => $text,
                'start' => (float) ($line['start'] ?? 0),
                'duration' => max(0.2, (float) ($line['duration'] ?? 1.5)),
            ];
        }
        $plan['dialogue'] = $dialogue;

        return ['plan' => $plan, 'fixes' => $fixes];
    }

    /**
     * @param  array<string, mixed>  $scene
     * @param  list<string>  $assetIds
     * @param  list<string>  $fixes
     * @param  list<string>  $sceneIds
     * @return array<string, mixed>
     */
    protected function sanitizeScene(array $scene, array $assetIds, array &$fixes, array &$sceneIds, int $idx): array
    {
        $baseId = (string) ($scene['id'] ?? ('scene_'.str_pad((string) ($idx + 1), 2, '0', STR_PAD_LEFT)));
        $id = $baseId;
        $n = 2;
        while (in_array($id, $sceneIds, true)) {
            $id = $baseId.'_'.$n++;
        }
        $sceneIds[] = $id;

        $start = max(0.0, (float) ($scene['start'] ?? 0));
        $duration = (float) ($scene['duration'] ?? 0);
        if ($duration < 0.3) {
            $duration = 1.0;
            $fixes[] = "{$id}: duración < 0.3s -> 1s";
        }

        $background = is_array($scene['background'] ?? null) ? $scene['background'] : null;
        if (is_array($background)) {
            $type = (string) ($background['type'] ?? '');
            if ($type === 'image' || $type === 'video') {
                $assetId = (string) ($background['assetId'] ?? '');
                if (! in_array($assetId, $assetIds, true)) {
                    $fixes[] = "{$id}: fondo referencia asset {$assetId} inexistente -> sin fondo";
                    $background = null;
                }
            } elseif ($type !== 'color') {
                $fixes[] = "{$id}: tipo de fondo '{$type}' no soportado -> sin fondo";
                $background = null;
            }
        }

        $transitionOut = is_array($scene['transitionOut'] ?? null) ? $scene['transitionOut'] : null;
        if (is_array($transitionOut)) {
            $tType = strtolower(trim((string) ($transitionOut['type'] ?? '')));
            $tDuration = (float) ($transitionOut['duration'] ?? 0.35);
            if (! $this->catalog->isTransition($tType)) {
                $fixes[] = "{$id}: transición '{$tType}' no válida -> fade";
                $tType = 'fade';
            }
            $tDuration = min(max(0.0, $tDuration), $duration);
            $transitionOut = ['type' => $tType, 'duration' => $tDuration];
        }

        $elements = is_array($scene['elements'] ?? null) ? $scene['elements'] : [];
        $elementIds = [];
        $sanitizedElements = [];
        foreach ($elements as $eIdx => $element) {
            if (! is_array($element)) {
                continue;
            }
            $sanitized = $this->sanitizeElement($element, $assetIds, $duration, $fixes, $elementIds, $eIdx, $id);
            if ($sanitized === null) {
                $fixes[] = "{$id}.elements[{$eIdx}]: elemento descartado";
                continue;
            }
            $sanitizedElements[] = $sanitized;
        }

        return [
            'id' => $id,
            'start' => $start,
            'duration' => $duration,
            'purpose' => (string) ($scene['purpose'] ?? 'general'),
            'background' => $background,
            'elements' => $sanitizedElements,
            'transitionOut' => $transitionOut,
        ];
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  list<string>  $assetIds
     * @param  list<string>  $fixes
     * @param  list<string>  $elementIds
     * @return array<string, mixed>|null
     */
    protected function sanitizeElement(array $element, array $assetIds, float $sceneDuration, array &$fixes, array &$elementIds, int $eIdx, string $sceneId): ?array
    {
        $type = strtolower(trim((string) ($element['type'] ?? '')));
        $rawText = trim((string) ($element['text'] ?? ''));
        $text = $this->sanitizeOnScreenText($rawText);
        $hasText = $text !== '' || $type === 'feature_list';

        if (! $this->catalog->isComponent($type)) {
            if ($hasText) {
                $fixes[] = "{$sceneId}.elements[{$eIdx}]: componente '{$type}' no existe -> headline";
                $type = 'headline';
            } else {
                return null;
            }
        }

        $baseId = (string) ($element['id'] ?? ('element_'.str_pad((string) ($eIdx + 1), 2, '0', STR_PAD_LEFT)));
        $id = $baseId;
        $n = 2;
        while (in_array($id, $elementIds, true)) {
            $id = $baseId.'_'.$n++;
        }
        $elementIds[] = $id;

        $start = max(0.0, (float) ($element['start'] ?? 0));
        $duration = (float) ($element['duration'] ?? 0);
        if ($duration < 0.3) {
            $duration = 0.4;
        }
        if ($start + $duration > $sceneDuration) {
            $duration = max(0.3, $sceneDuration - $start);
        }

        $animationIn = strtolower(trim((string) ($element['animationIn'] ?? '')));
        if (! $this->catalog->isAnimation($animationIn)) {
            $fixes[] = "{$sceneId}.elements[{$eIdx}].animationIn '{$animationIn}' -> fade";
            $animationIn = 'fade';
        }
        $animationOut = strtolower(trim((string) ($element['animationOut'] ?? '')));
        if (! $this->catalog->isAnimation($animationOut)) {
            $animationOut = 'none';
            $fixes[] = "{$sceneId}.elements[{$eIdx}].animationOut inválida -> none";
        }

        $out = [
            'id' => $id,
            'type' => $type,
            'start' => $start,
            'duration' => $duration,
            'animationIn' => $animationIn,
            'animationOut' => $animationOut,
        ];

        if ($type === 'feature_list') {
            $items = [];
            foreach ((array) ($element['items'] ?? []) as $item) {
                if (! is_string($item)) {
                    continue;
                }
                $clean = $this->sanitizeOnScreenText(trim($item));
                if ($clean !== '') {
                    $items[] = $clean;
                }
            }
            $out['items'] = $items;
        } elseif ($type === 'product_card') {
            $assetId = (string) ($element['assetId'] ?? '');
            if (! in_array($assetId, $assetIds, true)) {
                $assetId = $assetIds[0] ?? '';
                if ($assetId !== '') {
                    $fixes[] = "{$sceneId}.elements[{$eIdx}].assetId inválido -> {$assetId}";
                }
            }
            $out['assetId'] = $assetId;
        } else {
            if ($text !== '') {
                $out['text'] = $text;
            } elseif ($rawText !== '' && in_array($type, ['headline', 'subtitle', 'kinetic_subtitle', 'feature', 'badge', 'cta'], true)) {
                $fixes[] = "{$sceneId}.elements[{$eIdx}]: text descartado (nota/comentario/idea)";

                return null;
            }
            if ($type === 'subtitle') {
                $style = (string) ($element['style'] ?? 'plain');
                $out['style'] = $style === 'kinetic' ? 'kinetic' : 'plain';
            }
        }

        return $out;
    }

    /**
     * Copy on-screen: claim corto. Descarta ideas, comentarios y dirección de cámara.
     */
    protected function sanitizeOnScreenText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // Prefijos de nota interna → descartar el texto completo (no “limpiar” el prefijo).
        if (preg_match(
            '/^(?:idea|comentario|nota|note|director|dir\.?|c[aá]mara|camera|visual|talento|talent)\s*[:\-–—]/iu',
            $text
        )) {
            return '';
        }

        $lower = mb_strtolower($text);
        if (preg_match('/\b(idea|comentario|nota del director|director note|stage direction)\b/u', $lower)) {
            return '';
        }
        if (preg_match('/\b(aqu[ií]\s+(mostramos|vemos|sale|aparece)|vamos a mostrar|en este plano|insert\s+macro|push[\s-]?in|plano\s+(medio|cerrado|general)|selfie\s+pov|handheld|close[\s-]?up)\b/u', $lower)) {
            return '';
        }
        if (preg_match('/\b(el talento|la modelo|el presentador|gesticula|sonr[ií]e a c[aá]mara|mira a c[aá]mara)\b/u', $lower)) {
            return '';
        }

        $words = preg_split('/\s+/u', $text) ?: [];
        if (count($words) > 10) {
            return '';
        }
        if (count($words) > 8) {
            $text = implode(' ', array_slice($words, 0, 8));
        }
        if (mb_strlen($text) > 48) {
            $cut = mb_substr($text, 0, 48);
            $space = mb_strrpos($cut, ' ');
            $text = $space !== false && $space > 16 ? mb_substr($cut, 0, $space) : $cut;
        }

        return trim($text, " \t.,;:—-");
    }
}
