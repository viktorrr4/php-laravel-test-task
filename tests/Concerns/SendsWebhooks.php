<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * Helpers for posting MarketHub-style webhooks to the application, signing the
 * exact bytes the way the marketplace (and task/examples/send-event.php) does.
 */
trait SendsWebhooks
{
    protected function webhookSecret(): string
    {
        return (string) config('services.marketplace.webhook_secret');
    }

    /**
     * Build a well-formed event envelope around an order snapshot.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    protected function event(string $id, string $type, array $order, ?string $createdAt = null): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'created_at' => $createdAt ?? '2026-10-21T10:05:48+00:00',
            'data' => ['order' => $order],
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, string>  $headerOverrides
     */
    protected function postWebhook(array $event, array $headerOverrides = [], ?string $secret = null): TestResponse
    {
        $body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = $headerOverrides['X-Webhook-Timestamp'] ?? (string) time();
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? $this->webhookSecret());

        $headers = array_merge([
            'Content-Type' => 'application/json',
            'X-Webhook-Id' => $event['id'],
            'X-Webhook-Timestamp' => $timestamp,
            'X-Webhook-Signature' => $signature,
        ], $headerOverrides);

        // Send raw bytes so the server signs exactly what we signed.
        return $this->call('POST', '/api/webhooks/marketplace', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    /**
     * A complete order snapshot with sensible defaults, overridable per field.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function orderSnapshot(array $overrides = []): array
    {
        return array_merge([
            'id' => 'ord_7Q2KX9M4',
            'number' => 'MH-1048213',
            'status' => 'created',
            'currency' => 'RUB',
            'customer' => [
                'email' => 'anna.smirnova@example.com',
                'name' => 'Анна Смирнова',
                'phone' => '+79161234567',
            ],
            'items' => [
                ['sku' => 'TSHIRT-BLK-M', 'name' => 'Футболка базовая, чёрная, M', 'quantity' => 3, 'price' => '1290.00'],
                ['sku' => 'CAP-NVY', 'name' => 'Бейсболка Classic', 'quantity' => 1, 'price' => '890.50'],
            ],
            'delivery_price' => '349.00',
            'discount' => '200.00',
            'total' => '4909.50',
            'shipment' => null,
            'paid_at' => null,
            'cancelled_at' => null,
            'cancel_reason' => null,
            'created_at' => '2026-10-21T13:02:11+03:00',
            'updated_at' => '2026-10-21T13:02:11+03:00',
        ], $overrides);
    }
}
