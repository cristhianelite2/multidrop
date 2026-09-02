<?php

namespace App\Services\Marketing;

use App\Models\MarketingPrompt;
use App\Models\Product;
use App\Models\Store;
use App\Services\Catalog\ProductMediaDownloadService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ZipArchive;

class PromptExportZipService
{
    public function __construct(
        protected ProductMarketingMediaService $media,
        protected ProductMediaDownloadService $downloads,
    ) {}

    public function buildZip(Store $store, MarketingPrompt $prompt): ?string
    {
        if (! class_exists(ZipArchive::class)) {
            return null;
        }

        $products = $this->resolveProducts($store, $prompt);
        if ($products->isEmpty()) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mdpromptzip_');
        if ($tmp === false) {
            return null;
        }
        @unlink($tmp);
        $zipPath = $tmp.'.zip';
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        $zip->addFromString('prompt.txt', $this->buildPromptText($store, $prompt, $products));

        $multiProduct = $products->count() > 1;
        foreach ($products as $product) {
            $prefix = $multiProduct ? 'product-'.$product->id.'/' : '';

            foreach ($this->media->publicImageUrls($product, 20, $store) as $i => $url) {
                $file = $this->downloads->fetchBytes($url, 'image', $i + 1);
                if ($file) {
                    $zip->addFromString($prefix.'images/'.$file['filename'], $file['body']);
                }
            }

            $videoRows = $this->media->publicVideoUrls($product, 10, $store);
            foreach ($videoRows as $i => $url) {
                $file = $this->downloads->fetchBytes($url, 'video', $i + 1);
                if ($file) {
                    $zip->addFromString($prefix.'videos/'.$file['filename'], $file['body']);
                }
            }
        }

        $zip->close();

        return $zipPath;
    }

    public function downloadFilename(MarketingPrompt $prompt): string
    {
        $slug = Str::slug($prompt->name ?: 'prompt');
        if ($slug === '') {
            $slug = 'prompt';
        }

        return 'prompt-'.$prompt->id.'-'.Str::limit($slug, 40, '').'.zip';
    }

    /**
     * @return Collection<int, Product>
     */
    protected function resolveProducts(Store $store, MarketingPrompt $prompt): Collection
    {
        $ids = $prompt->linkedProductIds();
        if ($ids === []) {
            return collect();
        }

        return Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    protected function buildPromptText(Store $store, MarketingPrompt $prompt, Collection $products): string
    {
        $lines = [];
        $lines[] = 'PROMPT DE VIDEO — '.($prompt->name ?: 'Sin nombre');
        $lines[] = 'ID: '.$prompt->id;
        if ($prompt->campaign_id) {
            $lines[] = 'Campaña ID: '.$prompt->campaign_id;
        }
        $lines[] = 'Plataforma: '.$prompt->target_platform;
        $lines[] = 'Idioma: '.$prompt->language;
        if ($prompt->style) {
            $lines[] = 'Estilo: '.$prompt->style;
        }
        if ($prompt->audience) {
            $lines[] = 'Audiencia: '.$prompt->audience;
        }
        if ($prompt->hook) {
            $lines[] = 'Hook: '.$prompt->hook;
        }
        $lines[] = 'Duración objetivo: '.$prompt->videoLengthSeconds().' s';
        $lines[] = '';

        if ($products->isNotEmpty()) {
            $lines[] = '=== PRODUCTOS ===';
            foreach ($products as $product) {
                $lines[] = '#'.$product->id.' · '.$product->localizedName();
                $lines[] = 'URL: '.$this->media->productPageUrl($store, $product);
            }
            $lines[] = '';
        }

        $analysis = is_array($prompt->analysis) ? $prompt->analysis : [];
        if ($analysis !== []) {
            $lines[] = '=== ANÁLISIS ===';
            foreach ([
                'summary' => 'Resumen',
                'product_angle' => 'Ángulo de venta',
                'recommended_format' => 'Formato recomendado',
                'casting_notes' => 'Casting',
                'camera_notes' => 'Cámara',
            ] as $key => $label) {
                $value = trim((string) ($analysis[$key] ?? ''));
                if ($value !== '') {
                    $lines[] = $label.': '.$value;
                }
            }
            $lines[] = '';
        }

        $script = trim((string) $prompt->script);
        if ($script !== '') {
            $lines[] = '=== GUION / PROMPT COMPLETO ===';
            $lines[] = '';
            $lines[] = $script;
            $lines[] = '';
        }

        $segments = is_array($prompt->segments) ? $prompt->segments : [];
        if ($segments !== []) {
            $lines[] = '=== SEGMENTOS (JSON) ===';
            $lines[] = json_encode($segments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        }

        return implode("\n", $lines);
    }
}
