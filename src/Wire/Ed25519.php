<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Wire;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ed25519 detached signatures (libsodium). Hub ↔ companion channel.
 */
final class Ed25519
{
    public static function verify(string $rawSignature, string $message, string $publicKey): bool
    {
        if (
            strlen($rawSignature) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        ) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($rawSignature, $message, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    public static function sign(string $message, string $secretKey): string
    {
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException(
                'Ed25519 secret key must be ' . SODIUM_CRYPTO_SIGN_SECRETKEYBYTES . ' bytes.',
            );
        }

        return sodium_crypto_sign_detached($message, $secretKey);
    }
}
