<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\DataObjects\OrderSnapshot;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;

/**
 * Projects an order snapshot onto the stored read model.
 *
 * Out-of-order safe: an order row is only overwritten by a snapshot at least as
 * fresh as the one already stored (compared by the marketplace `updated_at`).
 * Items are a full replacement because each snapshot carries the complete order.
 */
class UpsertOrderFromSnapshotAction
{
    public function __invoke(OrderSnapshot $snapshot): Order
    {
        $order = Order::query()
            ->where('marketplace_order_id', $snapshot->id)
            ->lockForUpdate()
            ->first();

        // Stale snapshot: a newer state is already stored. Leave it untouched;
        // notifications are still re-evaluated against that newer state later.
        if ($order !== null && $order->marketplace_updated_at->gt($snapshot->updatedAt)) {
            return $order->load(['items', 'shipment', 'statusHistory', 'notifications']);
        }

        $order ??= new Order(['marketplace_order_id' => $snapshot->id]);

        $order->fill([
            'number' => $snapshot->number,
            'status' => $snapshot->status,
            'currency' => $snapshot->currency,
            'customer_email' => $snapshot->customerEmail,
            'customer_name' => $snapshot->customerName,
            'customer_phone' => $snapshot->customerPhone,
            'delivery_price' => $snapshot->deliveryPrice,
            'discount' => $snapshot->discount,
            'total' => $snapshot->total,
            'paid_at' => $snapshot->paidAt,
            'cancelled_at' => $snapshot->cancelledAt,
            'cancel_reason' => $snapshot->cancelReason,
            'marketplace_created_at' => $snapshot->createdAt,
            'marketplace_updated_at' => $snapshot->updatedAt,
        ])->save();

        $this->syncItems($order, $snapshot);
        $this->syncShipment($order, $snapshot);
        $this->recordHistory($order, $snapshot);

        return $order->load(['items', 'shipment', 'statusHistory', 'notifications']);
    }

    private function syncItems(Order $order, OrderSnapshot $snapshot): void
    {
        $order->items()->delete();
        $order->items()->createMany($snapshot->items);
    }

    private function syncShipment(Order $order, OrderSnapshot $snapshot): void
    {
        if ($snapshot->shipment === null) {
            return;
        }

        $order->shipment()->updateOrCreate([], $snapshot->shipment);
    }

    /**
     * Derives status history from the snapshot's own timestamps rather than from
     * the order of received events. `created`, `paid` and `cancelled` carry
     * explicit timestamps; `shipped`/`delivered` are dated from the snapshot that
     * reports that status. Unique (order, status) keeps each entry stable.
     */
    private function recordHistory(Order $order, OrderSnapshot $snapshot): void
    {
        $entries = [OrderStatus::Created->value => $snapshot->createdAt];

        if ($snapshot->paidAt !== null) {
            $entries[OrderStatus::Paid->value] = $snapshot->paidAt;
        }

        if ($snapshot->cancelledAt !== null) {
            $entries[OrderStatus::Cancelled->value] = $snapshot->cancelledAt;
        }

        if ($snapshot->status === OrderStatus::Shipped) {
            $entries[OrderStatus::Shipped->value] = $snapshot->updatedAt;
        }

        if ($snapshot->status === OrderStatus::Delivered) {
            $entries[OrderStatus::Delivered->value] = $snapshot->updatedAt;
        }

        foreach ($entries as $status => $occurredAt) {
            OrderStatusHistory::firstOrCreate(
                ['order_id' => $order->id, 'status' => $status],
                ['occurred_at' => $occurredAt],
            );
        }
    }
}
