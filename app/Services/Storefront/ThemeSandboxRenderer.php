<?php

namespace App\Services\Storefront;

use App\Models\Theme;
use App\Services\Storefront\Modules\ModuleRegistry;
use App\Services\Storefront\Modules\RenderContext;
use App\Support\VisitDevice;
use Illuminate\Http\Response;

class ThemeSandboxRenderer
{
    public function __construct(
        protected DesignThemeService $themes,
        protected ThemeSandboxService $sandbox,
        protected ModuleRegistry $modules,
    ) {}

    /**
     * @param  array{handle?: string, page_id?: ?string, product?: ?array}  $options
     */
    public function response(Theme $theme, array $options = []): Response
    {
        $design = $this->themes->forDisplay($this->themes->normalizeDesign(
            is_array($theme->design) ? $theme->design : [],
            $theme->name
        ));

        $page = null;
        if (! empty($options['page_id'])) {
            $page = $this->themes->findPage($design, (string) $options['page_id']);
        }
        if (! $page && ! empty($options['handle'])) {
            $page = $this->themes->findPageByHandle($design, (string) $options['handle'], false)
                ?: $this->themes->findPageByType($design, (string) $options['handle'], false);
        }
        if (! $page) {
            $page = $this->themes->findPageByType($design, 'landing', false)
                ?: $this->themes->findPageByHandle($design, 'index', false);
        }
        if (! $page || ! $this->modules->pageIsRenderable($page)) {
            abort(404, 'Esta plantilla no tiene HTML para esa página.');
        }

        $product = $options['product'] ?? null;
        if (! $product && ($page['type'] ?? '') === 'product') {
            $product = $this->sandbox->demoProducts($theme)->first();
        }

        $fakeStore = (object) [
            'id' => 0,
            'name' => $theme->name,
            'slug' => $theme->slug,
            'store_type' => 'template',
        ];
        $payload = $this->sandbox->payload($theme, $page, is_array($product) ? $product : null);
        $visit = VisitDevice::fromRequest(request());
        $payload = $this->modules->applyDeviceFlags($payload, $visit);
        $usesModules = $this->modules->pageUsesModules($page);
        $payload['engine'] = $usesModules ? 'twig' : 'legacy';
        $bodyAttrs = ['class' => '', 'id' => '', 'style' => ''];
        $extraStylesheets = [];

        if ($usesModules) {
            $staticHtml = '';
            $layout = $this->modules->layoutFor($page, $visit);
            $ctx = new RenderContext($payload, $design, $page, '', $visit);
            if (in_array('static', $layout, true)) {
                $staticHtml = $this->modules->renderStaticBody((string) ($page['html'] ?? ''), $ctx);
                $ctx = new RenderContext($payload, $design, $page, $staticHtml, $visit);
            }
            $html = $this->modules->assemble($ctx, $layout);
            $html = $this->themes->rewriteHtmlAssetUrls($html, $design['assets'] ?? []);
            $html = $this->themes->rewriteRelativePageHrefs($html, url('/t/'.$theme->slug.'/pages'));
            $html = DesignAssetUrl::localize($html);
        } else {
            $rawHtml = (string) ($page['html'] ?? '');
            $bodyAttrs = $this->themes->extractBodyAttributes($rawHtml);
            $extraStylesheets = $this->themes->extractStylesheetUrls($rawHtml);
            $html = $this->themes->extractBodyHtml($rawHtml);
            if (($page['type'] ?? '') === 'product') {
                $html = $this->themes->stripGarbageDescriptionBinds($html);
                $html = $this->themes->placeVariantsHook($html);
                $html = $this->themes->ensureMediaCarouselInHtml($html);
            }
            $html = $this->themes->rewriteHtmlAssetUrls($html, $design['assets'] ?? []);
            $html = $this->themes->rewriteRelativePageHrefs(
                $html,
                url('/t/'.$theme->slug.'/pages')
            );
            $html = DesignAssetUrl::localize($this->replaceTokens($html, $fakeStore, $payload));
        }
        $css = DesignAssetUrl::localize($this->themes->composeStorefrontCss($design, $page));
        $css = DesignAssetUrl::localize($this->themes->rewriteCssAssetUrls($css, $design['assets'] ?? []));
        $js = DesignAssetUrl::localize(trim((string) ($design['global_js'] ?? '')."\n".(string) ($page['js'] ?? '')));
        $payloadJson = DesignAssetUrl::localize(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
        );

        $visitClass = 'md-visit-'.$visit;
        $themeClass = $this->themes->themeBodyClass($design);
        $bodyAttrs['class'] = trim((string) ($bodyAttrs['class'] ?? '').' '.$visitClass.' '.$themeClass);

        $body = view('storefront.custom', [
            'store' => $fakeStore,
            'html' => $html,
            'css' => $css,
            'js' => $js,
            'checkout' => $design['checkout'] ?? [],
            'page' => $page,
            'preview' => false,
            'sandbox' => true,
            'sandboxLabel' => 'Sandbox · '.$theme->name.' · cupón DEMO10',
            'sandboxNav' => $payload['urls'] ?? [],
            'sandboxModules' => $payload['modules'] ?? [],
            'sandboxModuleLabels' => $this->sandbox->moduleLabels(),
            'payloadJson' => $payloadJson,
            'bodyClass' => $bodyAttrs['class'] ?? '',
            'bodyId' => $bodyAttrs['id'] ?? '',
            'bodyStyle' => $bodyAttrs['style'] ?? '',
            'extraStylesheets' => $extraStylesheets,
            'htmlLang' => $payload['locale'] ?? 'en',
            'moduleEngine' => $usesModules,
            'pixels' => $payload['pixels'] ?? [],
            'deferPixels' => (bool) ($payload['modules']['cookies'] ?? false),
            'visit' => $visit,
        ])->render();

        return response($body, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function replaceTokens(string $html, object $store, array $payload): string
    {
        $urls = $payload['urls'] ?? [];
        $star = is_array($payload['star_product'] ?? null) ? $payload['star_product'] : [];
        $map = [
            '{{store.name}}' => e($store->name),
            '{{store.slug}}' => e($store->slug),
            '{{store.id}}' => (string) $store->id,
            '{{products.count}}' => (string) count($payload['products'] ?? []),
            '{{star.name}}' => e((string) ($star['name'] ?? $star['title'] ?? '')),
            '{{star.price_formatted}}' => e((string) ($star['price_formatted'] ?? '')),
            '{{star.url}}' => e((string) ($star['url'] ?? '#')),
            '{{star.image}}' => e((string) ($star['image'] ?? '')),
            '{{urls.home}}' => e((string) ($urls['home'] ?? '#')),
            '{{urls.catalog}}' => e((string) ($urls['catalog'] ?? '#')),
            '{{urls.cart}}' => e((string) ($urls['cart'] ?? '#')),
            '{{urls.checkout}}' => e((string) ($urls['checkout'] ?? '#')),
            '{{urls.products_json}}' => e((string) ($urls['products_json'] ?? '#')),
        ];

        return strtr($html, $map);
    }
}
