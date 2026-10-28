<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerNotification extends Model
{
    protected $table = 'customer_notifications';

    protected $fillable = [
        'order_id',
        'type',
        'claimed_at',
        'sent_at',
        'suppressed_at',
        'attempts',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
            'suppressed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }

    public function isSuppressed(): bool
    {
        return $this->suppressed_at !== null;
    }

    /** Resolved means no further delivery attempt is wanted: it was sent or suppressed. */
    public function isResolved(): bool
    {
        return $this->isSent() || $this->isSuppressed();
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
