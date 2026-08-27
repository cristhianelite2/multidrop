<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'line_type',
        'name',
        'qty',
        'unit_price',
        'unit_cost',
        'total',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function imageUrl(): ?string
    {
        $img = data_get($this->meta, 'image');
        if (is_string($img) && $img !== '') {
            if (str_starts_with($img, '/media/')) {
                return asset(ltrim($img, '/'));
            }

            return $img;
        }
        $productImg = $this->product?->image_url;
        if (is_string($productImg) && $productImg !== '') {
            if (str_starts_with($productImg, '/media/')) {
                return asset(ltrim($productImg, '/'));
            }

            return $productImg;
        }

        return null;
    }

    /**
     * @return array{msrp: ?float, list_unit: float, paid_unit: float, qty: int}
     */
    public function pricingBreakdown(): array
    {
        $qty = max(1, (int) $this->qty);
        $paid = (float) $this->unit_price;
        $listUnit = $paid;
        $msrp = null;

        $metaMsrp = data_get($this->meta, 'msrp');
        $metaList = data_get($this->meta, 'list_unit');
        $metaCompare = data_get($this->meta, 'compare_at');

        if ($metaList !== null && (float) $metaList > 0) {
            $listUnit = (float) $metaList;
        }
        if ($metaMsrp !== null && (float) $metaMsrp > 0) {
            $msrp = (float) $metaMsrp;
        } elseif ($metaCompare !== null && (float) $metaCompare > $listUnit) {
            // Meta antigua: compare_at a veces mezclaba MSRP y lista
            $msrp = (float) $metaCompare;
        }

        if (($msrp === null || $listUnit <= $paid) && $this->product) {
            try {
                $currency = $this->order?->currency ?: 'USD';
                $quote = $this->product->quoteIn($currency);
                $quotePrice = (float) ($quote['price'] ?? 0);
                $quoteCompare = isset($quote['compare_at_price']) ? (float) $quote['compare_at_price'] : 0.0;
                if ($quotePrice > 0 && $listUnit <= $paid) {
                    $listUnit = max($listUnit, $quotePrice);
                }
                if ($quoteCompare > 0) {
                    $msrp = max($msrp ?? 0, $quoteCompare);
                }
            } catch (\Throwable) {
                //
            }
        }

        if ($msrp !== null && $msrp <= $listUnit) {
            $msrp = $msrp > $paid ? $msrp : null;
        }
        if ($listUnit < $paid) {
            $listUnit = $paid;
        }

        return [
            'msrp' => $msrp,
            'list_unit' => $listUnit,
            'paid_unit' => $paid,
            'qty' => $qty,
        ];
    }

    /** Precio tachado a mostrar (MSRP si existe, si no lista previa al descuento promo). */
    public function compareAtUnit(): ?float
    {
        $b = $this->pricingBreakdown();
        $show = $b['msrp'] ?? null;
        if ($show === null || $show <= $b['paid_unit']) {
            $show = $b['list_unit'] > $b['paid_unit'] ? $b['list_unit'] : null;
        }

        return $show;
    }

    public function compareLineTotal(): ?float
    {
        $unit = $this->compareAtUnit();
        if ($unit === null) {
            return null;
        }

        return round($unit * max(1, (int) $this->qty), 2);
    }

    /**
     * Ahorro por precio: MSRP / compare_at vs precio de venta (antes de descuentos promo).
     */
    public function priceSave(): float
    {
        $fromMeta = data_get($this->meta, 'price_save');
        if ($fromMeta !== null) {
            return max(0, (float) $fromMeta);
        }
        $b = $this->pricingBreakdown();
        if ($b['msrp'] === null || $b['msrp'] <= $b['list_unit']) {
            // Sin MSRP distinto: no hay “ahorro por precio”, solo posible descuento
            return 0.0;
        }

        return max(0, round(($b['msrp'] - $b['list_unit']) * $b['qty'], 2));
    }

    /**
     * Ahorro por descuento de línea (combo / promo sobre el precio de venta).
     */
    public function discountSave(): float
    {
        $fromMeta = data_get($this->meta, 'discount_save');
        if ($fromMeta !== null) {
            return max(0, (float) $fromMeta);
        }
        $b = $this->pricingBreakdown();

        return max(0, round(($b['list_unit'] - $b['paid_unit']) * $b['qty'], 2));
    }

    /** Total ahorrado en la línea (precio + descuento). */
    public function lineSave(): float
    {
        return round($this->priceSave() + $this->discountSave(), 2);
    }

    public function isComboLine(): bool
    {
        return $this->line_type === 'upsell' || ! empty(data_get($this->meta, 'upsell_combo'));
    }
}
