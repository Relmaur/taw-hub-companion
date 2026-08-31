<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Wire;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * HMAC-SHA256 over a shared secret, raw (binary) output compared with
 * `hash_equals`. Hub ↔ n8n channel; supported here for completeness so the
 * plugin implements the whole of ADR-0003.
 */
final class HmacSha256
{
    public static function verify(string $rawSignature, string $message, string $secret): bool
    {
        return hash_equals(hash_hmac('sha256', $message, $secret, true), $rawSignature);
    }

    public static function sign(string $message, string $secret): string
    {
        return hash_hmac('sha256', $message, $secret, true);
    }
}
