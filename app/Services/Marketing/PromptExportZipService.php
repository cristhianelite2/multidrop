<?php

namespace App\Services\Marketing;

use App\Models\MarketingPrompt;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ZipArchive;

class PromptExportZipService
{
    public function __construct(
        protected ProductMarketingMediaService $media,
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

        $manifest = [];
        $zip->addFromString('prompt.txt', $this->buildPromptText($store, $prompt, $products));

        $multiProduct = $products->count() > 1;
        foreach ($products as $product) {
            $prefix = $multiProduct ? 'product-'.$product->id.'/' : '';

            foreach ($this->media->exportImageUrls($product) as $i => $rawUrl) {
                $file = $this->media->fetchMediaBytes($store, $product, $rawUrl, 'image', $i + 1);
                if ($file) {
                    $entry = $prefix.'images/'.$this->zipEntryName($i + 1, $file['filename']);
                    $zip->addFromString($entry, $file['body']);
                    $manifest[] = $entry;
                } else {
                    $manifest[] = '# MISSING image: '.$rawUrl;
                }
            }

            foreach ($this->media->exportVideoEntries($product) as $i => $video) {
                $file = $this->media->fetchMediaBytes($store, $product, $video['url'], 'video', $i + 1);
                if ($file) {
                    $baseName = $this->videoZipFilename($video['name'], $file['filename'], $i + 1);
                    $entry = $prefix.'videos/'.$this->zipEntryName($i + 1, $baseName);
                    $zip->addFromString($entry, $file['body']);
                    $manifest[] = $entry;
                } else {
                    $manifest[] = '# MISSING video: '.$video['url'];
                }
            }
        }

        $zip->addFromString('manifest.txt', implode("\n", $manifest)."\n");
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

    protected function zipEntryName(int $index, string $filename): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $filename) ?: ('file-'.$index);
        $safe = trim($safe, '-.');
        if ($safe === '') {
            $safe = 'file-'.$index;
        }

        return sprintf('%03d-%s', $index, $safe);
    }

    protected function videoZipFilename(string $preferredName, string $fetchedName, int $index): string
    {
        $preferred = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $preferredName) ?: '';
        if ($preferred !== '' && str_contains($preferred, '.')) {
            return $preferred;
        }

        if (str_contains($fetchedName, '.')) {
            return $fetchedName;
        }

        return ($preferred !== '' ? $preferred : 'video-'.$index).'.mp4';
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
            ->with('variants')
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
                $lines[] = 'Imágenes en catálogo: '.count($this->media->exportImageUrls($product));
                $lines[] = 'Videos en catálogo: '.count($this->media->exportVideoEntries($product));
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
