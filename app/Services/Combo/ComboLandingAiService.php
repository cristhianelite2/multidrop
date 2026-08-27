<?php

namespace App\Services\Combo;

use App\Models\Product;
use App\Models\Store;
use App\Services\Storefront\DesignAiFixService;

class ComboLandingAiService
{
    public function __construct(
        protected DesignAiFixService $fixer
    ) {}

    /**
     * @param  array{
     *     name?: string,
     *     description?: string,
     *     slug?: string,
     *     product_ids?: list<int>,
     *     images?: list<array{style?: string, url?: string}>,
     *     combo_product_id?: int|null
     * }  $payload
     * @return array{success: bool, message?: string, error?: string, summary?: string, changed?: list<string>, provider?: string}
     */
    public function apply(Store $store, array $payload): array
    {
        $images = collect($payload['images'] ?? [])
            ->map(function ($row) {
                if (! is_array($row)) {
                    return null;
                }
                $url = trim((string) ($row['url'] ?? ''));
                if ($url === '') {
                    return null;
                }

                return [
                    'style' => trim((string) ($row['style'] ?? 'imagenes')),
                    'url' => $url,
                ];
            })
            ->filter()
            ->unique(fn (array $row) => $row['style'].'|'.$row['url'])
            ->values()
            ->all();

        if ($images === []) {
            return [
                'success' => false,
                'error' => 'Genera primero las imágenes promocionales para aplicarlas a la landing.',
            ];
        }

        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', array_map('intval', $payload['product_ids'] ?? []))
            ->get(['id', 'name', 'slug', 'price', 'currency', 'image_url']);

        $heroUrl = $this->urlForStyle($images, ['imagenes', 'oferta']) ?: ($images[0]['url'] ?? '');
        $comboProductId = (int) ($payload['combo_product_id'] ?? 0);
        if ($comboProductId > 0) {
            $store->setStarProductId($comboProductId);
        }

        $problem = $this->buildProblem($store, $payload, $products, $images, $heroUrl);

        return $this->fixer->resolve($store, $problem, null, 'both', 'combo_landing');
    }

    /**
     * @param  list<array{style: string, url: string}>  $images
     * @param  list<string>  $styles
     */
    protected function urlForStyle(array $images, array $styles): string
    {
        foreach ($styles as $style) {
            foreach ($images as $image) {
                if (($image['style'] ?? '') === $style) {
                    return (string) $image['url'];
                }
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @param  list<array{style: string, url: string}>  $images
     */
    protected function buildProblem(Store $store, array $payload, $products, array $images, string $heroUrl): string
    {
        $lines = [
            'Objetivo: adaptar la LANDING del producto principal (estrella) y TODA la tienda (móvil + desktop) para este combo promocional.',
            'Usa las URLs de imágenes promocionales 9:16 ya generadas. NO inventes otras URLs.',
            'Combo: '.trim((string) ($payload['name'] ?? 'Combo')),
            'Slug: '.trim((string) ($payload['slug'] ?? '')),
            'Descripción: '.trim((string) ($payload['description'] ?? '')),
            'Hero / cover (prioridad imagenes u oferta): '.$heroUrl,
            '',
            'Imágenes por estilo (úsalas en CSS con url("…") sobre .md-hero__img, .md-niche, .md-pdp, .md-card):',
        ];

        foreach ($images as $image) {
            $lines[] = '- estilo='.$image['style'].' → '.$image['url'];
        }

        $lines[] = '';
        $lines[] = 'Productos del pack:';
        foreach ($products as $product) {
            $lines[] = '- #'.$product->id.' '.$product->name.' · '.$product->price.' '.$product->currency.' · foto='.($product->image_url ?: 'n/a');
        }

        $lines[] = '';
        $lines[] = 'Reglas de implementación:';
        $lines[] = '1. Pon el combo como protagonista visual del hero (.md-hero / .md-mod-hero / .md-hero__img / .md-niche).';
        $lines[] = '2. Fuerza imagen del hero con CSS: .md-hero__img { content: url("'.$heroUrl.'"); width:100%; height:auto; aspect-ratio:9/16; object-fit:cover; }';
        $lines[] = '3. Optimiza móvil primero: stack vertical, tipografía legible, CTA sticky si encaja, imágenes 9:16 sin recortes raros, sin overflow horizontal.';
        $lines[] = '4. Aplica el mismo lenguaje visual en PDP (.md-pdp), grid (.md-card), header y footer. Todo el sitio debe verse coherente.';
        $lines[] = '5. Escribe los cambios en GLOBAL_CSS y MODULES_CSS (no solo CSS de una página) para que landing + catálogo + PDP + carrito se actualicen.';
        $lines[] = '6. Conserva selectores Multidrop (.md-*). No uses data-md-bind. No reescribas HTML comercial.';
        $lines[] = '7. Si hay estilo "beneficios"/"oferta"/"antes-despues"/"testimonios", úsalos como fondos o bloques visuales cerca del hero/PDP.';

        return implode("\n", $lines);
    }
}
