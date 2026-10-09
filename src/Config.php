<?php

declare(strict_types=1);

namespace TAW\HubCompanion;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Everything the plugin needs, read from `wp-config.php` constants only —
 * never the options table (matches the taw/core `Cors.php` precedent). The
 * plugin is *inert* until {@see self::isConfigured()} — i.e. until
 * `TAW_HUB_PUBLIC_KEY` is defined.
 */
final class Config
{
    public const NAMESPACE = 'taw-hub/v1';

    public function isConfigured(): bool
    {
        return $this->hubPublicKey() !== null || $this->fleetKeys() !== [];
    }

    /**
     * Extra trusted Ed25519 keys, by key id: the fleet key(s) the active theme
     * ships in its `composer.json` (`extra.taw-companion.keys`, child theme
     * over parent), then the `taw_hub_companion_fleet_keys` filter. They work
     * next to `TAW_HUB_PUBLIC_KEY`, so a theme deploy can bring taw-fleet's key
     * with no wp-config edit. Invalid entries are skipped.
     *
     * @return array<string, string> key id => 32 raw bytes
     */
    public function fleetKeys(): array
    {
        $declared = [];
        if (function_exists('get_template_directory') && function_exists('get_stylesheet_directory')) {
            foreach (array_unique([get_template_directory(), get_stylesheet_directory()]) as $dir) {
                $declared = array_merge($declared, self::themeKeys((string) $dir));
            }
        }
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('taw_hub_companion_fleet_keys', $declared);
            $declared = is_array($filtered) ? $filtered : $declared;
        }

        $keys = [];
        foreach ($declared as $id => $b64) {
            $raw = self::decodeKey($b64, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
            if (is_string($id) && preg_match('/^[A-Za-z0-9_\-.:]{1,128}$/', $id) === 1 && $raw !== null) {
                $keys[$id] = $raw;
            }
        }

        return $keys;
    }

    /**
     * @return array<mixed> the `extra.taw-companion.keys` object of a theme's composer.json
     */
    private static function themeKeys(string $dir): array
    {
        $file = rtrim($dir, '/') . '/composer.json';
        if ($dir === '' || !is_readable($file)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($file), true);
        $extra = is_array($json) && is_array($json['extra'] ?? null) ? $json['extra'] : [];
        $ours = is_array($extra['taw-companion'] ?? null) ? $extra['taw-companion'] : [];

        return is_array($ours['keys'] ?? null) ? $ours['keys'] : [];
    }

    /**
     * Whether this copy runs as an mu-plugin: loaded from `mu-plugins/` or a
     * theme's `vendor/` (anything outside the plugins folder), or forced with
     * `TAW_COMPANION_MU`. WordPress can't update mu-plugins, so the
     * self-updater stays off; updates arrive with the theme.
     */
    public function muMode(): bool
    {
        if (self::constant('TAW_COMPANION_MU') === true) {
            return true;
        }
        $file = self::constant('TAW_HUB_COMPANION_FILE');
        $pluginDir = self::constant('WP_PLUGIN_DIR');
        if (!is_string($file) || !is_string($pluginDir) || $pluginDir === '') {
            return false;
        }
        $real = realpath($file) ?: $file;
        $plugins = realpath($pluginDir) ?: $pluginDir;

        return !str_starts_with($real, rtrim($plugins, '/') . '/');
    }

    /**
     * The Hub's Ed25519 public key, decoded to 32 raw bytes; null if unset/invalid.
     */
    public function hubPublicKey(): ?string
    {
        return self::decodeKey(self::constant('TAW_HUB_PUBLIC_KEY'), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
    }

    /**
     * Expected inbound `X-Taw-Hub-Key-Id` for the Ed25519 channel. Defaults to
     * `hub-local` — the Hub's own default key id (ADR-0005) — so a
     * defaults-only pairing works and the id is still checked.
     */
    public function hubKeyId(): string
    {
        return self::nonEmptyString(self::constant('TAW_HUB_KEY_ID')) ?? 'hub-local';
    }

    public function hmacSecret(): ?string
    {
        return self::nonEmptyString(self::constant('TAW_HUB_HMAC_SECRET'));
    }

    public function hmacKeyId(): ?string
    {
        return self::nonEmptyString(self::constant('TAW_HUB_HMAC_KEY_ID'));
    }

    /**
     * Optional override for this site's own response-signing key id.
     */
    public function siteKeyIdOverride(): ?string
    {
        return self::nonEmptyString(self::constant('TAW_HUB_SITE_KEY_ID'));
    }

    /**
     * Whether the plugin opts itself into WordPress's background auto-update
     * cron (via {@see \TAW\HubCompanion\Update\Updater}). On by default —
     * define `TAW_HUB_COMPANION_AUTO_UPDATE` as `false` in `wp-config.php` to
     * hold a site back to manual "Update available" / Hub-driven updates.
     */
    public function autoUpdateEnabled(): bool
    {
        $value = self::constant('TAW_HUB_COMPANION_AUTO_UPDATE');

        return !($value === false || $value === 0 || $value === '0' || $value === 'false');
    }

    /**
     * @return list<string> IP allow-list; empty → allow all source IPs.
     */
    public function allowedIps(): array
    {
        $raw = self::nonEmptyString(self::constant('TAW_HUB_ALLOWED_IPS'));
        if ($raw === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $ip): bool => $ip !== ''));
    }

    public function maxDriftSeconds(): int
    {
        return 60;
    }

    public function replayTtlSeconds(): int
    {
        return 150;
    }

    /**
     * The REST prefix the Hub assumes when signing (`/wp-json/`). If a filter
     * has changed `rest_get_url_prefix()`, signatures will not verify — a known
     * unsupported edge (surfaced as an admin notice by {@see Plugin}).
     */
    public function restPrefix(): string
    {
        return 'wp-json';
    }

    private static function constant(string $name): mixed
    {
        return defined($name) ? constant($name) : null;
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function decodeKey(mixed $value, int $expectedLength): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $raw = base64_decode(strtr($value, '-_', '+/'), true);

        return $raw !== false && strlen($raw) === $expectedLength ? $raw : null;
    }
}
