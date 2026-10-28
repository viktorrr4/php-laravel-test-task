<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessWebhookEventJob;
use App\Jobs\SendCustomerNotificationJob;
use App\Actions\Notifications\DispatchOrderNotificationsAction;
use App\Enums\OrderStatus;
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

        (new DispatchOrderNotificationsAction())($order);

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
