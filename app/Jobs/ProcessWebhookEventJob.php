<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Webhooks\ProcessWebhookEventAction;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Async boundary for webhook handling. Thin by design: it loads the stored
 * event and delegates to the action. Safe to run more than once — the action
 * is idempotent.
 */
class ProcessWebhookEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $webhookEventId,
    ) {
    }

    public function handle(ProcessWebhookEventAction $action): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if ($event === null) {
            return;
        }

        $action($event);
    }
}
