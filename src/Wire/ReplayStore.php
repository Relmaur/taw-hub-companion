<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Wire;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ADR-0003 step 6 — single-use nonce enforcement, keyed by `(algo, keyId, nonce)`.
 *
 * With a persistent object cache, `wp_cache_add()` is atomic and authoritative
 * — a `false` return means the nonce was already spent. Without one, it falls
 * back to a transient check-then-set: not atomic against two truly
 * simultaneous requests reusing one nonce (a narrow race that widens the
 * replay window fractionally; the signature + timestamp checks still stand).
 * ADR-0003 recommends a Redis-backed cache in production. TTL defaults to 150s
 * (2× the 60s drift window + slack).
 */
final class ReplayStore
{
    private const GROUP = 'taw_hub_nonce';

    public function __construct(private int $ttlSeconds = 150)
    {
    }

    /**
     * @return bool true if the nonce was fresh and is now consumed; false if it was already spent.
     */
    public function consume(string $algo, string $keyId, string $nonce): bool
    {
        $key = $algo . ':' . $keyId . ':' . hash('sha256', $nonce);

        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            return (bool) wp_cache_add($key, 1, self::GROUP, $this->ttlSeconds);
        }

        $transientKey = 'taw_hub_nonce_' . md5($key);
        if (get_transient($transientKey) !== false) {
            return false;
        }
        set_transient($transientKey, 1, $this->ttlSeconds);

        return true;
    }
}
