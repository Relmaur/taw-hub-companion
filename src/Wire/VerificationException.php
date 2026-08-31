<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Wire;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown by {@see SignatureGate} / {@see SignatureHeaders} when a request
 * fails verification. {@see self::reason()} is one of the stable slugs from
 * taw-site-manager ADR-0003 — it is safe to expose in the 401 body and MUST
 * NOT leak internal detail.
 */
final class VerificationException extends \RuntimeException
{
    public const MALFORMED_SIGNATURE_HEADERS = 'malformed_signature_headers';
    public const TIMESTAMP_OUT_OF_WINDOW     = 'timestamp_out_of_window';
    public const UNKNOWN_KEY_ID              = 'unknown_key_id';
    public const INVALID_SIGNATURE           = 'invalid_signature';
    public const REPLAYED_NONCE              = 'replayed_nonce';

    public function __construct(private string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
