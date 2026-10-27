<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle statuses of a MarketHub order.
 *
 * Normal path: created -> paid -> shipped -> delivered.
 * An order may be cancelled before it is shipped (whether paid or not).
 */
enum OrderStatus: string
{
    case Created = 'created';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * Position on the normal fulfilment path. Used to order the derived status
     * history; `cancelled` is a terminal branch and is placed by its timestamp.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Created => 0,
            self::Paid => 1,
            self::Shipped => 2,
            self::Delivered => 3,
            self::Cancelled => 4,
        };
    }

    public function isCancelled(): bool
    {
        return $this === self::Cancelled;
    }

    /** Statuses that imply the order has been paid at some point. */
    public function impliesPaid(): bool
    {
        return in_array($this, [self::Paid, self::Shipped, self::Delivered], true);
    }
}
