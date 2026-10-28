<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

class WebhookSignatureVerifierTest extends TestCase
{
    public function test_it_matches_the_reference_signature_from_the_task(): void
    {
        // Vectors from task/examples/signature-example.md.
        $secret = 'whsec_test_5f1d8c2a9b';
        $timestamp = '1792577149';
        $body = file_get_contents(__DIR__.'/../../task/examples/signature-example-body.json');
        $expected = 'sha256=4d4f2d50f98596ab503d29dc96db6fda247e3c482bff130bd97353564cb163f7';

        $verifier = new WebhookSignatureVerifier($secret);

        $this->assertTrue($verifier->isValid($timestamp, $body, $expected));
    }

    public function test_it_rejects_a_tampered_body(): void
    {
        $verifier = new WebhookSignatureVerifier('secret');
        $header = 'sha256='.$verifier->expectedSignature('123', '{"a":1}');

        $this->assertFalse($verifier->isValid('123', '{"a":2}', $header));
    }

    public function test_it_rejects_wrong_secret(): void
    {
        $signed = new WebhookSignatureVerifier('real-secret');
        $header = 'sha256='.$signed->expectedSignature('123', '{"a":1}');

        $attacker = new WebhookSignatureVerifier('guessed-secret');

        $this->assertFalse($attacker->isValid('123', '{"a":1}', $header));
    }

    public function test_it_rejects_everything_when_the_secret_is_missing(): void
    {
        // A fail-open verifier would authenticate a signature computed with an
        // empty key; an unconfigured secret must reject instead.
        $verifier = new WebhookSignatureVerifier('');
        $forged = 'sha256='.hash_hmac('sha256', '123.{"a":1}', '');

        $this->assertFalse($verifier->isValid('123', '{"a":1}', $forged));
    }

    public function test_it_rejects_malformed_signature_header(): void
    {
        $verifier = new WebhookSignatureVerifier('secret');

        $this->assertFalse($verifier->isValid('123', '{}', 'deadbeef'));
        $this->assertFalse($verifier->isValid('123', '{}', 'sha256=not-hex'));
    }

    public function test_freshness_window(): void
    {
        $verifier = new WebhookSignatureVerifier('secret', tolerance: 300);

        $this->assertTrue($verifier->isFresh((string) (1000 + 299), now: 1000));
        $this->assertTrue($verifier->isFresh((string) (1000 - 299), now: 1000));
        $this->assertFalse($verifier->isFresh((string) (1000 + 301), now: 1000));
        $this->assertFalse($verifier->isFresh('not-a-number', now: 1000));
    }
}
