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

    /**
     * The email types the order's current stored state warrants — the single
     * source of truth for both queuing (which emails to create) and sending
     * (whether a queued email is still valid). A cancelled order yields only the
     * cancellation mail, so a paid/shipped mail queued before the cancellation is
     * no longer desired and must be suppressed rather than sent.
     *
     * @return list<self>
     */
    public static function desiredFor(Order $order): array
    {
        if ($order->status === OrderStatus::Cancelled) {
            return [self::Cancelled];
        }

        $types = [];

        if ($order->paid_at !== null) {
            $types[] = self::Paid;
        }

        if ($order->shipment !== null) {
            $types[] = self::Shipped;
        }

        return $types;
    }

    /**
     * Whether an email of this type still matches the order's current state.
     */
    public function isValidFor(Order $order): bool
    {
        return in_array($this, self::desiredFor($order), true);
    }
}
