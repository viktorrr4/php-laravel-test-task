<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Models\CustomerNotification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers one customer email on the dedicated `mail` queue.
 *
 * The CustomerNotification row tracks three distinct moments, never conflated:
 *  - `claimed_at`  — a worker is processing this row right now (released on
 *                    failure, or by the reconciler once stale);
 *  - `sent_at`     — the email was confirmed delivered; set only after send;
 *  - `suppressed_at` — the email no longer matches the order's state and will
 *                    never be sent (e.g. a paid mail for a since-cancelled order).
 *
 * Guarantee is at-least-once: if the process dies between a successful
 * `Mail::send()` and stamping `sent_at`, a later run resends. Exactly-once is not
 * promised because the SMTP result can be indeterminate.
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
        if (! $this->claim()) {
            return;
        }

        $notification = CustomerNotification::with('order.items', 'order.shipment')
            ->find($this->notificationId);

        if ($notification === null || $notification->order === null) {
            Log::warning('notification.missing', ['notification_id' => $this->notificationId]);

            return;
        }

        $type = NotificationType::from($notification->type);

        // Re-check against the current order state read under the claim: a
        // notification decided on earlier may have been overtaken (the order was
        // cancelled after a paid mail was queued). Such a mail is suppressed, not
        // sent, and marked so the reconciler never resurrects it.
        if (! $type->isValidFor($notification->order)) {
            CustomerNotification::whereKey($this->notificationId)->update([
                'suppressed_at' => CarbonImmutable::now(),
                'claimed_at' => null,
            ]);

            Log::info('notification.suppressed', [
                'notification_id' => $this->notificationId,
                'order_id' => $notification->order->id,
                'type' => $type->value,
                'status' => $notification->order->status->value,
            ]);

            return;
        }

        try {
            Mail::to($notification->order->customer_email)
                ->send($type->mailable($notification->order));
        } catch (Throwable $exception) {
            // Release the claim so a retry can resend; sent_at was never set, so
            // a transient SMTP failure cannot be mistaken for a delivery.
            CustomerNotification::whereKey($this->notificationId)->update(['claimed_at' => null]);

            throw $exception;
        }

        // Delivery confirmed: stamp sent_at and drop the claim.
        CustomerNotification::whereKey($this->notificationId)->update([
            'sent_at' => CarbonImmutable::now(),
            'claimed_at' => null,
        ]);

        Log::info('notification.sent', [
            'notification_id' => $this->notificationId,
            'order_id' => $notification->order->id,
            'type' => $type->value,
        ]);
    }

    /**
     * Atomically take the processing claim. Only the worker whose UPDATE flips
     * claimed_at proceeds; a concurrent worker (or a retry racing the original)
     * sees zero affected rows and stops. A claim older than the configured TTL is
     * treated as abandoned and may be re-taken, so a crashed worker never wedges
     * the row forever.
     */
    private function claim(): bool
    {
        $now = CarbonImmutable::now();
        $staleBefore = $now->subSeconds((int) config('queue.notification_claim_ttl', 300));

        $claimed = CustomerNotification::whereKey($this->notificationId)
            ->whereNull('sent_at')
            ->whereNull('suppressed_at')
            ->where(function ($query) use ($staleBefore): void {
                $query->whereNull('claimed_at')
                    ->orWhere('claimed_at', '<=', $staleBefore);
            })
            ->update(['claimed_at' => $now]);

        return $claimed > 0;
    }

    public function failed(Throwable $exception): void
    {
        CustomerNotification::whereKey($this->notificationId)->update([
            'attempts' => DB::raw('attempts + 1'),
            'last_error' => mb_substr($exception->getMessage(), 0, 1000),
        ]);

        Log::error('notification.failed', [
            'notification_id' => $this->notificationId,
            'error' => mb_substr($exception->getMessage(), 0, 200),
        ]);
    }
}
