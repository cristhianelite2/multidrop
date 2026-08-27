<?php

namespace App\Domain\Scoring;

class ProductScoreService
{
    /**
     * Score 0–100. Factores normalizados; pesos iniciales del plan.
     */
    public function score(array $factors): array
    {
        $weights = [
            'demand_growth' => 0.15,
            'trend_velocity' => 0.10,
            'social_proof' => 0.08,
            'competition_inverse' => 0.07,
            'margin' => 0.12,
            'shipping_feasibility' => 0.10,
            'stock_local' => 0.08,
            'demo_video_fit' => 0.08,
            'problem_fit' => 0.07,
            'seasonality_fit' => 0.05,
            'return_risk_inverse' => 0.05,
            'regulatory_risk_inverse' => 0.05,
        ];

        $total = 0.0;
        $breakdown = [];

        foreach ($weights as $key => $weight) {
            $value = $this->clamp((float) ($factors[$key] ?? 50));
            $contribution = $value * $weight;
            $breakdown[$key] = round($contribution, 2);
            $total += $contribution;
        }

        $score = (int) round($total);
        $band = match (true) {
            $score >= 70 => 'test',
            $score >= 55 => 'watchlist',
            default => 'reject',
        };

        return [
            'score' => $score,
            'band' => $band,
            'breakdown' => $breakdown,
        ];
    }

    public function marginScore(float $sellPrice, float $productCost, float $shippingCost, float $feesPercent = 0.04): float
    {
        if ($sellPrice <= 0) {
            return 0;
        }

        $fees = $sellPrice * $feesPercent;
        $contribution = $sellPrice - $productCost - $shippingCost - $fees;
        $marginPct = ($contribution / $sellPrice) * 100;

        // 20% margen ≈ 50 pts; 50%+ ≈ 100
        return $this->clamp(($marginPct / 50) * 100);
    }

    protected function clamp(float $value): float
    {
        return max(0, min(100, $value));
    }
}
