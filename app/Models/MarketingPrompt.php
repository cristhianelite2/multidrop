<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingPrompt extends Model
{
    protected $fillable = [
        'store_id',
        'campaign_id',
        'product_id',
        'name',
        'hook',
        'script',
        'segments',
        'analysis',
        'audience',
        'language',
        'style',
        'target_platform',
    ];

    protected function casts(): array
    {
        return [
            'segments' => 'array',
            'analysis' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function videoLengthSeconds(): int
    {
        $fromAnalysis = (int) data_get($this->analysis, 'video_length_seconds', 0);
        if ($fromAnalysis >= 9 && $fromAnalysis <= 60) {
            return $fromAnalysis;
        }
        $segments = is_array($this->segments) ? $this->segments : [];
        if ($segments !== []) {
            $last = $segments[array_key_last($segments)];

            return max(9, min(45, (int) data_get($last, 'end', 15)));
        }

        return 15;
    }

    public function videos(): HasMany
    {
        return $this->hasMany(MarketingVideo::class, 'prompt_id');
    }

    /**
     * @return list<int>
     */
    public function linkedProductIds(): array
    {
        $ids = [];
        if ($this->product_id) {
            $ids[] = (int) $this->product_id;
        }

        $analysis = is_array($this->analysis) ? $this->analysis : [];
        $fromAnalysis = data_get($analysis, 'product_ids', []);
        if (is_array($fromAnalysis)) {
            foreach ($fromAnalysis as $id) {
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }
        }

        $single = data_get($analysis, 'product_id');
        if (is_numeric($single)) {
            $ids[] = (int) $single;
        }

        return array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));
    }

    public function hasLinkedProducts(): bool
    {
        if ($this->linkedProductIds() !== []) {
            return true;
        }

        if ($this->product_id) {
            return true;
        }

        return $this->relationLoaded('product')
            ? $this->product !== null
            : $this->product()->exists();
    }
}
