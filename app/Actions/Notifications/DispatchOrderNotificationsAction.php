<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Jobs\SendCustomerNotificationJob;
use App\Models\CustomerNotification;
use App\Models\Order;

/**
 * Decides which customer emails the current order state warrants and queues the
 * ones not already decided on.
 *
 * Two business rules drive this:
 *  - no duplicates: a unique (order, type) row is the source of truth, so each
 *    email type is queued at most once per order, whatever the event stream does;
 *  - no contradictions: the decision is made from the order's current stored
 *    state, not the triggering event. A cancelled order only ever yields the
 *    cancellation email, even if paid/shipped events arrive out of order.
 *
 * Sending itself happens in SendCustomerNotificationJob on the `mail` queue, so
 * a slow SMTP server never holds the processing transaction open.
 */
class DispatchOrderNotificationsAction
{
    public function __invoke(Order $order): void
    {
        foreach ($this->desiredTypes($order) as $type) {
            $notification = CustomerNotification::firstOrCreate(
                ['order_id' => $order->id, 'type' => $type->value],
            );

            // Only the event that first created the row queues the email;
            // dispatch after commit so a rolled-back row never sends.
            if ($notification->wasRecentlyCreated) {
                SendCustomerNotificationJob::dispatch($notification->id)->afterCommit();
            }
        }
    }

    /**
     * @return list<NotificationType>
     */
    private function desiredTypes(Order $order): array
    {
        // A cancelled order must never emit paid/shipped mails — that would
        // contradict its current state. The cancellation mail itself notes the
        // refund when the order had been paid.
        if ($order->status === OrderStatus::Cancelled) {
            return [NotificationType::Cancelled];
        }

        $types = [];

        if ($order->paid_at !== null) {
            $types[] = NotificationType::Paid;
        }

        if ($order->shipment !== null) {
            $types[] = NotificationType::Shipped;
        }

        return $types;
    }
}
