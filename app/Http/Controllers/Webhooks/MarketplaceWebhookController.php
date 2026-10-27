<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\MarketplaceWebhookRequest;
use App\Jobs\ProcessWebhookEventJob;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts verified MarketHub webhooks. Kept intentionally thin: it records the
 * event idempotently and hands processing to a queued job, so it can answer
 * within the marketplace's 5-second window regardless of downstream work.
 */
class MarketplaceWebhookController extends Controller
{
    public function __invoke(MarketplaceWebhookRequest $request): JsonResponse
    {
        $eventId = (string) $request->input('id');

        // Idempotency anchor: the same event id is stored once, even across retries.
        $event = WebhookEvent::firstOrCreate(
            ['event_id' => $eventId],
            [
                'type' => (string) $request->input('type'),
                'marketplace_created_at' => CarbonImmutable::parse($request->input('created_at')),
                'payload' => $request->validated(),
                'signature_valid' => true,
                'received_at' => CarbonImmutable::now(),
            ],
        );

        // Queue processing unless this event was already fully applied.
        if (! $event->isProcessed()) {
            ProcessWebhookEventJob::dispatch($event->id);
        }

        return response()->json(['received' => true], Response::HTTP_ACCEPTED);
    }
}
