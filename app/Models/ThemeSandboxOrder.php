<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThemeSandboxOrder extends Model
{
    protected $fillable = [
        'theme_id',
        'number',
        'email',
        'name',
        'phone',
        'address',
        'items',
        'subtotal',
        'discount',
        'total',
        'currency',
        'coupon',
        'payment_status',
        'fulfillment_status',
        'cj_order_id',
        'tracking_number',
        'carrier',
        'cj_payload',
        'cj_response',
        'cj_order_detail',
        'cj_tracking',
        'cj_error',
    ];

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'items' => 'array',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'cj_payload' => 'array',
            'cj_response' => 'array',
            'cj_order_detail' => 'array',
            'cj_tracking' => 'array',
        ];
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    public function cjOk(): bool
    {
        return ($this->fulfillment_status === 'submitted' || $this->fulfillment_status === 'shipped')
            && (string) $this->cj_order_id !== '';
    }

    /**
     * Payload público (sin dumps crudos de CJ).
     *
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'email' => $this->email,
            'name' => $this->name,
            'phone' => $this->phone,
            'address' => $this->address,
            'items' => $this->items ?? [],
            'subtotal' => (float) $this->subtotal,
            'discount' => (float) $this->discount,
            'total' => (float) $this->total,
            'currency' => $this->currency,
            'coupon' => $this->coupon,
            'payment_status' => $this->payment_status,
            'fulfillment_status' => $this->fulfillment_status,
            'cj_order_id' => $this->cj_order_id,
            'cj_status' => $this->fulfillment_status,
            'cj_error' => $this->cj_error,
            'tracking_number' => $this->tracking_number,
            'carrier' => $this->carrier,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
