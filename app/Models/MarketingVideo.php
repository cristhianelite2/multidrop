<?php

namespace App\Models;

use App\Services\Storefront\DesignAssetUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingVideo extends Model
{
    protected $fillable = [
        'store_id',
        'campaign_id',
        'prompt_id',
        'source',
        'path',
        'original_name',
        'ad_headline',
        'ad_primary_text',
        'ad_cta',
        'duration',
        'page_handles',
        'stripped_at',
        'creatify_job_id',
    ];

    protected function casts(): array
    {
        return [
            'page_handles' => 'array',
            'stripped_at' => 'datetime',
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

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(MarketingPrompt::class, 'prompt_id');
    }

    public function publicUrl(): string
    {
        return DesignAssetUrl::fromPath((string) $this->path);
    }

    /**
     * @return list<string>
     */
    public function pageHandleList(): array
    {
        $raw = is_array($this->page_handles) ? $this->page_handles : [];

        return array_values(array_filter(array_map('strval', $raw)));
    }
}
