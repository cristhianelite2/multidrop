<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorePublicationPlan extends Model
{
    use HasFactory;

    protected $table = 'store_publication_plans';

    protected $fillable = [
        'store_id',
        'batch_uid',
        'days',
        'per_day',
        'start_date',
        'channels',
        'product_ids',
        'format',
        'status',
        'generated_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'channels' => 'array',
        'product_ids' => 'array',
        'generated_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return HasMany<StorePublication, $this>
     */
    public function publications(): HasMany
    {
        return $this->hasMany(StorePublication::class, 'plan_id');
    }

    public function pendingCount(): int
    {
        return $this->publications()
            ->whereIn('status', ['draft', 'approved', 'error'])
            ->count();
    }
}