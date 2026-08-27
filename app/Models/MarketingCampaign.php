<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaign extends Model
{
    protected $fillable = [
        'store_id',
        'name',
        'status',
        'platforms',
        'daily_budget',
        'currency',
        'landing_handle',
        'landing_url',
        'notes',
        'creatify_link_id',
        'meta_draft_id',
        'tiktok_draft_id',
        'draft_payload',
        'insights',
        'targets',
        'advice',
        'advice_at',
    ];

    protected function casts(): array
    {
        return [
            'platforms' => 'array',
            'daily_budget' => 'decimal:2',
            'draft_payload' => 'array',
            'insights' => 'array',
            'targets' => 'array',
            'advice' => 'array',
            'advice_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function prompts(): HasMany
    {
        return $this->hasMany(MarketingPrompt::class, 'campaign_id');
    }

    public function videos(): HasMany
    {
        return $this->hasMany(MarketingVideo::class, 'campaign_id');
    }

    /**
     * @return list<string>
     */
    public function platformList(): array
    {
        $raw = is_array($this->platforms) ? $this->platforms : [];
        $out = [];
        foreach ($raw as $p) {
            $p = strtolower(trim((string) $p));
            if (in_array($p, ['meta', 'tiktok'], true)) {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }
}
