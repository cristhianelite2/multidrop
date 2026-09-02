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

            return max(9, min(60, (int) data_get($last, 'end', 15)));
        }

        return 15;
    }

    public function videos(): HasMany
    {
        return $this->hasMany(MarketingVideo::class, 'prompt_id');
    }
}
