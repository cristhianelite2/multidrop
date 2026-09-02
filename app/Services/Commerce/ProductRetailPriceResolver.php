<?php

namespace App\Services\Commerce;

use App\Domain\AI\ProductPriceSuggestionService;
use App\Models\Product;
use App\Services\Currency\CurrencyService;

/**
 * Si el precio guardado es el costo de compra (importación marketplace sin vitrina),
 * calcula precio de venta + compare para storefront y checkout.
 */
class ProductRetailPriceResolver
{
    public function __construct(
        protected CurrencyService $fx,
        protected ProductPriceSuggestionService $prices
    ) {}

    /**
     * @param  array{currency: string, price: float, compare_at_price: float|null, from?: string, converted?: bool, source?: string}  $quote
     * @return array{currency: string, price: float, compare_at_price: float|null, from?: string, converted?: bool, source?: string}
     */
    public function apply(Product $product, array $quote): array
    {
        if ((bool) data_get($product->creative_data, 'is_combo', false)) {
            return $quote;
        }

        $currency = strtoupper((string) ($quote['currency'] ?? $product->currency ?? $this->fx->base()));
        $price = (float) ($quote['price'] ?? 0);
        $compare = isset($quote['compare_at_price']) && $quote['compare_at_price'] !== null
            ? (float) $quote['compare_at_price']
            : null;
        $purchase = $this->purchaseInCurrency($product, $currency);

        if ($purchase <= 0) {
            return $quote;
        }

        $hasConfiguredSale = $compare !== null && $compare > $price && $price > ($purchase * 1.02);
        if ($hasConfiguredSale) {
            return $quote;
        }

        if ($price > ($purchase * 1.05)) {
            return $quote;
        }

        $retail = $this->computeRetail($product, $purchase, $currency);
        if ($retail <= $purchase) {
            return $quote;
        }

        $compareOut = $compare;
        if ($compareOut === null || $compareOut <= $retail) {
            $compareOut = $this->resolveCompare($product, $retail, $currency);
        }

        return array_merge($quote, [
            'price' => $retail,
            'compare_at_price' => ($compareOut !== null && $compareOut > $retail) ? $compareOut : null,
            'source' => ($quote['source'] ?? 'base').'+retail',
        ]);
    }

    protected function purchaseInCurrency(Product $product, string $currency): float
    {
        $currency = strtoupper($currency);
        $base = strtoupper((string) ($product->currency ?: $this->fx->base()));
        $purchase = (float) ($product->purchase_price ?? 0);

        if ($purchase > 0) {
            return $base === $currency
                ? $purchase
                : (float) $this->fx->convert($purchase, $base, $currency, false);
        }

        $market = $product->marketplacePurchasePrice();

        return $market !== null && $market > 0
            ? ($base === $currency
                ? (float) $market
                : (float) $this->fx->convert((float) $market, $base, $currency, false))
            : 0.0;
    }

    protected function computeRetail(Product $product, float $purchase, string $currency): float
    {
        $verified = is_array($product->verified_data) ? $product->verified_data : [];
        $pricing = is_array($verified['pricing'] ?? null) ? $verified['pricing'] : [];
        $sellUsd = (float) (data_get($pricing, 'sell_usd') ?? data_get($verified, 'sell_usd') ?? 0);

        if ($sellUsd > 0) {
            return (float) $this->fx->convert($sellUsd, 'USD', $currency, true);
        }

        $feesPct = (float) (data_get($pricing, 'fees_pct') ?? 0.045);
        $marginPct = (float) (data_get($pricing, 'target_margin_pct') ?? 0.42);
        $denom = max(0.15, 1 - $marginPct - $feesPct);
        $raw = $purchase / $denom;

        return $this->prices->attractivePrice($raw, $currency);
    }

    protected function resolveCompare(Product $product, float $retail, string $currency): ?float
    {
        $verified = is_array($product->verified_data) ? $product->verified_data : [];
        $verifiedCompare = data_get($verified, 'compare_at_price');

        if ($verifiedCompare !== null && (float) $verifiedCompare > $retail) {
            $srcCur = strtoupper((string) (data_get($verified, 'currency') ?: $product->currency ?: $currency));

            return (float) $this->fx->convert((float) $verifiedCompare, $srcCur, $currency, true);
        }

        return $this->prices->suggestCompare($retail, $currency);
    }
}
