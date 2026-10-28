<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Verifies MarketHub webhook signatures.
 *
 * Signature = HMAC-SHA256("{timestamp}.{raw body}", secret), lowercase hex,
 * delivered in the `X-Webhook-Signature: sha256=<hex>` header. The raw request
 * body must be used byte-for-byte — never a re-encoded JSON string.
 */
final class WebhookSignatureVerifier
{
    public function __construct(
        private readonly string $secret,
        private readonly int $tolerance = 300,
    ) {}

    /**
     * Constant-time check that the provided signature header matches the body.
     */
    public function isValid(string $timestamp, string $rawBody, string $signatureHeader): bool
    {
        // A missing secret cannot authenticate anything: treat it as invalid
        // rather than verifying against an empty HMAC key, which would let any
        // request forge a matching signature (fail-open).
        if ($this->secret === '') {
            return false;
        }

        $provided = $this->extractHex($signatureHeader);

        if ($provided === null) {
            return false;
        }

        $expected = $this->expectedSignature($timestamp, $rawBody);

        return hash_equals($expected, $provided);
    }

    /**
     * Rejects requests whose timestamp drifts too far from now, limiting the
     * window in which a captured request could be replayed.
     */
    public function isFresh(string $timestamp, ?int $now = null): bool
    {
        if (! ctype_digit($timestamp)) {
            return false;
        }

        $now ??= time();

        return abs($now - (int) $timestamp) <= $this->tolerance;
    }

    public function expectedSignature(string $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret);
    }

    private function extractHex(string $signatureHeader): ?string
    {
        if (! str_starts_with($signatureHeader, 'sha256=')) {
            return null;
        }

        $hex = substr($signatureHeader, strlen('sha256='));

        return ctype_xdigit($hex) ? strtolower($hex) : null;
    }
}
