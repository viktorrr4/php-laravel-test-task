<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Models\CustomerNotification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers one customer email on the dedicated `mail` queue.
 *
 * The CustomerNotification row is the idempotency anchor: it is created when the
 * email is decided on, and `sent_at` is stamped once delivered. A re-run after a
 * successful send is a no-op.
 */
class SendCustomerNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $notificationId,
    ) {
        $this->onQueue('mail');
    }

    /**
     * Spaced-out retries so a transient SMTP outage does not burn every attempt
     * in a second.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function handle(): void
    {
        $notification = CustomerNotification::with('order.items', 'order.shipment')
            ->find($this->notificationId);

        if ($notification === null || $notification->isSent()) {
            return;
        }

        $type = NotificationType::from($notification->type);

        Mail::to($notification->order->customer_email)
            ->send($type->mailable($notification->order));

        $notification->forceFill(['sent_at' => CarbonImmutable::now()])->save();
    }

    public function failed(Throwable $exception): void
    {
        CustomerNotification::whereKey($this->notificationId)->update([
            'attempts' => DB::raw('attempts + 1'),
            'last_error' => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }
}
