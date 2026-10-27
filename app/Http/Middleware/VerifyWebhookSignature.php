<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\WebhookSignatureVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects MarketHub webhook requests that are unsigned, stale, or tampered with,
 * before any application logic runs. Operates on the raw request body.
 */
class VerifyWebhookSignature
{
    public function __construct(
        private readonly WebhookSignatureVerifier $verifier,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = $request->header('X-Webhook-Timestamp');
        $signature = $request->header('X-Webhook-Signature');

        if (! is_string($timestamp) || ! is_string($signature) || $timestamp === '' || $signature === '') {
            abort(Response::HTTP_BAD_REQUEST, 'Missing webhook signature headers.');
        }

        if (! $this->verifier->isFresh($timestamp)) {
            abort(Response::HTTP_UNAUTHORIZED, 'Webhook timestamp outside allowed tolerance.');
        }

        if (! $this->verifier->isValid($timestamp, $request->getContent(), $signature)) {
            abort(Response::HTTP_UNAUTHORIZED, 'Invalid webhook signature.');
        }

        return $next($request);
    }
}
