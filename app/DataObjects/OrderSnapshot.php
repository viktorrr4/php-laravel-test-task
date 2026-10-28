<?php

declare(strict_types=1);

namespace App\DataObjects;

use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;

/**
 * Typed view over the `data.order` object of a webhook payload.
 *
 * A snapshot is the full, authoritative state of the order at the moment the
 * event was produced. The application never reconstructs state from the
 * sequence of events — it always applies the freshest snapshot it has seen.
 */
final class OrderSnapshot
{
    /**
     * @param  array<int, array{sku: string, name: string, quantity: int, price: string}>  $items
     * @param  array{carrier: string, tracking_number: string, tracking_url: string}|null  $shipment
     */
    public function __construct(
        public readonly string $id,
        public readonly string $number,
        public readonly OrderStatus $status,
        public readonly string $currency,
        public readonly string $customerEmail,
        public readonly string $customerName,
        public readonly ?string $customerPhone,
        public readonly array $items,
        public readonly string $deliveryPrice,
        public readonly string $discount,
        public readonly string $total,
        public readonly ?array $shipment,
        public readonly ?CarbonImmutable $paidAt,
        public readonly ?CarbonImmutable $cancelledAt,
        public readonly ?string $cancelReason,
        public readonly CarbonImmutable $createdAt,
        public readonly CarbonImmutable $updatedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $order
     */
    public static function fromArray(array $order): self
    {
        $customer = $order['customer'] ?? [];
        $shipment = $order['shipment'] ?? null;

        return new self(
            id: (string) $order['id'],
            number: (string) $order['number'],
            status: OrderStatus::from((string) $order['status']),
            currency: (string) $order['currency'],
            customerEmail: (string) ($customer['email'] ?? ''),
            customerName: (string) ($customer['name'] ?? ''),
            customerPhone: isset($customer['phone']) ? (string) $customer['phone'] : null,
            items: array_map(static fn (array $item): array => [
                'sku' => (string) $item['sku'],
                'name' => (string) $item['name'],
                'quantity' => (int) $item['quantity'],
                'price' => (string) $item['price'],
            ], $order['items'] ?? []),
            deliveryPrice: (string) $order['delivery_price'],
            discount: (string) $order['discount'],
            total: (string) $order['total'],
            shipment: is_array($shipment) ? [
                'carrier' => (string) $shipment['carrier'],
                'tracking_number' => (string) $shipment['tracking_number'],
                'tracking_url' => (string) $shipment['tracking_url'],
            ] : null,
            paidAt: self::parseDate($order['paid_at'] ?? null),
            cancelledAt: self::parseDate($order['cancelled_at'] ?? null),
            cancelReason: isset($order['cancel_reason']) ? (string) $order['cancel_reason'] : null,
            createdAt: self::parseDate($order['created_at']),
            updatedAt: self::parseDate($order['updated_at']),
        );
    }

    /**
     * MarketHub sends timestamps with an explicit offset (`+03:00`, `+00:00`).
     * We normalize every one to UTC so the stored moment and all freshness
     * comparisons are offset-independent: `13:05:47+03:00` and `10:05:47+00:00`
     * are the same instant and must compare and persist as such.
     */
    private static function parseDate(?string $value): ?CarbonImmutable
    {
        return $value === null || $value === ''
            ? null
            : CarbonImmutable::parse($value)->utc();
    }
}
