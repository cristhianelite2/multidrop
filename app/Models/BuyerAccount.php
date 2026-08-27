<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class BuyerAccount extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'email',
        'name',
        'phone',
        'password',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function claims(): HasMany
    {
        return $this->hasMany(OrderClaim::class);
    }

    public function hasPassword(): bool
    {
        return filled($this->password);
    }

    /**
     * Pedidos de todas las tiendas con este email.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Order>
     */
    public function ordersQuery()
    {
        return Order::query()
            ->whereRaw('LOWER(customer_email) = ?', [strtolower($this->email)])
            ->with(['store', 'items', 'fulfillments'])
            ->orderByDesc('id');
    }
}
