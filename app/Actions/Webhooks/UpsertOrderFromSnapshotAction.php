<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\DataObjects\OrderSnapshot;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Projects an order snapshot onto the stored read model.
 *
 * Two concerns are kept separate:
 *  - current state (the `orders` row, its items and shipment) is overwritten only
 *    by a snapshot at least as fresh as the one already stored;
 *  - status history accumulates from every snapshot, fresh or stale, because a
 *    transition that genuinely happened (e.g. a `shipped` event delivered after
 *    `delivered`) belongs in the history even when it must not roll back state.
 *
 * Freshness is a tuple: the marketplace `updated_at`, tie-broken by the event's
 * own `created_at`. The tie-break is persisted (`orders.last_event_at`) so a late
 * redelivery stays stale across worker restarts and never flips state.
 */
class UpsertOrderFromSnapshotAction
{
    public function __invoke(OrderSnapshot $snapshot, CarbonImmutable $eventOccurredAt): Order
    {
        $order = Order::query()
            ->where('marketplace_order_id', $snapshot->id)
            ->lockForUpdate()
            ->first();

        $isFresh = $order === null || $this->isFresher($snapshot, $eventOccurredAt, $order);

        if ($isFresh) {
            $order = $this->applyCurrentState($order, $snapshot, $eventOccurredAt);
        } else {
            Log::info('webhook.snapshot.stale', [
                'marketplace_order_id' => $snapshot->id,
                'reason' => 'stale',
                'snapshot_updated_at' => $snapshot->updatedAt->toIso8601String(),
                'stored_updated_at' => $order->marketplace_updated_at?->toIso8601String(),
            ]);
        }

        // History is derived from the snapshot's own timestamps and recorded
        // regardless of freshness, so out-of-order transitions are not dropped.
        $this->recordHistory($order, $snapshot);

        return $order->load(['items', 'shipment', 'statusHistory', 'notifications']);
    }

    /**
     * A snapshot is fresher than the stored order when its `updated_at` is newer,
     * or — on an exact `updated_at` tie — when its event was produced later. Full
     * equality (same updated_at and same event moment) is treated as not fresher,
     * so a late replay leaves state untouched.
     */
    private function isFresher(OrderSnapshot $snapshot, CarbonImmutable $eventOccurredAt, Order $order): bool
    {
        $storedUpdatedAt = $order->marketplace_updated_at;

        if (! $snapshot->updatedAt->equalTo($storedUpdatedAt)) {
            return $snapshot->updatedAt->greaterThan($storedUpdatedAt);
        }

        $storedEventAt = $order->last_event_at;

        if ($storedEventAt === null) {
            return false;
        }

        $tieBreak = $eventOccurredAt->greaterThan($storedEventAt);

        if ($tieBreak) {
            Log::info('webhook.snapshot.tie-break', [
                'marketplace_order_id' => $snapshot->id,
                'reason' => 'tie-break',
                'updated_at' => $snapshot->updatedAt->toIso8601String(),
            ]);
        }

        return $tieBreak;
    }

    private function applyCurrentState(?Order $order, OrderSnapshot $snapshot, CarbonImmutable $eventOccurredAt): Order
    {
        $wasNew = $order === null;
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
            'last_event_at' => $eventOccurredAt,
        ])->save();

        $this->syncItems($order, $snapshot);
        $this->syncShipment($order, $snapshot);

        Log::info('webhook.snapshot.applied', [
            'marketplace_order_id' => $snapshot->id,
            'reason' => 'fresh',
            'created' => $wasNew,
            'status' => $snapshot->status->value,
            'updated_at' => $snapshot->updatedAt->toIso8601String(),
        ]);

        return $order;
    }

    private function syncItems(Order $order, OrderSnapshot $snapshot): void
    {
        $order->items()->delete();
        $order->items()->createMany($snapshot->items);
    }

    /**
     * The snapshot is the full state of the order: a missing shipment means the
     * order no longer has one, so a stored shipment must be removed rather than
     * left behind.
     */
    private function syncShipment(Order $order, OrderSnapshot $snapshot): void
    {
        if ($snapshot->shipment === null) {
            if ($order->shipment()->exists()) {
                $order->shipment()->delete();

                Log::info('webhook.snapshot.shipment-removed', [
                    'marketplace_order_id' => $snapshot->id,
                ]);
            }

            return;
        }

        $order->shipment()->updateOrCreate([], $snapshot->shipment);
    }

    /**
     * Derives status history from the snapshot's own timestamps rather than from
     * the order of received events. `created`, `paid` and `cancelled` carry
     * explicit timestamps; `shipped`/`delivered` are dated from the snapshot that
     * reports that status. Uniqueness on (order, status, occurred_at) keeps each
     * distinct transition while a replay of the same event is a no-op.
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
            $entry = OrderStatusHistory::firstOrCreate(
                ['order_id' => $order->id, 'status' => $status, 'occurred_at' => $occurredAt],
            );

            if ($entry->wasRecentlyCreated) {
                Log::debug('webhook.history.recorded', [
                    'marketplace_order_id' => $snapshot->id,
                    'status' => $status,
                    'occurred_at' => $occurredAt->toIso8601String(),
                ]);
            }
        }
    }
}
