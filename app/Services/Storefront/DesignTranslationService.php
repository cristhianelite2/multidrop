<?php

namespace App\Services\Storefront;

use App\Domain\AI\AiTaskRouter;
use App\Models\Market;
use App\Models\Store;
use App\Models\Theme;
use App\Services\Buyer\BuyerPortalLocale;
use Illuminate\Support\Facades\Log;

/**
 * Traduce copy visible de una plantilla (global Theme o copia Store) vía MIIA.
 * Conserva hooks data-md-*, tokens {{…}}, clases y lógica JS.
 */
class DesignTranslationService
{
    public function __construct(
        protected AiTaskRouter $ai,
        protected DesignThemeService $themes,
        protected ThemeLibraryService $library,
        protected BuyerPortalLocale $locales,
    ) {}

    /**
     * @return list<array{locale: string, label: string, name: string, iso: string}>
     */
    public function availableLocales(): array
    {
        $preferred = [
            'es_MX' => 'Español (México)',
            'es_ES' => 'Español (España)',
            'en_US' => 'English (US)',
            'en_GB' => 'English (UK)',
            'en_CA' => 'English (Canada)',
            'en_AU' => 'English (Australia)',
            'pt_PT' => 'Português (PT)',
            'pt_BR' => 'Português (BR)',
            'fr_FR' => 'Français',
            'de_DE' => 'Deutsch',
            'it_IT' => 'Italiano',
            'nl_NL' => 'Nederlands',
            'pl_PL' => 'Polski',
            'sv_SE' => 'Svenska',
            'da_DK' => 'Dansk',
            'nb_NO' => 'Norsk',
            'fi_FI' => 'Suomi',
            'hu_HU' => 'Magyar',
            'cs_CZ' => 'Čeština',
            'ro_RO' => 'Română',
            'el_GR' => 'Ελληνικά',
        ];

        foreach (Market::query()->where('is_active', true)->whereNotNull('locale')->get(['locale', 'name', 'code']) as $m) {
            $loc = (string) $m->locale;
            if ($loc !== '' && ! isset($preferred[$loc])) {
                $preferred[$loc] = $m->name.' ('.$m->code.')';
            }
        }

        $out = [];
        foreach ($preferred as $locale => $label) {
            $iso = strtolower((string) substr($locale, -2));
            if ($iso === 'uk') {
                $iso = 'gb';
            }
            $out[] = [
                'locale' => $locale,
                'name' => $label,
                'label' => $label,
                'iso' => strlen($iso) === 2 ? $iso : '',
            ];
        }

        return $out;
    }

    /**
     * @return array{success: bool, message?: string, error?: string, summary?: string, changed?: list<string>, locale?: string, provider?: string}
     */
    public function translateTheme(Theme $theme, string $targetLocale): array
    {
        $design = $this->themes->normalizeDesign(
            is_array($theme->design) ? $theme->design : [],
            $theme->name
        );

        $result = $this->translateDesignArray($design, $targetLocale, 'theme:'.$theme->slug);
        if (! ($result['success'] ?? false)) {
            return $result;
        }

        $this->library->saveTheme($theme, $result['design']);

        return [
            'success' => true,
            'message' => $result['message'] ?? 'Plantilla global traducida.',
            'summary' => $result['summary'] ?? null,
            'changed' => $result['changed'] ?? [],
            'locale' => $result['locale'] ?? $this->locales->normalize($targetLocale),
            'provider' => 'miia',
        ];
    }

    /**
     * @return array{success: bool, message?: string, error?: string, summary?: string, changed?: list<string>, locale?: string, provider?: string}
     */
    public function translateStore(Store $store, string $targetLocale): array
    {
        $design = $this->themes->normalize($store);
        $result = $this->translateDesignArray($design, $targetLocale, 'store:'.$store->slug);
        if (! ($result['success'] ?? false)) {
            return $result;
        }

        $this->themes->save($store, $result['design']);

        return [
            'success' => true,
            'message' => $result['message'] ?? 'Copia de plantilla traducida.',
            'summary' => $result['summary'] ?? null,
            'changed' => $result['changed'] ?? [],
            'locale' => $result['locale'] ?? $this->locales->normalize($targetLocale),
            'provider' => 'miia',
        ];
    }

    /**
     * @param  array<string, mixed>  $design
     * @return array{success: bool, design?: array<string, mixed>, message?: string, error?: string, summary?: string, changed?: list<string>, locale?: string}
     */
    protected function translateDesignArray(array $design, string $targetLocale, string $contextId): array
    {
        if (! $this->ai->hasMiia()) {
            return [
                'success' => false,
                'error' => 'Configura la API Key de MIIA en Admin → General.',
            ];
        }

        $targetLocale = trim($targetLocale);
        if ($targetLocale === '') {
            return ['success' => false, 'error' => 'Indica el idioma destino.'];
        }

        $normalized = $this->locales->normalize($targetLocale);
        $langAttr = $normalized;
        $langLabel = $this->localeLabel($targetLocale);
        $pages = is_array($design['pages'] ?? null) ? $design['pages'] : [];
        if ($pages === []) {
            return ['success' => false, 'error' => 'La plantilla no tiene páginas para traducir.'];
        }

        $changed = [];
        $summaries = [];
        $errors = [];

        foreach ($pages as $i => $page) {
            if (! is_array($page)) {
                continue;
            }
            $pageResult = $this->translatePage($page, $langLabel, $targetLocale, $langAttr, $contextId);
            if (! ($pageResult['success'] ?? false)) {
                $errors[] = ($page['handle'] ?? $page['id'] ?? 'page').': '.($pageResult['error'] ?? 'falló');
                continue;
            }
            $design['pages'][$i] = $pageResult['page'];
            $changed[] = 'página '.($page['handle'] ?? $page['title'] ?? $i);
            if (! empty($pageResult['summary'])) {
                $summaries[] = (string) $pageResult['summary'];
            }
        }

        $globalJs = (string) ($design['global_js'] ?? '');
        if (trim($globalJs) !== '') {
            $jsResult = $this->translateGlobalJs($globalJs, $langLabel, $targetLocale, $contextId);
            if ($jsResult['success'] ?? false) {
                $design['global_js'] = $jsResult['js'];
                $changed[] = 'JS global';
                if (! empty($jsResult['summary'])) {
                    $summaries[] = (string) $jsResult['summary'];
                }
            } else {
                $errors[] = 'global_js: '.($jsResult['error'] ?? 'falló');
            }
        }

        if ($changed === []) {
            return [
                'success' => false,
                'error' => $errors !== []
                    ? 'No se pudo traducir: '.implode(' · ', array_slice($errors, 0, 3))
                    : 'MIIA no devolvió cambios aplicables.',
            ];
        }

        $design['locale'] = $normalized;
        $design['lang'] = $langAttr;
        $design['default_locale'] = $targetLocale;
        $locales = is_array($design['locales'] ?? null) ? $design['locales'] : [];
        $locales = array_values(array_unique(array_filter(array_map('strval', $locales))));
        if (! in_array($targetLocale, $locales, true)) {
            $locales[] = $targetLocale;
        }
        $design['locales'] = $locales;

        $currencySvc = app(\App\Services\Currency\CurrencyService::class);
        $suggested = $currencySvc->currencyForLocale($targetLocale);
        if ($suggested) {
            $currencies = is_array($design['currencies'] ?? null) ? $design['currencies'] : [];
            $currencies = array_values(array_unique(array_filter(array_map(
                fn ($c) => strtoupper((string) $c),
                $currencies
            ))));
            if (! in_array($suggested, $currencies, true)) {
                $currencies[] = $suggested;
            }
            if (empty($design['default_currency']) && empty($design['currency'])) {
                $design['default_currency'] = $suggested;
                $design['currency'] = $suggested;
            }
            $design['currencies'] = $currencies;
        }

        return [
            'success' => true,
            'design' => $design,
            'message' => 'Traducido a '.$langLabel.' ('.$targetLocale.'): '.implode(', ', $changed).'.'
                .($errors !== [] ? ' Avisos: '.implode(' · ', array_slice($errors, 0, 2)) : ''),
            'summary' => implode(' ', array_slice($summaries, 0, 4)),
            'changed' => $changed,
            'locale' => $normalized,
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array{success: bool, page?: array<string, mixed>, summary?: string, error?: string}
     */
    protected function translatePage(array $page, string $langLabel, string $locale, string $langAttr, string $contextId): array
    {
        $title = (string) ($page['title'] ?? '');
        $html = (string) ($page['html'] ?? '');
        $js = (string) ($page['js'] ?? '');

        if (trim($title.$html.$js) === '') {
            return ['success' => true, 'page' => $page, 'summary' => ''];
        }

        $system = $this->systemPrompt($langLabel, $locale, $langAttr);
        $user = implode("\n\n", [
            'Contexto: '.$contextId.' · página handle='.($page['handle'] ?? '').' type='.($page['type'] ?? ''),
            '===TITLE==='."\n".$this->clip($title, 200),
            '===HTML==='."\n".$this->clip($html, 32000),
            '===JS==='."\n".$this->clip($js, 14000),
        ]);

        $result = $this->ai->chat('design_translate', [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ]);

        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'error' => is_string($result['error'] ?? null)
                    ? mb_substr($result['error'], 0, 300)
                    : 'MIIA no respondió.',
            ];
        }

        $parsed = $this->parsePagePayload((string) ($result['content'] ?? ''));
        if ($parsed === null) {
            Log::warning('Design translation parse failed', [
                'context' => $contextId,
                'handle' => $page['handle'] ?? null,
                'preview' => mb_substr((string) ($result['content'] ?? ''), 0, 400),
            ]);

            return ['success' => false, 'error' => 'Respuesta MIIA inválida.'];
        }

        if (isset($parsed['title'])) {
            $page['title'] = $parsed['title'];
        }
        if (isset($parsed['html'])) {
            $page['html'] = $this->ensureHtmlLang($parsed['html'], $langAttr);
        } elseif ($html !== '') {
            $page['html'] = $this->ensureHtmlLang($html, $langAttr);
        }
        if (isset($parsed['js'])) {
            $page['js'] = $parsed['js'];
        }
        $page['updated_at'] = now()->toIso8601String();

        return [
            'success' => true,
            'page' => $page,
            'summary' => $parsed['summary'] ?? '',
        ];
    }

    /**
     * @return array{success: bool, js?: string, summary?: string, error?: string}
     */
    protected function translateGlobalJs(string $js, string $langLabel, string $locale, string $contextId): array
    {
        $system = <<<TXT
Eres MIIA. Traduce SOLO los textos visibles al usuario en este theme.js al idioma {$langLabel} (locale {$locale}).
NO cambies nombres de funciones, variables, selectores, APIs Multidrop, ni lógica.
Conserva comillas y concatenaciones. Si una cadena es técnica (selector CSS, URL, clave), déjala igual.

Responde SOLO:
===SUMMARY===
(1 frase)
===JS===
(código completo traducido)
===END===
TXT;

        $result = $this->ai->chat('design_translate', [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "Contexto: {$contextId}\n\n===JS===\n".$this->clip($js, 28000)],
        ]);

        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'error' => is_string($result['error'] ?? null)
                    ? mb_substr($result['error'], 0, 300)
                    : 'MIIA no respondió (JS).',
            ];
        }

        $parsed = $this->parsePagePayload((string) ($result['content'] ?? ''), onlyJs: true);
        if ($parsed === null || empty($parsed['js'])) {
            return ['success' => false, 'error' => 'JS global: formato inválido.'];
        }

        return [
            'success' => true,
            'js' => $parsed['js'],
            'summary' => $parsed['summary'] ?? '',
        ];
    }

    protected function systemPrompt(string $langLabel, string $locale, string $langAttr): string
    {
        return <<<TXT
Eres MIIA, copywriter + front-end de Multidrop. Traduce la plantilla al idioma {$langLabel} (locale {$locale}).

Responde SOLO con este formato (sin markdown fences):

===SUMMARY===
(1-2 frases)
===TITLE===
(título de página traducido)
===HTML===
(HTML completo traducido; si venía fragmento body, devuelve fragmento)
===JS===
(JS de página traducido, o vacío si no había)
===END===

Reglas CRÍTICAS:
- Traduce TODO el texto visible: nav, hero, CTAs, labels, footers, empty states, aria-label, title attributes, meta description si aparece.
- Pon/actualiza lang="{$langAttr}" en <html> si el documento lo incluye.
- NO traduzcas ni alteres: tokens {{…}}, atributos data-md-*, class, id, href con {{urls.*}}, nombres de archivos assets/, selectores CSS, lógica JS, APIs Multidrop.
- NO inventes productos ni precios. NO elimines hooks.
- Mantén la estructura HTML y el estilo. Solo cambia el idioma del copy.
- Si una sección no aplica, déjala vacía bajo su encabezado.
TXT;
    }

    /**
     * @return array{summary?: string, title?: string, html?: string, js?: string}|null
     */
    protected function parsePagePayload(string $content, bool $onlyJs = false): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        $content = (string) preg_replace('/^```(?:\w+)?\s*/m', '', $content);
        $content = (string) preg_replace('/```$/m', '', $content);

        if (! str_contains($content, '===') && preg_match('/\{.*\}/s', $content, $m)) {
            $json = json_decode($m[0], true);
            if (is_array($json)) {
                return $this->normalizeParsed($json, $onlyJs);
            }
        }

        if (! str_contains($content, '===SUMMARY===') && ! str_contains($content, '===HTML===') && ! str_contains($content, '===JS===')) {
            return null;
        }

        $get = function (string $key) use ($content): ?string {
            $keys = ['SUMMARY', 'TITLE', 'HTML', 'JS', 'CSS', 'END'];
            $pattern = '/==='.$key.'===\s*(.*?)(?====(?:'.implode('|', $keys).')===|$)/s';
            if (! preg_match($pattern, $content, $m)) {
                return null;
            }

            return trim($m[1]);
        };

        return $this->normalizeParsed([
            'summary' => $get('SUMMARY') ?? '',
            'title' => $get('TITLE'),
            'html' => $get('HTML'),
            'js' => $get('JS'),
        ], $onlyJs);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{summary?: string, title?: string, html?: string, js?: string}|null
     */
    protected function normalizeParsed(array $raw, bool $onlyJs = false): ?array
    {
        $out = [];
        foreach (['summary', 'title', 'html', 'js'] as $key) {
            if (! array_key_exists($key, $raw) || ! is_string($raw[$key])) {
                continue;
            }
            $val = trim($raw[$key]);
            if ($val === '' || strcasecmp($val, 'vacío') === 0 || strcasecmp($val, 'empty') === 0) {
                continue;
            }
            $out[$key] = $val;
        }

        if ($onlyJs) {
            return isset($out['js']) ? $out : null;
        }

        if ($out === [] || (count($out) === 1 && isset($out['summary']))) {
            return isset($out['summary']) ? $out : null;
        }

        return $out;
    }

    protected function ensureHtmlLang(string $html, string $langAttr): string
    {
        if (preg_match('/<html\b[^>]*>/i', $html)) {
            if (preg_match('/\blang\s*=\s*["\'][^"\']*["\']/i', $html)) {
                return (string) preg_replace('/\blang\s*=\s*["\'][^"\']*["\']/i', 'lang="'.$langAttr.'"', $html, 1);
            }

            return (string) preg_replace('/<html\b/i', '<html lang="'.$langAttr.'"', $html, 1);
        }

        return $html;
    }

    protected function localeLabel(string $locale): string
    {
        $locale = strtolower(str_replace('-', '_', trim($locale)));
        $short = explode('_', $locale)[0];

        return match ($short) {
            'es' => 'español',
            'en' => 'inglés',
            'pt' => 'portugués',
            'fr' => 'francés',
            'de' => 'alemán',
            'it' => 'italiano',
            'nl' => 'neerlandés',
            'pl' => 'polaco',
            'sv' => 'sueco',
            'da' => 'danés',
            'nb', 'no' => 'noruego',
            'fi' => 'finlandés',
            'hu' => 'húngaro',
            'cs' => 'checo',
            'ro' => 'rumano',
            'el' => 'griego',
            default => $locale,
        };
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
