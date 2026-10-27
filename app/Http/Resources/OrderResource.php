<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full order representation per the `Order` schema in task/openapi.yaml.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->marketplace_order_id,
            'number' => $this->number,
            'status' => $this->status->value,
            'customer' => [
                'email' => $this->customer_email,
                'name' => $this->customer_name,
                'phone' => $this->customer_phone,
            ],
            'currency' => $this->currency,
            'items' => $this->items->map(fn (OrderItem $item): array => [
                'sku' => $item->sku,
                'name' => $item->name,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'subtotal' => $item->subtotal(),
            ])->all(),
            'delivery_price' => $this->delivery_price,
            'discount' => $this->discount,
            'total' => $this->total,
            'shipment' => $this->shipment === null ? null : [
                'carrier' => $this->shipment->carrier,
                'tracking_number' => $this->shipment->tracking_number,
                'tracking_url' => $this->shipment->tracking_url,
            ],
            'paid_at' => $this->paid_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->marketplace_created_at->toIso8601String(),
            'updated_at' => $this->marketplace_updated_at->toIso8601String(),
            'history' => $this->statusHistory
                ->sortBy(fn (OrderStatusHistory $entry): array => [
                    $entry->occurred_at->getTimestamp(),
                    $entry->status->rank(),
                ])
                ->values()
                ->map(fn (OrderStatusHistory $entry): array => [
                    'status' => $entry->status->value,
                    'at' => $entry->occurred_at->toIso8601String(),
                ])
                ->all(),
        ];
    }
}
