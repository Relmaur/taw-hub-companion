<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Wire;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The five `X-Taw-Hub-*` headers, parsed and shape-validated (ADR-0003 step 1).
 * Anything missing, blank, or malformed — bad algo, non-numeric timestamp,
 * out-of-charset nonce, non-base64 or wrong-length signature — throws
 * {@see VerificationException::MALFORMED_SIGNATURE_HEADERS}.
 *
 * Steps 1–2 of ADR-0003 collapse here: the canonical is rebuilt from
 * `$timestamp` / `$nonce`, so there is no separate "headers echo the canonical"
 * failure — a bad value is caught as malformed here, and a mismatched value
 * fails the signature check downstream.
 */
final class SignatureHeaders
{
    public const ALGO_ED25519 = 'ed25519';
    public const ALGO_HMAC    = 'hmac-sha256';

    private const NONCE_PATTERN = '/^[A-Za-z0-9_\-]{8,128}$/';
    private const KEY_ID_PATTERN = '/^[A-Za-z0-9_\-.:]{1,128}$/';

    /**
     * @param non-empty-string $algo
     * @param non-empty-string $keyId
     * @param non-empty-string $nonce
     * @param non-empty-string $signatureRaw Decoded raw signature bytes (64 = Ed25519, 32 = HMAC).
     */
    private function __construct(
        public readonly string $algo,
        public readonly string $keyId,
        public readonly int $timestamp,
        public readonly string $nonce,
        public readonly string $signatureRaw,
    ) {
    }

    /**
     * @param callable(string): string $header Case-insensitive header lookup; '' when absent.
     * @throws VerificationException
     */
    public static function fromLookup(callable $header): self
    {
        $algo      = strtolower(trim($header('X-Taw-Hub-Algo')));
        $keyId     = trim($header('X-Taw-Hub-Key-Id'));
        $timestamp = trim($header('X-Taw-Hub-Timestamp'));
        $nonce     = trim($header('X-Taw-Hub-Nonce'));
        $signature = trim($header('X-Taw-Hub-Signature'));

        if (!in_array($algo, [self::ALGO_ED25519, self::ALGO_HMAC], true)) {
            throw self::malformed('algo');
        }
        if (preg_match(self::KEY_ID_PATTERN, $keyId) !== 1) {
            throw self::malformed('key-id');
        }
        if ($timestamp === '' || preg_match('/^\d{1,20}$/', $timestamp) !== 1) {
            throw self::malformed('timestamp');
        }
        if (preg_match(self::NONCE_PATTERN, $nonce) !== 1) {
            throw self::malformed('nonce');
        }

        $raw = base64_decode(strtr($signature, '-_', '+/'), true);
        if ($raw === false || $raw === '') {
            throw self::malformed('signature (not base64)');
        }

        $expectedLen = $algo === self::ALGO_ED25519
            ? SODIUM_CRYPTO_SIGN_BYTES        // 64
            : 32;                             // HMAC-SHA256
        if (strlen($raw) !== $expectedLen) {
            throw self::malformed('signature length');
        }

        return new self($algo, $keyId, (int) $timestamp, $nonce, $raw);
    }

    private static function malformed(string $which): VerificationException
    {
        return new VerificationException(
            VerificationException::MALFORMED_SIGNATURE_HEADERS,
            "malformed signature header: {$which}",
        );
    }
}
