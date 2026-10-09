<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Wire;

use TAW\HubCompanion\Config;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ADR-0003 step 4 — resolve verification key material by `(algo, keyId)`.
 *
 * A companion site trusts exactly one Hub. For `ed25519` the material is the
 * Hub's public key; for `hmac-sha256` it's the shared secret. When the
 * expected key id is configured, the inbound `X-Taw-Hub-Key-Id` must match it
 * exactly (constant-time); when it isn't, any id is accepted for that algo
 * (single-trusted-key mode).
 *
 * COORDINATION (ADR-0005): confirm with the Hub whether `TAW_HUB_KEY_ID` names
 * the Hub's inbound key id (used here) or is only the site's own response
 * key-id override.
 */
final class KeyRing
{
    /**
     * @param (\Closure(): array<string, string>)|null $fleetKeys extra Ed25519 keys by id, read
     *        when a request arrives (the theme is loaded by then)
     */
    public function __construct(
        private ?string $hubPublicKey,
        private ?string $expectedEd25519KeyId = null,
        private ?string $hmacSecret = null,
        private ?string $expectedHmacKeyId = null,
        private ?\Closure $fleetKeys = null,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            $config->hubPublicKey(),
            $config->hubKeyId(),
            $config->hmacSecret(),
            $config->hmacKeyId(),
            static fn (): array => $config->fleetKeys(),
        );
    }

    /**
     * @return string|null Raw key bytes, or null → {@see VerificationException::UNKNOWN_KEY_ID}.
     */
    public function resolve(string $algo, string $keyId): ?string
    {
        return match ($algo) {
            SignatureHeaders::ALGO_ED25519 => $this->gate($keyId, $this->expectedEd25519KeyId, $this->hubPublicKey) ?? $this->fleet($keyId),
            SignatureHeaders::ALGO_HMAC    => $this->gate($keyId, $this->expectedHmacKeyId, $this->hmacSecret),
            default                        => null,
        };
    }

    /**
     * A fleet key with exactly this id (constant-time per entry), or null.
     */
    private function fleet(string $keyId): ?string
    {
        if ($this->fleetKeys === null) {
            return null;
        }
        $found = null;
        foreach (($this->fleetKeys)() as $id => $material) {
            if (hash_equals((string) $id, $keyId)) {
                $found = $material;
            }
        }

        return $found;
    }

    private function gate(string $presentedKeyId, ?string $expectedKeyId, ?string $material): ?string
    {
        if ($material === null || $material === '') {
            return null;
        }
        if ($expectedKeyId !== null && !hash_equals($expectedKeyId, $presentedKeyId)) {
            return null;
        }

        return $material;
    }
}
