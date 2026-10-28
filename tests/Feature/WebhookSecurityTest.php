<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

class WebhookSecurityTest extends TestCase
{
    use RefreshDatabase;
    use SendsWebhooks;

    public function test_valid_signature_is_accepted_and_stored(): void
    {
        $event = $this->event('evt_1', 'order.created', $this->orderSnapshot());

        $this->postWebhook($event)->assertAccepted();

        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_1', 'type' => 'order.created']);
        $this->assertDatabaseHas('orders', ['marketplace_order_id' => 'ord_7Q2KX9M4', 'status' => 'created']);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $event = $this->event('evt_1', 'order.created', $this->orderSnapshot());

        $this->postWebhook($event, ['X-Webhook-Signature' => 'sha256=deadbeef'])
            ->assertUnauthorized();

        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $event = $this->event('evt_1', 'order.created', $this->orderSnapshot());

        $this->postWebhook($event, ['X-Webhook-Timestamp' => (string) (time() - 3600)])
            ->assertUnauthorized();

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_missing_headers_are_rejected(): void
    {
        $body = json_encode($this->event('evt_1', 'order.created', $this->orderSnapshot()));

        $this->call('POST', '/api/webhooks/marketplace', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(400);
    }

    public function test_malformed_money_amount_is_rejected_before_storing_or_queuing(): void
    {
        // Each value violates the amount contract in a different way.
        foreach (['-10.00', '49.999', '100', 'abc', '1,000.00'] as $badTotal) {
            $event = $this->event('evt_bad', 'order.created', $this->orderSnapshot(['total' => $badTotal]));

            $this->postWebhook($event)->assertStatus(422);
        }

        // A validation failure happens before anything is persisted or queued.
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_item_price_must_also_be_a_valid_amount(): void
    {
        $snapshot = $this->orderSnapshot();
        $snapshot['items'][0]['price'] = '12.3'; // one fraction digit

        $this->postWebhook($this->event('evt_bad_item', 'order.created', $snapshot))->assertStatus(422);

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_well_formed_two_decimal_amounts_are_accepted(): void
    {
        $snapshot = $this->orderSnapshot([
            'delivery_price' => '0.00',
            'discount' => '0.00',
            'total' => '4909.50',
        ]);

        $this->postWebhook($this->event('evt_ok', 'order.created', $snapshot))->assertAccepted();

        $this->assertDatabaseHas('orders', ['marketplace_order_id' => 'ord_7Q2KX9M4', 'total' => '4909.50']);
    }
}
