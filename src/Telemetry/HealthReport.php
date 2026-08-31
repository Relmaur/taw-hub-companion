<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Telemetry;

use TAW\HubCompanion\Keys\SiteKeypair;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The `GET /health` payload. Shape is fixed by the Hub's
 * `Data\HealthSnapshot::fromResponse` — only `ok` is required (missing → false);
 * the version fields are nullable strings ("" is coerced to null Hub-side).
 * `checked_at` / `response_ms` are set by the Hub — do not send them.
 *
 * `site_public_key` / `site_key_id` are a superset for registration
 * convenience (Part 6: "public key surfaced via /health for Hub registration").
 * COORDINATION (ADR-0005): confirm `HealthSnapshot` tolerates the extra keys.
 */
final class HealthReport
{
    public function __construct(private SiteKeypair $keypair)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        return [
            'ok'                => true,
            'php_version'       => PHP_VERSION,
            'wp_version'        => self::nullIfBlank(get_bloginfo('version')),
            'taw_core_version'  => self::tawCoreVersion(),
            'companion_version' => defined('TAW_HUB_COMPANION_VERSION')
                ? (string) constant('TAW_HUB_COMPANION_VERSION')
                : null,
            'site_public_key'   => $this->keypair->publicKeyBase64(),
            'site_key_id'       => $this->keypair->keyId(),
        ];
    }

    private static function tawCoreVersion(): ?string
    {
        if (
            class_exists(\Composer\InstalledVersions::class)
            && \Composer\InstalledVersions::isInstalled('taw/core')
        ) {
            $version = \Composer\InstalledVersions::getPrettyVersion('taw/core');

            return $version !== null ? ltrim($version, 'vV') : null;
        }

        return null;
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
