<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessWebhookEventJob;
use App\Jobs\SendCustomerNotificationJob;
use App\Models\CustomerNotification;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Recovers stuck queued work by reconciling against domain state rather than
 * blindly retrying failed_jobs.
 *
 * Re-dispatches webhook events that were never applied and customer
 * notifications that were never delivered — but only while they are under the
 * attempt cap, so a permanently broken job stops looping and remains a visible
 * dead letter (attempts == cap, last_error set). Both jobs are idempotent, so a
 * redundant re-dispatch is harmless.
 */
class ReconcileQueuedWorkCommand extends Command
{
    protected $signature = 'app:reconcile-queued-work {--grace=60 : Seconds to wait before retrying, to avoid racing a running job}';

    protected $description = 'Re-dispatch stuck webhook events and customer notifications within the attempt cap';

    public function handle(): int
    {
        $cap = (int) config('queue.reconcile_cap', 10);
        $cutoff = CarbonImmutable::now()->subSeconds((int) $this->option('grace'));

        $events = WebhookEvent::query()
            ->whereNull('processed_at')
            ->where('attempts', '<', $cap)
            ->where('received_at', '<=', $cutoff)
            ->get();

        foreach ($events as $event) {
            ProcessWebhookEventJob::dispatch($event->id);
        }

        $notifications = CustomerNotification::query()
            ->whereNull('sent_at')
            ->where('attempts', '<', $cap)
            ->where('created_at', '<=', $cutoff)
            ->get();

        foreach ($notifications as $notification) {
            SendCustomerNotificationJob::dispatch($notification->id);
        }

        $this->info("Re-dispatched {$events->count()} webhook event(s) and {$notifications->count()} notification(s).");

        return self::SUCCESS;
    }
}
