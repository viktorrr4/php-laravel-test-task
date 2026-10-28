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

    public function test_marketplace_timestamps_are_stored_as_the_same_instant_in_utc(): void
    {
        Mail::fake();

        // +03:00 wall-clock must be persisted as the same moment in UTC, not
        // reinterpreted as a UTC wall-clock three hours off.
        $snapshot = $this->orderSnapshot([
            'status' => 'paid',
            'paid_at' => '2026-10-21T13:05:47+03:00',
            'updated_at' => '2026-10-21T13:05:47+03:00',
        ]);

        $this->postWebhook($this->event('evt_paid', 'order.paid', $snapshot))->assertAccepted();

        $order = Order::firstWhere('marketplace_order_id', 'ord_7Q2KX9M4');
        $this->assertSame('2026-10-21T10:05:47+00:00', $order->marketplace_updated_at->toIso8601String());
        $this->assertSame('2026-10-21T10:05:47+00:00', $order->paid_at->toIso8601String());
    }

    public function test_late_shipped_after_delivered_appends_history_without_rolling_back_state(): void
    {
        Mail::fake();

        $shipment = ['carrier' => 'СДЭК', 'tracking_number' => '1098765432', 'tracking_url' => 'https://track/1'];

        $delivered = $this->orderSnapshot([
            'status' => 'delivered',
            'paid_at' => '2026-10-21T13:05:47+03:00',
            'updated_at' => '2026-10-22T10:00:00+00:00',
            'shipment' => $shipment,
        ]);
        $shipped = $this->orderSnapshot([
            'status' => 'shipped',
            'paid_at' => '2026-10-21T13:05:47+03:00',
            'updated_at' => '2026-10-22T06:40:03+00:00', // earlier -> stale
            'shipment' => $shipment,
        ]);

        $this->postWebhook($this->event('evt_delivered', 'order.delivered', $delivered))->assertAccepted();
        $this->postWebhook($this->event('evt_shipped', 'order.shipped', $shipped))->assertAccepted();

        $order = Order::firstWhere('marketplace_order_id', 'ord_7Q2KX9M4');
        $this->assertSame('delivered', $order->status->value, 'Late shipped snapshot must not roll back delivered state.');
        $this->assertEqualsCanonicalizing(
            ['created', 'paid', 'shipped', 'delivered'],
            $order->statusHistory()->pluck('status')->map(fn ($s) => $s->value)->all(),
            'The shipped transition still belongs in the history.',
        );
    }

    public function test_status_recurring_at_a_new_moment_is_kept_as_a_separate_history_entry(): void
    {
        Mail::fake();

        $shipment = ['carrier' => 'СДЭК', 'tracking_number' => '1098765432', 'tracking_url' => 'https://track/1'];

        $firstShip = $this->orderSnapshot(['status' => 'shipped', 'paid_at' => '2026-10-21T13:05:47+03:00', 'updated_at' => '2026-10-22T06:40:03+00:00', 'shipment' => $shipment]);
        $delivered = $this->orderSnapshot(['status' => 'delivered', 'paid_at' => '2026-10-21T13:05:47+03:00', 'updated_at' => '2026-10-22T08:00:00+00:00', 'shipment' => $shipment]);
        // Support manually re-ships after an erroneous delivery (fresher snapshot).
        $reShip = $this->orderSnapshot(['status' => 'shipped', 'paid_at' => '2026-10-21T13:05:47+03:00', 'updated_at' => '2026-10-22T10:00:00+00:00', 'shipment' => $shipment]);

        $this->postWebhook($this->event('evt_ship_1', 'order.shipped', $firstShip))->assertAccepted();
        $this->postWebhook($this->event('evt_delivered', 'order.delivered', $delivered))->assertAccepted();
        $this->postWebhook($this->event('evt_ship_2', 'order.shipped', $reShip))->assertAccepted();

        $order = Order::firstWhere('marketplace_order_id', 'ord_7Q2KX9M4');
        $this->assertSame('shipped', $order->status->value);
        $this->assertSame(
            2,
            $order->statusHistory()->where('status', 'shipped')->count(),
            'Each distinct shipped transition is its own history entry.',
        );
    }

    public function test_full_snapshot_without_shipment_removes_a_stored_shipment(): void
    {
        Mail::fake();

        $shipped = $this->orderSnapshot([
            'status' => 'shipped',
            'paid_at' => '2026-10-21T13:05:47+03:00',
            'updated_at' => '2026-10-22T06:40:03+00:00',
            'shipment' => ['carrier' => 'СДЭК', 'tracking_number' => '1098765432', 'tracking_url' => 'https://track/1'],
        ]);
        // A fresher snapshot that no longer carries a shipment.
        $cancelled = $this->orderSnapshot([
            'status' => 'cancelled',
            'paid_at' => '2026-10-21T13:05:47+03:00',
            'cancelled_at' => '2026-10-22T09:00:00+00:00',
            'cancel_reason' => 'Отменён',
            'updated_at' => '2026-10-22T09:00:00+00:00',
            'shipment' => null,
        ]);

        $this->postWebhook($this->event('evt_shipped', 'order.shipped', $shipped))->assertAccepted();
        $this->assertDatabaseCount('order_shipments', 1);

        $this->postWebhook($this->event('evt_cancel', 'order.cancelled', $cancelled))->assertAccepted();
        $this->assertDatabaseCount('order_shipments', 0);
    }

    public function test_tie_break_on_equal_updated_at_keeps_the_later_event(): void
    {
        Mail::fake();

        // Same updated_at on both snapshots: the event produced later wins, so a
        // late-delivered earlier paid cannot overwrite the cancellation.
        $sameUpdatedAt = '2026-10-21T20:47:15+03:00';

        $cancelled = $this->orderSnapshot([
            'status' => 'cancelled',
            'paid_at' => '2026-10-21T18:11:02+03:00',
            'cancelled_at' => $sameUpdatedAt,
            'cancel_reason' => 'Отменён',
            'updated_at' => $sameUpdatedAt,
        ]);
        $paid = $this->orderSnapshot([
            'status' => 'paid',
            'paid_at' => '2026-10-21T18:11:02+03:00',
            'updated_at' => $sameUpdatedAt,
        ]);

        // Cancellation event produced after the paid event.
        $this->postWebhook($this->event('evt_cancel', 'order.cancelled', $cancelled, '2026-10-21T17:47:16+00:00'))->assertAccepted();
        $this->postWebhook($this->event('evt_paid', 'order.paid', $paid, '2026-10-21T10:05:48+00:00'))->assertAccepted();

        $order = Order::firstWhere('marketplace_order_id', 'ord_7Q2KX9M4');
        $this->assertSame('cancelled', $order->status->value);
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
