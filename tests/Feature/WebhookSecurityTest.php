<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\WebhookEvent;
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
}
