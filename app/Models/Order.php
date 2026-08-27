<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class Order extends Model
{
    protected $fillable = [
        'number',
        'access_token',
        'portal_pass_hash',
        'store_id',
        'customer_id',
        'customer_email',
        'customer_name',
        'customer_phone',
        'market_id',
        'status',
        'payment_status',
        'fulfillment_status',
        'payment_provider',
        'payment_ref',
        'currency',
        'subtotal',
        'discount',
        'shipping',
        'tax',
        'total',
        'coupon_code',
        'shipping_address',
        'meta',
        'admin_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'shipping' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'shipping_address' => 'array',
            'meta' => 'array',
            'admin_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            if (! $order->number) {
                $order->number = self::generateNumber();
            }
            if (! $order->access_token) {
                $order->access_token = Str::lower(Str::random(40));
            }
        });

        static::created(function (self $order) {
            if (! $order->portal_pass_hash && $order->number) {
                $order->forceFill([
                    'portal_pass_hash' => Hash::make(strtoupper($order->number)),
                ])->saveQuietly();
            }
        });
    }

    public static function generateNumber(): string
    {
        do {
            $number = 'MD-'.strtoupper(Str::random(8));
        } while (self::query()->where('number', $number)->exists());

        return $number;
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(Fulfillment::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(OrderClaim::class);
    }

    public function ensurePortalPassHash(): void
    {
        if ($this->portal_pass_hash || ! $this->number) {
            return;
        }
        $this->forceFill([
            'portal_pass_hash' => Hash::make(strtoupper($this->number)),
        ])->saveQuietly();
    }

    public function verifyPortalPass(string $orderNumber): bool
    {
        $this->ensurePortalPassHash();
        if (! $this->portal_pass_hash) {
            return false;
        }

        return Hash::check(strtoupper(trim($orderNumber)), $this->portal_pass_hash);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function paymentStatusLabel(): string
    {
        return match (strtolower((string) $this->payment_status)) {
            'paid', 'approved', 'completed' => 'Pagado',
            'pending'                        => 'Pendiente de pago',
            'failed', 'rejected'             => 'Pago fallido',
            'cancelled', 'canceled'          => 'Cancelado',
            'refunded'                       => 'Reembolsado',
            default                          => ucfirst((string) $this->payment_status),
        };
    }

    public function fulfillmentStatusLabel(): string
    {
        return match (strtolower((string) $this->fulfillment_status)) {
            'unfulfilled'              => 'En preparación',
            'submitted', 'processing'  => 'Procesando con proveedor',
            'manual'                   => 'En preparación manual',
            'skipped'                  => 'Sin envío requerido',
            'shipped', 'in_transit'    => 'En camino',
            'delivered', 'completed'   => 'Entregado',
            'error'                    => 'Error en envío',
            'cancelled', 'canceled'    => 'Cancelado',
            default                    => ucfirst(str_replace('_', ' ', (string) $this->fulfillment_status)),
        };
    }

    public function paymentProviderLabel(): string
    {
        return match (strtolower((string) $this->payment_provider)) {
            'paypal' => 'PayPal',
            'mercadopago' => 'Mercado Pago',
            'stripe' => 'Stripe',
            default => 'No definido',
        };
    }
}
