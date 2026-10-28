<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Webhooks\ProcessWebhookEventAction;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Async boundary for webhook handling, on the dedicated `webhooks` queue. Thin
 * by design: it loads the stored event and delegates to the action. Safe to run
 * more than once — the action is idempotent.
 */
class ProcessWebhookEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $webhookEventId,
    ) {
        $this->onQueue('webhooks');
    }

    /**
     * Spaced-out retries so a transient DB outage does not burn every attempt
     * in a second.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function handle(ProcessWebhookEventAction $action): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if ($event === null) {
            return;
        }

        $action($event);
    }

    public function failed(Throwable $exception): void
    {
        WebhookEvent::whereKey($this->webhookEventId)->update([
            'attempts' => DB::raw('attempts + 1'),
            'last_error' => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }
}
