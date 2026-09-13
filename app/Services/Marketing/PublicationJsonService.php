<?php

namespace App\Services\Marketing;

use App\Models\MarketingVideo;
use App\Models\Product;
use App\Models\Store;

class PublicationJsonService
{
    protected const MAX_LENGTHS = [
        'facebook' => 63206,
        'instagram' => 2200,
        'tiktok' => 2200,
        'twitter' => 280,
        'youtube' => 5000,
    ];

    /**
     * @return array{
     *     formato: string,
     *     redes: list<string>,
     *     contenidos: array<string, string>,
     *     fecha_programada: string,
     *     estado: string,
     *     tema: string,
     *     media_urls: list<string>
     * }
     */
    public function forVideo(Store $store, MarketingVideo $video): array
    {
        $video->loadMissing('product');
        $product = $video->product;

        $name = $product
            ? $product->localizedName()
            : trim((string) ($video->ad_headline ?: $video->original_name ?: 'Vídeo'));
        $description = $product ? $this->snippet((string) $product->localizedDescription()) : '';
        $price = $product ? $this->priceLine($product, $store) : '';
        $hashtags = $this->hashtags($name);

        $contenidos = [];
        $contenidos['instagram'] = $this->withRules('instagram', $this->instagram($name, $description, $price, $hashtags));
        $contenidos['tiktok'] = $this->withRules('tiktok', $this->tiktok($name, $description, $price, $hashtags));
        $contenidos['facebook'] = $this->withRules('facebook', $this->facebook($name, $description, $price));
        $contenidos['twitter'] = $this->withRules('twitter', $this->twitter($name, $description, $price, $hashtags));
        $contenidos['youtube'] = $this->withRules('youtube', $this->youtube($name, $description, $price, $hashtags));

        return [
            'formato' => 'video',
            'redes' => array_keys($contenidos),
            'contenidos' => $contenidos,
            'fecha_programada' => now()->addHours(2)->format('Y-m-d\TH:i'),
            'estado' => 'scheduled',
            'tema' => mb_substr($name, 0, 120),
            'media_urls' => [$video->publicUrl()],
        ];
    }

    protected function facebook(string $name, string $description, string $price): string
    {
        $lines = ['¡'.$name.'!', '', $description, '', 'Precio: '.$price, '¡Consigue el tuyo hoy! 🛍️'];

        return trim(implode("\n", $lines));
    }

    protected function instagram(string $name, string $description, string $price, string $hashtags): string
    {
        $lines = ['✨ '.$name, '', $description, '', '💲 '.$price, '', $hashtags];

        return trim(implode("\n", $lines));
    }

    protected function tiktok(string $name, string $description, string $price, string $hashtags): string
    {
        $lines = ['⭐ '.$name, '', $description, '', '💲 '.$price, '', '¡Mira el video completo! 👀', '', $hashtags];

        return trim(implode("\n", $lines));
    }

    protected function youtube(string $name, string $description, string $price, string $hashtags): string
    {
        $lines = [$name, '', $description, '', '💲 '.$price, '', 'Más información, fotos y compra en el enlace de la descripción.', '', $hashtags];

        return trim(implode("\n", $lines));
    }

    protected function twitter(string $name, string $description, string $price, string $hashtags): string
    {
        $parts = [$name];
        if ($description !== '') {
            $parts[] = $description;
        }
        if ($price !== '') {
            $parts[] = $price;
        }
        $parts[] = $hashtags;

        return trim(implode(' · ', $parts));
    }

    protected function withRules(string $network, string $text): string
    {
        $max = self::MAX_LENGTHS[$network] ?? 280;
        if (mb_strlen($text) <= $max) {
            return trim($text);
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim(($space !== false && $space > 0 ? mb_substr($cut, 0, $space) : $cut)).'…';
    }

    protected function snippet(string $text): string
    {
        $flat = preg_replace('/[\r\n\t]+/', ' ', $text) ?: $text;
        $flat = trim(strip_tags($flat));
        if ($flat === '') {
            return $flat;
        }
        $flat = preg_replace('/\s+/u', ' ', $flat) ?: $flat;

        return mb_substr($flat, 0, 500);
    }

    protected function priceLine(Product $product, Store $store): string
    {
        try {
            return $product->formattedPriceIn($store->currency());
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function hashtags(string $name): string
    {
        $words = preg_split('/[\s\p{P}\p{S}]+/u', $name) ?: [];
        $word = '';
        foreach ($words as $w) {
            $w = trim((string) $w);
            if (mb_strlen($w) >= 3 && ! empty(preg_replace('/[^\p{L}\p{N}]/u', '', $w))) {
                $word = $w;
                break;
            }
        }
        if ($word === '') {
            $word = 'nuevo';
        }
        $tag = '#'.ucfirst(mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $word) ?: 'nuevo'));

        return $tag.' #Nuevo #Oferta';
    }
}