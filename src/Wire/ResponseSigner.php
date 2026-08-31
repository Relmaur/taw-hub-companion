<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Wire;

use TAW\HubCompanion\Keys\SiteKeypair;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Signs an outgoing response so the Hub can verify it wasn't tampered with in
 * transit (ADR-0003 "Responses" — `HttpCompanionClient` hard-fails on a
 * missing or bad response signature).
 *
 * The response canonical uses the literal `RESPONSE` for `{METHOD}` and the
 * *request's* signed path for `{PATH}`. Signed with the site's own Ed25519
 * key; the Hub verifies against `managed_sites.companion_public_key`.
 */
final class ResponseSigner
{
    /** @var callable(): int */
    private $clock;

    public function __construct(
        private SiteKeypair $keypair,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return array<string, string> The `X-Taw-Hub-*` headers to add to the response.
     */
    public function headersFor(string $signedRequestPath, string $rawResponseBody): array
    {
        $timestamp = ($this->clock)();
        $nonce     = self::nonce();

        $canonical = CanonicalString::forResponse($signedRequestPath, $rawResponseBody, $timestamp, $nonce);
        $signature = base64_encode($this->keypair->sign($canonical));

        return [
            'X-Taw-Hub-Algo'      => SignatureHeaders::ALGO_ED25519,
            'X-Taw-Hub-Key-Id'    => $this->keypair->keyId(),
            'X-Taw-Hub-Timestamp' => (string) $timestamp,
            'X-Taw-Hub-Nonce'     => $nonce,
            'X-Taw-Hub-Signature' => $signature,
        ];
    }

    private static function nonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }
}
