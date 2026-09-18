<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorePublication extends Model
{
    use HasFactory;

    protected $table = 'store_publications';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ERROR = 'error';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'store_id',
        'plan_id',
        'product_id',
        'network',
        'format',
        'content',
        'topic',
        'media_urls',
        'scheduled_at',
        'status',
        'sellercentral_post_id',
        'error_message',
        'day_offset',
        'slot_index',
    ];

    protected $casts = [
        'media_urls' => 'array',
        'scheduled_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(StorePublicationPlan::class, 'plan_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function networkLabel(): string
    {
        return [
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'twitter' => 'Twitter / X',
            'linkedin' => 'LinkedIn',
            'tiktok' => 'TikTok',
            'youtube' => 'YouTube',
            'gmail' => 'Gmail',
        ][$this->network] ?? ucfirst((string) $this->network);
    }
}