<?php

declare(strict_types=1);

namespace App\Enums;

use App\Mail\OrderCancelledMail;
use App\Mail\OrderPaidMail;
use App\Mail\OrderShippedMail;
use App\Models\Order;
use Illuminate\Mail\Mailable;

/**
 * Customer-facing email types. Each maps to exactly one Mailable, so the
 * dispatching action and the sending job agree on what to build.
 */
enum NotificationType: string
{
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';

    public function mailable(Order $order): Mailable
    {
        return match ($this) {
            self::Paid => new OrderPaidMail($order),
            self::Shipped => new OrderShippedMail($order),
            self::Cancelled => new OrderCancelledMail($order),
        };
    }
}
