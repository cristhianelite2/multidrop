<?php

namespace App\Services\Storefront;

use App\Domain\AI\AiTaskRouter;
use App\Models\Store;
use Illuminate\Support\Facades\Log;

/**
 * MIIA corrige HTML/CSS/JS del theme con el problema descrito + contexto Multidrop.
 */
class DesignAiFixService
{
    public function __construct(
        protected AiTaskRouter $ai,
        protected DesignThemeService $themes,
        protected CustomDesignRenderer $renderer
    ) {}

    /**
     * @return array{success: bool, message?: string, error?: string, summary?: string, changed?: list<string>, provider?: string}
     */
    public function resolve(Store $store, string $problem, ?string $pageId = null, string $scope = 'page', string $task = 'design_fix'): array
    {
        if (! $this->ai->hasMiia()) {
            return [
                'success' => false,
                'error' => 'Configura la API Key de MIIA en Admin → General.',
            ];
        }

        $task = $this->ai->taskMeta($task) ? $task : 'design_fix';

        $problem = trim($problem);
        if ($problem === '') {
            return ['success' => false, 'error' => 'Describe el problema a resolver.'];
        }

        $design = $this->themes->normalize($store);
        $page = null;
        if ($pageId) {
            $page = $this->themes->findPage($design, $pageId);
        }
        if (! $page) {
            $page = $this->themes->findPageByType($design, 'landing', false)
                ?: ($design['pages'][0] ?? null);
        }
        if (! $page) {
            return ['success' => false, 'error' => 'No hay página de diseño para corregir.'];
        }

        $scope = in_array($scope, ['page', 'global', 'both'], true) ? $scope : 'page';
        $products = $this->renderer->productsForStore($store, $design, true)->take(6)->values()->all();

        $context = $this->buildContext($store, $design, $page, $products, $scope);
        $system = $this->systemPrompt();
        $user = "Problema reportado por el merchant:\n{$problem}\n\n--- CONTEXTO ---\n{$context}";

        $result = $this->ai->chat($task, [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], [
            'temperature' => 0.35,
            'timeout' => 90,
        ]);

        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'error' => is_string($result['error'] ?? null)
                    ? mb_substr($result['error'], 0, 400)
                    : 'La IA no pudo responder.',
                'provider' => $result['provider'] ?? 'miia',
            ];
        }

        $parsed = $this->parseFixPayload((string) ($result['content'] ?? ''));
        if ($parsed === null) {
            Log::warning('Design AI fix parse failed', [
                'store_id' => $store->id,
                'preview' => mb_substr((string) ($result['content'] ?? ''), 0, 500),
            ]);

            return [
                'success' => false,
                'error' => 'La IA respondió pero no en el formato esperado. Reintenta con un problema más concreto.',
                'provider' => $result['provider'] ?? 'miia',
            ];
        }

        $changed = [];
        $design = $this->themes->normalize($store);

        if (in_array($scope, ['page', 'both'], true)) {
            foreach ($design['pages'] as $i => $row) {
                if (($row['id'] ?? '') !== ($page['id'] ?? '')) {
                    continue;
                }
                if (array_key_exists('html', $parsed) && is_string($parsed['html']) && ($row['type'] ?? '') === 'page') {
                    $design['pages'][$i]['html'] = $this->themes->extractBodyHtml($parsed['html']);
                    $changed[] = 'html';
                }
                if (array_key_exists('css', $parsed) && is_string($parsed['css'])) {
                    $design['pages'][$i]['css'] = $parsed['css'];
                    $changed[] = 'css página';
                }
                if (array_key_exists('js', $parsed) && is_string($parsed['js'])) {
                    $design['pages'][$i]['js'] = $parsed['js'];
                    $changed[] = 'js página';
                }
                if (array_key_exists('layout', $parsed) && is_array($parsed['layout'])) {
                    $design['pages'][$i]['modules'] = $parsed['layout'];
                    $changed[] = 'layout';
                }
                $design['pages'][$i]['updated_at'] = now()->toIso8601String();
                break;
            }
        }

        if (in_array($scope, ['global', 'both'], true)) {
            if (array_key_exists('global_css', $parsed) && is_string($parsed['global_css'])) {
                $design['global_css'] = $parsed['global_css'];
                $changed[] = 'CSS global';
            }
            if (array_key_exists('global_js', $parsed) && is_string($parsed['global_js'])) {
                $design['global_js'] = $parsed['global_js'];
                $changed[] = 'JS global';
            }
            if (array_key_exists('modules_css', $parsed) && is_string($parsed['modules_css'])) {
                $design['modules_css'] = $parsed['modules_css'];
                $changed[] = 'CSS módulos';
            }
        }

        if ($changed === []) {
            return [
                'success' => false,
                'error' => 'La IA no devolvió cambios aplicables para el alcance elegido.',
                'summary' => $parsed['summary'] ?? null,
                'provider' => $result['provider'] ?? 'miia',
            ];
        }

        $this->themes->save($store, $design);

        return [
            'success' => true,
            'message' => 'Correcciones aplicadas: '.implode(', ', $changed).'.',
            'summary' => $parsed['summary'] ?? 'Problema resuelto.',
            'changed' => $changed,
            'page_id' => $page['id'] ?? null,
            'provider' => $result['provider'] ?? 'miia',
        ];
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $page
     * @param  list<array<string, mixed>>  $products
     */
    protected function buildContext(Store $store, array $design, array $page, array $products, string $scope): string
    {
        $productSample = collect($products)->map(fn ($p) => [
            'id' => $p['id'] ?? null,
            'name' => $p['name'] ?? null,
            'slug' => $p['slug'] ?? null,
            'handle' => $p['handle'] ?? null,
            'price' => $p['price'] ?? null,
            'featured' => $p['featured'] ?? false,
            'is_featured' => $p['is_featured'] ?? false,
            'is_star' => $p['is_star'] ?? $p['star'] ?? false,
            'image' => $p['image'] ?? null,
        ])->all();

        $starId = $store->starProductId();
        $parts = [
            'Store: '.$store->name.' (slug='.$store->slug.', id='.$store->id.')',
            'Star product id: '.($starId ?: 'fallback (featured/first)'),
            'Page: title='.($page['title'] ?? '').' type='.($page['type'] ?? '').' handle='.($page['handle'] ?? '').' id='.($page['id'] ?? ''),
            'Scope: '.$scope,
            'Product count in context: '.count($productSample),
            'Sample products JSON: '.mb_substr(json_encode($productSample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]', 0, 4000),
            'Runtime contract:',
            '- Engine Twig: layout en page.modules[]. HTML comercial lo genera la plataforma.',
            '- La plantilla SOLO pisa CSS (theme.css / modules.css / page.css). No reescribas HTML de módulos.',
            '- Selectores: .md-header .md-hero .md-grid .md-card .md-pdp .md-cart .md-mod-urgency .md-mod-upsell #md-atc-modal',
            '- Prohibido data-md-bind / data-md-products / hijack de Cart.',
        ];

        if (in_array($scope, ['page', 'both'], true)) {
            $parts[] = '===CURRENT_LAYOUT==='."\n".json_encode($page['modules'] ?? [], JSON_UNESCAPED_UNICODE);
            $parts[] = '===CURRENT_HTML (solo páginas estáticas)==='."\n".$this->clip((string) ($page['html'] ?? ''), 8000);
            $parts[] = '===CURRENT_PAGE_CSS==='."\n".$this->clip((string) ($page['css'] ?? ''), 12000);
            $parts[] = '===CURRENT_PAGE_JS==='."\n".$this->clip((string) ($page['js'] ?? ''), 12000);
        }
        if (in_array($scope, ['global', 'both'], true)) {
            $parts[] = '===CURRENT_GLOBAL_CSS==='."\n".$this->clip((string) ($design['global_css'] ?? ''), 18000);
            $parts[] = '===CURRENT_GLOBAL_JS==='."\n".$this->clip((string) ($design['global_js'] ?? ''), 18000);
        }

        $notes = trim((string) ($design['prompt_notes'] ?? ''));
        if ($notes !== '') {
            $parts[] = 'Merchant notes: '.$this->clip($notes, 2000);
        }

        return implode("\n\n", $parts);
    }

    protected function systemPrompt(): string
    {
        return <<<'TXT'
Eres MIIA, ingeniera front-end de Multidrop. Recibes un problema de diseño/theme.
Los módulos (header, grid, pdp, cart, checkout, urgencia, upsell, ruleta) son HTML de plataforma.
Tú CORRIGES CSS y, si hace falta, el orden en layout (page.modules). No reescribas HTML comercial.

Responde SOLO con este formato (sin markdown fences):

===SUMMARY===
(1-3 frases de qué cambiaste)
===HTML===
(vacío salvo página estática FAQ/nosotros)
===CSS===
(css de página, o vacío)
===JS===
(js de página, o vacío — nunca carrito/grids)
===GLOBAL_CSS===
(theme.css, o vacío)
===MODULES_CSS===
(override de .md-mod-*, o vacío)
===LAYOUT===
(JSON array de módulos, o vacío)
===GLOBAL_JS===
(solo menú móvil; vacío si no aplica)
===END===

Reglas:
- Si una sección no cambia, déjala vacía.
- Conserva la identidad visual salvo que el problema pida lo contrario.
- No inventes APIs de pago ni secretos.
- No uses data-md-bind ni data-md-products.
TXT;
    }

    /**
     * @return array{summary?: string, html?: string, css?: string, js?: string, global_css?: string, global_js?: string}|null
     */
    protected function parseFixPayload(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        // Quitar fences si vinieron
        $content = (string) preg_replace('/^```(?:\w+)?\s*/m', '', $content);
        $content = (string) preg_replace('/```$/m', '', $content);

        if (! str_contains($content, '===SUMMARY===') && ! str_contains($content, '===HTML===')) {
            // Intento JSON
            if (preg_match('/\{.*\}/s', $content, $m)) {
                $json = json_decode($m[0], true);
                if (is_array($json)) {
                    return $this->normalizeParsed($json);
                }
            }

            return null;
        }

        $get = function (string $key) use ($content): ?string {
            $keys = ['SUMMARY', 'HTML', 'CSS', 'JS', 'GLOBAL_CSS', 'GLOBAL_JS', 'MODULES_CSS', 'LAYOUT', 'END'];
            $pattern = '/==='.$key.'===\s*(.*?)(?====(?:'.implode('|', $keys).')===|$)/s';
            if (! preg_match($pattern, $content, $m)) {
                return null;
            }

            return trim($m[1]);
        };

        $out = [
            'summary' => $get('SUMMARY') ?? '',
            'html' => $get('HTML'),
            'css' => $get('CSS'),
            'js' => $get('JS'),
            'global_css' => $get('GLOBAL_CSS'),
            'global_js' => $get('GLOBAL_JS'),
            'modules_css' => $get('MODULES_CSS'),
            'layout' => $get('LAYOUT'),
        ];

        return $this->normalizeParsed($out);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{summary?: string, html?: string, css?: string, js?: string, global_css?: string, global_js?: string}|null
     */
    protected function normalizeParsed(array $raw): ?array
    {
        $map = [
            'summary' => 'summary',
            'html' => 'html',
            'css' => 'css',
            'js' => 'js',
            'global_css' => 'global_css',
            'globalCss' => 'global_css',
            'global_js' => 'global_js',
            'globalJs' => 'global_js',
            'modules_css' => 'modules_css',
            'modulesCss' => 'modules_css',
        ];
        $out = [];
        foreach ($map as $from => $to) {
            if (! array_key_exists($from, $raw)) {
                continue;
            }
            $val = $raw[$from];
            if (! is_string($val)) {
                continue;
            }
            $val = trim($val);
            if ($val === '' || strcasecmp($val, 'vacío') === 0 || strcasecmp($val, 'empty') === 0) {
                continue;
            }
            $out[$to] = $val;
        }

        if (isset($raw['layout'])) {
            $layout = $raw['layout'];
            if (is_string($layout)) {
                $decoded = json_decode($layout, true);
                $layout = is_array($decoded) ? $decoded : (preg_split('/[,\s]+/', trim($layout)) ?: []);
            }
            if (is_array($layout) && $layout !== []) {
                $out['layout'] = array_values(array_filter(array_map('strval', $layout)));
            }
        }

        if ($out === [] || (count($out) === 1 && isset($out['summary']))) {
            return isset($out['summary']) ? $out : null;
        }

        return $out;
    }

    protected function clip(string $text, int $max): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max)."\n…[truncated]";
    }
}
