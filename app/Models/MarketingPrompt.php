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
        'name',
        'hook',
        'script',
        'audience',
        'language',
        'style',
        'target_platform',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function videos(): HasMany
    {
        return $this->hasMany(MarketingVideo::class, 'prompt_id');
    }
}
