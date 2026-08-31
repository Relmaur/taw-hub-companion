<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Wire;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds the exact byte string both the Hub and this plugin sign, per
 * taw-site-manager ADR-0003 (`docs/reference/wire-protocol.md`):
 *
 *   TAW-HUB-v1 \n
 *   {METHOD}                      upper-case HTTP verb, or the literal RESPONSE
 *   {PATH}                        leading slash, no query, no host
 *   {TIMESTAMP}                   unix seconds, decimal string
 *   {NONCE}
 *   {sha256(body)}                lower-case hex of the raw body bytes
 *
 * `\n`-joined, NO trailing newline. Any change here is a breaking protocol
 * change (bump `TAW-HUB-v1` → `v2`, needs a superseding ADR).
 *
 * The PATH is reconstructed by the caller as `/<rest-prefix>/<namespace>/<route>`
 * from the *matched REST route* — never from `$_SERVER['REQUEST_URI']`, which
 * carries a subdirectory prefix the Hub doesn't sign. See {@see \TAW\HubCompanion\Http\SignatureGuard}.
 */
final class CanonicalString
{
    public const SCHEME = 'TAW-HUB-v1';

    /**
     * @param non-empty-string $bodySha256Hex
     */
    public static function build(
        string $method,
        string $path,
        int $timestamp,
        string $nonce,
        string $bodySha256Hex,
    ): string {
        return implode("\n", [
            self::SCHEME,
            strtoupper($method),
            $path,
            (string) $timestamp,
            $nonce,
            $bodySha256Hex,
        ]);
    }

    public static function forRequest(
        string $method,
        string $path,
        string $rawBody,
        int $timestamp,
        string $nonce,
    ): string {
        return self::build($method, $path, $timestamp, $nonce, hash('sha256', $rawBody));
    }

    /**
     * Response canonical: `{METHOD}` is the literal `RESPONSE` (domain-separates
     * a signed response from a request), `{PATH}` is the *request's* signed path.
     */
    public static function forResponse(
        string $signedRequestPath,
        string $rawResponseBody,
        int $timestamp,
        string $nonce,
    ): string {
        return self::build('RESPONSE', $signedRequestPath, $timestamp, $nonce, hash('sha256', $rawResponseBody));
    }
}
