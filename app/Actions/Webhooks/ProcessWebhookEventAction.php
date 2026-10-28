<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Actions\Notifications\DispatchOrderNotificationsAction;
use App\DataObjects\OrderSnapshot;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies a single stored webhook event to the read model.
 *
 * The whole operation runs in one transaction and is idempotent: the event row
 * is locked and skipped if already processed, so duplicate deliveries (a core
 * guarantee of the marketplace) never apply twice.
 */
class ProcessWebhookEventAction
{
    public function __construct(
        private readonly UpsertOrderFromSnapshotAction $upsertOrder,
        private readonly DispatchOrderNotificationsAction $dispatchNotifications,
    ) {
    }

    public function __invoke(WebhookEvent $event): void
    {
        DB::transaction(function () use ($event): void {
            $locked = WebhookEvent::whereKey($event->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->isProcessed()) {
                return;
            }

            $snapshot = OrderSnapshot::fromArray($locked->payload['data']['order']);

            $order = ($this->upsertOrder)($snapshot);
            ($this->dispatchNotifications)($order);

            $locked->forceFill(['processed_at' => CarbonImmutable::now()])->save();
        });
    }
}
