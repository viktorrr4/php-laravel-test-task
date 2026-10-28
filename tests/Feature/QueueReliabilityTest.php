<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Notifications\DispatchOrderNotificationsAction;
use App\Jobs\ProcessWebhookEventJob;
use App\Jobs\SendCustomerNotificationJob;
use App\Mail\OrderPaidMail;
use App\Models\CustomerNotification;
use App\Models\Order;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class QueueReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_is_queued_on_the_mail_queue(): void
    {
        Queue::fake();

        $order = Order::factory()->paid()->create();

        (new DispatchOrderNotificationsAction)($order);

        Queue::assertPushedOn('mail', SendCustomerNotificationJob::class);
    }

    public function test_send_job_is_idempotent_when_already_sent(): void
    {
        Mail::fake();

        $order = Order::factory()->paid()->create();
        $order->items()->create(['sku' => 'X', 'name' => 'X', 'quantity' => 1, 'price' => '1.00']);
        $notification = CustomerNotification::create([
            'order_id' => $order->id,
            'type' => 'paid',
            'sent_at' => CarbonImmutable::now(),
        ]);

        (new SendCustomerNotificationJob($notification->id))->handle();

        Mail::assertNothingSent();
    }

    public function test_send_job_delivers_and_stamps_sent_at(): void
    {
        Mail::fake();

        $order = Order::factory()->paid()->create();
        $order->items()->create(['sku' => 'X', 'name' => 'X', 'quantity' => 1, 'price' => '1.00']);
        $notification = CustomerNotification::create(['order_id' => $order->id, 'type' => 'paid']);

        (new SendCustomerNotificationJob($notification->id))->handle();

        Mail::assertSent(OrderPaidMail::class, 1);
        $this->assertNotNull($notification->fresh()->sent_at);
    }

    public function test_send_job_sends_once_when_run_concurrently(): void
    {
        Mail::fake();

        $order = Order::factory()->paid()->create();
        $order->items()->create(['sku' => 'X', 'name' => 'X', 'quantity' => 1, 'price' => '1.00']);
        $notification = CustomerNotification::create(['order_id' => $order->id, 'type' => 'paid']);

        // Two workers racing on the same notification: the atomic claim must let
        // only the first through, so the customer is emailed exactly once.
        (new SendCustomerNotificationJob($notification->id))->handle();
        (new SendCustomerNotificationJob($notification->id))->handle();

        Mail::assertSent(OrderPaidMail::class, 1);
    }

    public function test_send_job_releases_claim_when_delivery_fails(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

        $order = Order::factory()->paid()->create();
        $order->items()->create(['sku' => 'X', 'name' => 'X', 'quantity' => 1, 'price' => '1.00']);
        $notification = CustomerNotification::create(['order_id' => $order->id, 'type' => 'paid']);

        try {
            (new SendCustomerNotificationJob($notification->id))->handle();
            $this->fail('Expected the delivery failure to propagate.');
        } catch (RuntimeException) {
            // Expected: the job rethrows so the queue can retry.
        }

        // The claim is released so a retry can resend.
        $this->assertNull($notification->fresh()->sent_at);
    }

    public function test_send_job_suppresses_a_notification_that_no_longer_matches_order_state(): void
    {
        Mail::fake();

        // A paid mail was queued, then the order was cancelled before the job ran.
        $order = Order::factory()->cancelled()->create();
        $notification = CustomerNotification::create(['order_id' => $order->id, 'type' => 'paid']);

        (new SendCustomerNotificationJob($notification->id))->handle();

        Mail::assertNotSent(OrderPaidMail::class);

        $notification->refresh();
        $this->assertNotNull($notification->suppressed_at, 'Stale mail must be marked suppressed.');
        $this->assertNull($notification->sent_at, 'A suppressed mail was never sent.');
        $this->assertNull($notification->claimed_at, 'The claim is released on suppression.');
    }

    public function test_successful_send_clears_the_claim_and_stamps_only_sent_at(): void
    {
        Mail::fake();

        $order = Order::factory()->paid()->create();
        $order->items()->create(['sku' => 'X', 'name' => 'X', 'quantity' => 1, 'price' => '1.00']);
        $notification = CustomerNotification::create(['order_id' => $order->id, 'type' => 'paid']);

        (new SendCustomerNotificationJob($notification->id))->handle();

        $notification->refresh();
        $this->assertNotNull($notification->sent_at);
        $this->assertNull($notification->claimed_at, 'A confirmed send leaves no lingering claim.');
    }

    public function test_reconcile_skips_suppressed_notifications(): void
    {
        Queue::fake();

        $order = Order::factory()->cancelled()->create();
        $notification = $this->makeNotification($order, attempts: 0, createdAt: CarbonImmutable::now()->subMinutes(5));
        $notification->update(['suppressed_at' => CarbonImmutable::now()]);

        $this->artisan('app:reconcile-queued-work')->assertSuccessful();

        Queue::assertNotPushed(SendCustomerNotificationJob::class);
    }

    public function test_reconcile_releases_a_stale_claim_and_redispatches(): void
    {
        Queue::fake();

        $order = Order::factory()->paid()->create();
        $notification = $this->makeNotification($order, attempts: 0, createdAt: CarbonImmutable::now()->subMinutes(5));
        // Claimed long ago by a worker that never finished (claim TTL default 300s).
        $notification->update(['claimed_at' => CarbonImmutable::now()->subSeconds(400)]);

        $this->artisan('app:reconcile-queued-work')->assertSuccessful();

        $this->assertNull($notification->fresh()->claimed_at, 'A stale claim is released.');
        Queue::assertPushed(SendCustomerNotificationJob::class, 1);
    }

    public function test_reconcile_leaves_a_live_claim_alone(): void
    {
        Queue::fake();

        $order = Order::factory()->paid()->create();
        $notification = $this->makeNotification($order, attempts: 0, createdAt: CarbonImmutable::now()->subMinutes(5));
        // A fresh claim: a worker is actively processing it right now.
        $notification->update(['claimed_at' => CarbonImmutable::now()]);

        $this->artisan('app:reconcile-queued-work')->assertSuccessful();

        $this->assertNotNull($notification->fresh()->claimed_at, 'A live claim must not be disturbed.');
        Queue::assertNotPushed(SendCustomerNotificationJob::class);
    }

    public function test_failed_hook_records_attempt_and_error_on_notification(): void
    {
        $order = Order::factory()->create();
        $notification = CustomerNotification::create(['order_id' => $order->id, 'type' => 'paid']);

        (new SendCustomerNotificationJob($notification->id))->failed(new RuntimeException('smtp down'));

        $notification->refresh();
        $this->assertSame(1, $notification->attempts);
        $this->assertStringContainsString('smtp down', (string) $notification->last_error);
    }

    public function test_failed_hook_records_attempt_and_error_on_webhook_event(): void
    {
        $event = $this->makeWebhookEvent();

        (new ProcessWebhookEventJob($event->id))->failed(new RuntimeException('db down'));

        $event->refresh();
        $this->assertSame(1, $event->attempts);
        $this->assertStringContainsString('db down', (string) $event->last_error);
    }

    public function test_reconcile_redispatches_stuck_work_under_cap(): void
    {
        Queue::fake();

        $order = Order::factory()->paid()->create();
        // Stuck, under cap, old enough.
        $this->makeNotification($order, attempts: 2, createdAt: CarbonImmutable::now()->subMinutes(5));
        $this->makeWebhookEvent(attempts: 1, receivedAt: CarbonImmutable::now()->subMinutes(5));

        $this->artisan('app:reconcile-queued-work')->assertSuccessful();

        Queue::assertPushed(SendCustomerNotificationJob::class, 1);
        Queue::assertPushed(ProcessWebhookEventJob::class, 1);
    }

    public function test_reconcile_skips_work_at_or_over_cap(): void
    {
        Queue::fake();

        $order = Order::factory()->paid()->create();
        $this->makeNotification($order, attempts: 10, createdAt: CarbonImmutable::now()->subMinutes(5)); // == cap
        $this->makeWebhookEvent(attempts: 10, receivedAt: CarbonImmutable::now()->subMinutes(5));

        $this->artisan('app:reconcile-queued-work')->assertSuccessful();

        Queue::assertNotPushed(SendCustomerNotificationJob::class);
        Queue::assertNotPushed(ProcessWebhookEventJob::class);
    }

    public function test_reconcile_respects_grace_window(): void
    {
        Queue::fake();

        // Just created -> inside the grace window -> not retried yet.
        $this->makeWebhookEvent(attempts: 0, receivedAt: CarbonImmutable::now());

        $this->artisan('app:reconcile-queued-work')->assertSuccessful();

        Queue::assertNotPushed(ProcessWebhookEventJob::class);
    }

    private function makeNotification(Order $order, int $attempts, CarbonImmutable $createdAt): CustomerNotification
    {
        $notification = CustomerNotification::create([
            'order_id' => $order->id,
            'type' => 'paid',
            'attempts' => $attempts,
        ]);

        // created_at is not fillable; set it explicitly for grace/age assertions.
        $notification->created_at = $createdAt;
        $notification->save();

        return $notification;
    }

    private function makeWebhookEvent(int $attempts = 0, ?CarbonImmutable $receivedAt = null): WebhookEvent
    {
        return WebhookEvent::create([
            'event_id' => 'evt_'.uniqid(),
            'type' => 'order.paid',
            'marketplace_created_at' => CarbonImmutable::now(),
            'payload' => ['data' => ['order' => []]],
            'signature_valid' => true,
            'received_at' => $receivedAt ?? CarbonImmutable::now(),
            'attempts' => $attempts,
        ]);
    }
}
