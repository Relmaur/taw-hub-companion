<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Keys;

use TAW\HubCompanion\Config;
use TAW\HubCompanion\Wire\Ed25519;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * This site's own Ed25519 identity — generated once on plugin activation,
 * stored in autoload-off options, and surfaced (public half only) via
 * `GET /health` so the Hub operator can register it (`RegisterSite`).
 *
 * The key id is an opaque `site-<random>` string, stable for the life of the
 * install; `TAW_HUB_SITE_KEY_ID` overrides it. `rotate()` regenerates the
 * keypair but keeps the key id (ADR-0003 — `/keys/rotate` returns only the new
 * public key).
 *
 * The secret key sits in an autoload-off option. Hardening note: on a host
 * with a suitable secret available it could be sealed with
 * `sodium_crypto_secretbox`; left plain here to keep activation dependency-free
 * — key compromise is mitigated by `/keys/rotate`.
 */
final class SiteKeypair
{
    private const OPT_SECRET = 'taw_hub_companion_secret_key';
    private const OPT_PUBLIC = 'taw_hub_companion_public_key';
    private const OPT_KEY_ID = 'taw_hub_companion_key_id';

    /** @var array{secret: non-empty-string, public: non-empty-string}|null */
    private ?array $cache = null;

    public function __construct(private Config $config)
    {
    }

    public function ensureExists(): void
    {
        if (is_string(get_option(self::OPT_SECRET)) && is_string(get_option(self::OPT_PUBLIC))) {
            return;
        }
        $this->generate();
    }

    /**
     * @return string The new base64 public key.
     */
    public function rotate(): string
    {
        $this->generate(); // keeps OPT_KEY_ID

        return $this->publicKeyBase64();
    }

    public function publicKeyBase64(): string
    {
        return base64_encode($this->material()['public']);
    }

    public function keyId(): string
    {
        $override = $this->config->siteKeyIdOverride();
        if ($override !== null) {
            return $override;
        }

        $stored = get_option(self::OPT_KEY_ID);
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $generated = 'site-' . bin2hex(random_bytes(8));
        update_option(self::OPT_KEY_ID, $generated, false);

        return $generated;
    }

    public function sign(string $message): string
    {
        return Ed25519::sign($message, $this->material()['secret']);
    }

    public static function forget(): void
    {
        delete_option(self::OPT_SECRET);
        delete_option(self::OPT_PUBLIC);
        delete_option(self::OPT_KEY_ID);
    }

    /**
     * @return array{secret: non-empty-string, public: non-empty-string}
     */
    private function generate(): array
    {
        $pair   = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);

        update_option(self::OPT_SECRET, base64_encode($secret), false);
        update_option(self::OPT_PUBLIC, base64_encode($public), false);

        if (!is_string(get_option(self::OPT_KEY_ID))) {
            update_option(self::OPT_KEY_ID, 'site-' . bin2hex(random_bytes(8)), false);
        }

        return $this->cache = ['secret' => $secret, 'public' => $public];
    }

    /**
     * @return array{secret: non-empty-string, public: non-empty-string}
     */
    private function material(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $storedSecret = get_option(self::OPT_SECRET);
        $storedPublic = get_option(self::OPT_PUBLIC);
        $secret = is_string($storedSecret) ? base64_decode($storedSecret, true) : false;
        $public = is_string($storedPublic) ? base64_decode($storedPublic, true) : false;

        if ($secret === false || $public === false || $secret === '' || $public === '') {
            return $this->generate();
        }

        return $this->cache = ['secret' => $secret, 'public' => $public];
    }
}
