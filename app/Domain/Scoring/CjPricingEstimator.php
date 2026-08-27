<?php

namespace App\Domain\Scoring;

use App\Services\Currency\CurrencyService;

/**
 * Estimación rápida de precio de venta / costos / ganancia para resultados CJ (USD).
 */
class CjPricingEstimator
{
    public function __construct(
        protected CurrencyService $currency
    ) {}

    /**
     * Tipos de cambio: 1 unidad de moneda base → moneda (config global).
     *
     * @return array<string, float>
     */
    public function rates(): array
    {
        return $this->currency->rates();
    }

    /**
     * @return list<string>
     */
    public function currencies(): array
    {
        return $this->currency->currencies();
    }

    /**
     * @param  array<string, mixed>  $product  Ítem normalizado CJ
     * @return array{
     *   cost_usd: float|null,
     *   ship_usd: float|null,
     *   fees_usd: float|null,
     *   landed_usd: float|null,
     *   sell_usd: float|null,
     *   profit_usd: float|null,
     *   margin_pct: float|null,
     *   fees_pct: float,
     *   target_margin_pct: float
     * }
     */
    public function estimate(array $product, float $feesPercent = 0.045, float $targetMargin = 0.42): array
    {
        $cost = isset($product['price']) && $product['price'] !== null
            ? (float) $product['price']
            : null;

        if ($cost === null || $cost <= 0) {
            return [
                'cost_usd' => null,
                'ship_usd' => null,
                'fees_usd' => null,
                'landed_usd' => null,
                'sell_usd' => null,
                'profit_usd' => null,
                'margin_pct' => null,
                'fees_pct' => $feesPercent,
                'target_margin_pct' => $targetMargin,
            ];
        }

        $ship = $this->estimateShippingUsd($product);
        $denom = max(0.15, 1 - $targetMargin - $feesPercent);
        $usdMode = $this->currency->roundingFor('USD');
        // El envío se cobra aparte en checkout: el precio de vitrina no incluye flete.
        $sell = $this->currency->applyRounding(
            $cost / $denom,
            $usdMode === 'none' ? 'psych' : $usdMode
        );
        $fees = $sell * $feesPercent;
        $profit = $sell - $cost - $fees;
        $marginPct = $sell > 0 ? ($profit / $sell) * 100 : 0;

        return [
            'cost_usd' => round($cost, 2),
            'ship_usd' => round($ship, 2),
            'fees_usd' => round($fees, 2),
            'landed_usd' => round($cost, 2),
            'sell_usd' => round($sell, 2),
            'profit_usd' => round($profit, 2),
            'margin_pct' => round($marginPct, 1),
            'fees_pct' => $feesPercent,
            'target_margin_pct' => $targetMargin,
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     */
    public function estimateShippingUsd(array $product): float
    {
        if (! empty($product['free_shipping'])) {
            return 0.0;
        }

        $weightG = (float) ($product['weight'] ?? 0);
        if ($weightG <= 0) {
            $weightG = 250;
        }

        $ship = 1.8 + ($weightG / 1000) * 9.5;
        $ship = max(1.5, min(28.0, $ship));

        return round($ship, 2);
    }

    public function convert(float $usd, string $currency): float
    {
        return $this->currency->convert($usd, 'USD', $currency, true);
    }
}
