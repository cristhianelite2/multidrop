<?php

namespace App\Models;

use App\Services\Storefront\DesignAssetUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignProductMedia extends Model
{
    protected $table = 'campaign_product_media';

    protected $fillable = [
        'store_id',
        'marketing_campaign_id',
        'product_id',
        'kind',
        'path',
        'original_name',
        'mime',
        'size',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'marketing_campaign_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function url(): string
    {
        return DesignAssetUrl::fromPath((string) $this->path);
    }
}