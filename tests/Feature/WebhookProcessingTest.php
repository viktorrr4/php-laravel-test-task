<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\OrderCancelledMail;
use App\Mail\OrderPaidMail;
use App\Mail\OrderShippedMail;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

class WebhookProcessingTest extends TestCase
{
    use RefreshDatabase;
    use SendsWebhooks;

    public function test_paid_event_updates_state_records_history_and_emails_once(): void
    {
        Mail::fake();

        $snapshot = $this->orderSnapshot([
            'status' => 'paid',
            'paid_at' => '2026-10-21T13:05:47+03:00',
            'updated_at' => '2026-10-21T13:05:47+03:00',
        ]);

        $this->postWebhook($this->event('evt_paid', 'order.paid', $snapshot))->assertAccepted();

        $order = Order::firstWhere('marketplace_order_id', 'ord_7Q2KX9M4');
        $this->assertSame('paid', $order->status->value);
        $this->assertNotNull($order->paid_at);
        $this->assertEqualsCanonicalizing(
            ['created', 'paid'],
            $order->statusHistory()->pluck('status')->map(fn ($s) => $s->value)->all(),
        );

        Mail::assertSent(OrderPaidMail::class, 1);
    }

    public function test_shipped_event_stores_shipment_and_sends_paid_and_shipped_mails(): void
    {
        Mail::fake();

        $snapshot = $this->orderSnapshot([
            'status' => 'shipped',
            'paid_at' => '2026-10-21T13:05:47+03:00',
            'updated_at' => '2026-10-22T06:40:03+00:00',
            'shipment' => [
                'carrier' => 'СДЭК',
                'tracking_number' => '1098765432',
                'tracking_url' => 'https://www.cdek.ru/ru/tracking?order_id=1098765432',
            ],
        ]);

        $this->postWebhook($this->event('evt_shipped', 'order.shipped', $snapshot))->assertAccepted();

        $this->assertDatabaseHas('order_shipments', ['tracking_number' => '1098765432']);
        Mail::assertSent(OrderShippedMail::class, 1);
        Mail::assertSent(OrderPaidMail::class, 1);
    }

    public function test_duplicate_delivery_is_idempotent(): void
    {
        Mail::fake();

        $snapshot = $this->orderSnapshot([
            'status' => 'paid',
            'paid_at' => '2026-10-21T13:05:47+03:00',
            'updated_at' => '2026-10-21T13:05:47+03:00',
        ]);
        $event = $this->event('evt_paid', 'order.paid', $snapshot);

        $this->postWebhook($event)->assertAccepted();
        $this->postWebhook($event)->assertAccepted(); // redelivered with same id

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('customer_notifications', 1);
        Mail::assertSent(OrderPaidMail::class, 1);
    }

    public function test_cancellation_after_payment_mentions_refund_and_suppresses_paid_mail(): void
    {
        Mail::fake();

        // Only the cancellation is ever delivered; it still carries paid_at.
        $cancelled = $this->orderSnapshot([
            'status' => 'cancelled',
            'paid_at' => '2026-10-21T18:11:02+03:00',
            'cancelled_at' => '2026-10-21T20:47:15+03:00',
            'cancel_reason' => 'Покупатель отказался от заказа',
            'updated_at' => '2026-10-21T20:47:15+03:00',
        ]);

        $this->postWebhook($this->event('evt_cancel', 'order.cancelled', $cancelled))->assertAccepted();

        $order = Order::firstWhere('marketplace_order_id', 'ord_7Q2KX9M4');
        $this->assertSame('cancelled', $order->status->value);

        Mail::assertSent(OrderCancelledMail::class, 1);
        Mail::assertNotSent(OrderPaidMail::class);
        Mail::assertSent(OrderCancelledMail::class, fn (OrderCancelledMail $mail) => $mail->order->paid_at !== null);
    }

    public function test_out_of_order_paid_after_cancel_does_not_resurrect_or_email(): void
    {
        Mail::fake();

        $cancelled = $this->orderSnapshot([
            'status' => 'cancelled',
            'paid_at' => '2026-10-21T18:11:02+03:00',
            'cancelled_at' => '2026-10-21T20:47:15+03:00',
            'cancel_reason' => 'Отменён',
            'updated_at' => '2026-10-21T20:47:15+03:00',
        ]);

        // Older paid snapshot, delivered late.
        $paid = $this->orderSnapshot([
            'status' => 'paid',
            'paid_at' => '2026-10-21T18:11:02+03:00',
            'updated_at' => '2026-10-21T18:11:02+03:00',
        ]);

        $this->postWebhook($this->event('evt_cancel', 'order.cancelled', $cancelled))->assertAccepted();
        $this->postWebhook($this->event('evt_paid', 'order.paid', $paid))->assertAccepted();

        $order = Order::firstWhere('marketplace_order_id', 'ord_7Q2KX9M4');
        $this->assertSame('cancelled', $order->status->value, 'Stale paid snapshot must not overwrite cancelled state.');

        Mail::assertNotSent(OrderPaidMail::class);
        Mail::assertSent(OrderCancelledMail::class, 1);
    }

    public function test_unknown_event_type_still_applies_the_snapshot(): void
    {
        Mail::fake();

        $snapshot = $this->orderSnapshot(['status' => 'created']);

        // A type the service has never seen before; the snapshot is authoritative.
        $this->postWebhook($this->event('evt_new', 'order.repriced', $snapshot))->assertAccepted();

        $this->assertDatabaseHas('orders', ['marketplace_order_id' => 'ord_7Q2KX9M4', 'status' => 'created']);
    }
}
