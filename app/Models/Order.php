<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = [
        'marketplace_order_id',
        'number',
        'status',
        'currency',
        'customer_email',
        'customer_name',
        'customer_phone',
        'delivery_price',
        'discount',
        'total',
        'paid_at',
        'cancelled_at',
        'cancel_reason',
        'marketplace_created_at',
        'marketplace_updated_at',
        'last_event_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'delivery_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'marketplace_created_at' => 'datetime',
            'marketplace_updated_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    /**
     * Expose the order through its MarketHub id in route/{order} bindings,
     * matching the `id` path parameter in the API contract.
     */
    public function getRouteKeyName(): string
    {
        return 'marketplace_order_id';
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasOne<OrderShipment, $this> */
    public function shipment(): HasOne
    {
        return $this->hasOne(OrderShipment::class);
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /** @return HasMany<CustomerNotification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(CustomerNotification::class);
    }
}
