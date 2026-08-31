<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Wire;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The ADR-0003 verification pipeline, in the mandated order:
 *
 *   1. parse headers            → malformed_signature_headers   (done in {@see SignatureHeaders})
 *   2. headers echo canonical   → (implicit — canonical is rebuilt from the headers)
 *   3. |now − timestamp| ≤ 60s  → timestamp_out_of_window
 *   4. resolve key by (algo,id) → unknown_key_id
 *   5. cryptographic verify     → invalid_signature
 *   6. consume nonce (LAST)     → replayed_nonce
 *
 * Replay is checked last so a forged/invalid request never populates the
 * nonce store (which would be a DoS: pre-seeding nonces).
 */
final class SignatureGate
{
    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(): int)|null $clock Unix-seconds time source; defaults to time().
     */
    public function __construct(
        private KeyRing $keys,
        private ReplayStore $replay,
        private int $maxDriftSeconds = 60,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param string $signedPath The reconstructed `/<prefix>/<namespace>/<route>` path.
     * @throws VerificationException
     */
    public function verify(string $method, string $signedPath, string $rawBody, SignatureHeaders $headers): void
    {
        $now = ($this->clock)();
        if (abs($now - $headers->timestamp) > $this->maxDriftSeconds) {
            throw new VerificationException(VerificationException::TIMESTAMP_OUT_OF_WINDOW);
        }

        $key = $this->keys->resolve($headers->algo, $headers->keyId);
        if ($key === null || $key === '') {
            throw new VerificationException(VerificationException::UNKNOWN_KEY_ID);
        }

        $canonical = CanonicalString::forRequest(
            $method,
            $signedPath,
            $rawBody,
            $headers->timestamp,
            $headers->nonce,
        );

        $ok = match ($headers->algo) {
            SignatureHeaders::ALGO_ED25519 => Ed25519::verify($headers->signatureRaw, $canonical, $key),
            SignatureHeaders::ALGO_HMAC    => HmacSha256::verify($headers->signatureRaw, $canonical, $key),
            default                        => false,
        };
        if (!$ok) {
            throw new VerificationException(VerificationException::INVALID_SIGNATURE);
        }

        if (!$this->replay->consume($headers->algo, $headers->keyId, $headers->nonce)) {
            throw new VerificationException(VerificationException::REPLAYED_NONCE);
        }
    }
}
