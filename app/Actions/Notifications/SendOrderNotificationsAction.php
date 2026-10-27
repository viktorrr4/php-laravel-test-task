<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Enums\OrderStatus;
use App\Mail\OrderCancelledMail;
use App\Mail\OrderPaidMail;
use App\Mail\OrderShippedMail;
use App\Models\CustomerNotification;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Decides which customer emails the current order state warrants and sends the
 * ones not yet sent.
 *
 * Two business rules drive this:
 *  - no duplicates: a unique (order, type) row is the source of truth, so each
 *    email type is sent at most once per order, whatever the event stream does;
 *  - no contradictions: the decision is made from the order's current stored
 *    state, not the triggering event. A cancelled order only ever yields the
 *    cancellation email, even if paid/shipped events arrive out of order.
 */
class SendOrderNotificationsAction
{
    public const TYPE_PAID = 'paid';

    public const TYPE_SHIPPED = 'shipped';

    public const TYPE_CANCELLED = 'cancelled';

    public function __invoke(Order $order): void
    {
        foreach ($this->desiredTypes($order) as $type) {
            $notification = CustomerNotification::firstOrCreate(
                ['order_id' => $order->id, 'type' => $type],
                ['sent_at' => CarbonImmutable::now()],
            );

            if ($notification->wasRecentlyCreated) {
                Mail::to($order->customer_email)->send($this->mailFor($type, $order));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function desiredTypes(Order $order): array
    {
        // A cancelled order must never emit paid/shipped mails — that would
        // contradict its current state. The cancellation mail itself notes the
        // refund when the order had been paid.
        if ($order->status === OrderStatus::Cancelled) {
            return [self::TYPE_CANCELLED];
        }

        $types = [];

        if ($order->paid_at !== null) {
            $types[] = self::TYPE_PAID;
        }

        if ($order->shipment !== null) {
            $types[] = self::TYPE_SHIPPED;
        }

        return $types;
    }

    private function mailFor(string $type, Order $order): Mailable
    {
        return match ($type) {
            self::TYPE_PAID => new OrderPaidMail($order),
            self::TYPE_SHIPPED => new OrderShippedMail($order),
            self::TYPE_CANCELLED => new OrderCancelledMail($order),
        };
    }
}
