<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Telemetry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The `GET /inventory` payload — a security-focused software bill of materials
 * for this WordPress install: every plugin, must-use plugin, drop-in and theme
 * with the metadata the Hub needs to
 *
 *   - correlate components against vulnerability feeds (WPScan / Patchstack),
 *   - spot abandoned components (`tested_up_to` vs core, `update_source`), and
 *   - flag pending updates (`update_version`).
 *
 * Read-only and subprocess-free — works on every host, like {@see HealthReport}
 * and the `/logs` route.
 *
 * Schema is coordinated with the Hub's `SiteFleet\Data\InventorySnapshot`
 * (taw-hub ADR-0013): flat root keys (matching `php_version` on `/health`),
 * snake_case, `update_version` holds the pending version string, `update_source`
 * values are underscore-form. `InventorySnapshot::fromResponse` is tolerant and
 * ignores unknown keys, so fields may be added here without a lock-step Hub
 * release; `schema_version` lets the Hub branch on companion evolution.
 */
final class InventoryReport
{
    /**
     * Bump when the payload shape changes in a way the Hub must know about.
     */
    private const SCHEMA_VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at'   => gmdate('c'),
            'wp_version'     => self::str(get_bloginfo('version')),
            'wp_locale'      => function_exists('get_locale') ? get_locale() : null,
            'wp_multisite'   => function_exists('is_multisite') && is_multisite(),
            'php_version'    => PHP_VERSION,
            'plugins'        => $this->plugins(),
            'mu_plugins'     => $this->muPlugins(),
            'dropins'        => $this->dropins(),
            'themes'         => $this->themes(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plugins(): array
    {
        self::ensurePluginApi();
        if (!function_exists('get_plugins')) {
            return [];
        }

        $updates    = $this->componentUpdates('update_plugins');
        $wpOrg      = $this->wpOrgManaged('update_plugins');
        $autoUpdate = $this->autoUpdateList('auto_update_plugins');

        $out = [];
        foreach (get_plugins() as $file => $data) {
            $file      = (string) $file;
            $updateUri = self::str($data['UpdateURI'] ?? null);

            $out[] = [
                'slug'           => self::slugFromFile($file), // folder-derived — unreliable for non-.org plugins
                'file'           => $file,                     // WordPress's canonical key; the Hub's dedup key
                'name'           => self::str($data['Name'] ?? null),
                'version'        => self::str($data['Version'] ?? null),
                'active'         => function_exists('is_plugin_active') && is_plugin_active($file),
                'network_active' => function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($file),
                'auto_update'    => in_array($file, $autoUpdate, true),
                'author'         => self::str(self::stripTags($data['Author'] ?? null)),
                'plugin_uri'     => self::str($data['PluginURI'] ?? null),
                'update_uri'     => $updateUri,
                'requires_wp'    => self::str($data['RequiresWP'] ?? null),
                'requires_php'   => self::str($data['RequiresPHP'] ?? null),
                'tested_up_to'   => $this->testedUpTo($file),
                'update_version' => $updates[$file] ?? null,
                'update_source'  => self::updateSource(isset($wpOrg[$file]), $updateUri),
                'main_file_mtime' => self::mtimeIso(self::pluginPath($file)),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function muPlugins(): array
    {
        self::ensurePluginApi();
        if (!function_exists('get_mu_plugins')) {
            return [];
        }

        $base = defined('WPMU_PLUGIN_DIR') ? rtrim((string) WPMU_PLUGIN_DIR, '/') : null;

        $out = [];
        foreach (get_mu_plugins() as $file => $data) {
            $file  = (string) $file;
            $out[] = [
                'file'            => $file,
                'name'            => self::str($data['Name'] ?? null),
                'version'         => self::str($data['Version'] ?? null),
                'author'          => self::str(self::stripTags($data['Author'] ?? null)),
                'main_file_mtime' => self::mtimeIso($base !== null ? $base . '/' . $file : null),
            ];
        }

        return $out;
    }

    /**
     * Present drop-ins (`object-cache.php`, `advanced-cache.php`, `db.php`,
     * `sunrise.php`, …). A drop-in the operator did not install is a classic
     * persistence trick, so the Hub wants the bare list.
     *
     * @return list<string>
     */
    private function dropins(): array
    {
        self::ensurePluginApi();
        if (!function_exists('get_dropins')) {
            return [];
        }

        return array_keys(get_dropins());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function themes(): array
    {
        if (!function_exists('wp_get_themes')) {
            return [];
        }

        $active     = function_exists('get_stylesheet') ? (string) get_stylesheet() : null;
        $template   = function_exists('get_template') ? (string) get_template() : null;
        $updates    = $this->componentUpdates('update_themes');
        $wpOrg      = $this->wpOrgManaged('update_themes');
        $autoUpdate = $this->autoUpdateList('auto_update_themes');

        $out = [];
        foreach (wp_get_themes() as $slug => $theme) {
            $slug  = (string) $slug;
            $out[] = [
                'slug'           => $slug,
                'name'           => self::themeField($theme, 'Name'),
                'version'        => self::themeField($theme, 'Version'),
                'active'         => $slug === $active,
                'parent_active'  => $slug === $template && $slug !== $active,
                'template'       => self::themeField($theme, 'Template'),
                'author'         => self::str(self::stripTags(self::themeField($theme, 'Author'))),
                'requires_wp'    => self::themeField($theme, 'RequiresWP'),
                'requires_php'   => self::themeField($theme, 'RequiresPHP'),
                'auto_update'    => in_array($slug, $autoUpdate, true),
                'update_version' => $updates[$slug] ?? null,
                'update_source'  => self::updateSource(isset($wpOrg[$slug]), null),
            ];
        }

        return $out;
    }

    /**
     * The pending-version map from an `update_{plugins,themes}` site transient:
     * component key => new version.
     *
     * @param 'update_plugins'|'update_themes' $transientName
     * @return array<string, string>
     */
    private function componentUpdates(string $transientName): array
    {
        $transient = function_exists('get_site_transient') ? get_site_transient($transientName) : null;
        if (!is_object($transient) || !isset($transient->response) || !is_array($transient->response)) {
            return [];
        }

        $out = [];
        foreach ($transient->response as $key => $info) {
            $new = is_object($info) ? ($info->new_version ?? null)
                : (is_array($info) ? ($info['new_version'] ?? null) : null);
            if (is_string($new) && $new !== '') {
                $out[(string) $key] = $new;
            }
        }

        return $out;
    }

    /**
     * Component keys WordPress is tracking against the .org repo — the union of
     * the `response` (update pending) and `no_update` (up to date) buckets of
     * the update transient. Absence from both means nothing is watching that
     * component for vulnerabilities on the site's behalf.
     *
     * @param 'update_plugins'|'update_themes' $transientName
     * @return array<string, true>
     */
    private function wpOrgManaged(string $transientName): array
    {
        $transient = function_exists('get_site_transient') ? get_site_transient($transientName) : null;
        if (!is_object($transient)) {
            return [];
        }

        $keys = [];
        foreach (['response', 'no_update'] as $bucket) {
            $entries = $transient->{$bucket} ?? null;
            if (is_array($entries)) {
                foreach (array_keys($entries) as $key) {
                    $keys[(string) $key] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * @param 'auto_update_plugins'|'auto_update_themes' $option
     * @return list<string>
     */
    private function autoUpdateList(string $option): array
    {
        if (!function_exists('get_option')) {
            return [];
        }

        $value = get_option($option, []);
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * `Tested up to:` from the plugin's readme.txt header, or null (single-file
     * plugins, private plugins, unreadable readme).
     */
    private function testedUpTo(string $file): ?string
    {
        if (!str_contains($file, '/') || !defined('WP_PLUGIN_DIR')) {
            return null;
        }

        $readme = rtrim((string) WP_PLUGIN_DIR, '/') . '/' . dirname($file) . '/readme.txt';
        if (!is_file($readme) || !is_readable($readme)) {
            return null;
        }

        $head = file_get_contents($readme, false, null, 0, 8192);
        if ($head === false) {
            return null;
        }

        return preg_match('/^[ \t]*Tested up to:[ \t]*(.+?)[ \t]*$/mi', $head, $m) === 1
            ? trim($m[1])
            : null;
    }

    /**
     * @param mixed $theme WP_Theme in WordPress, array in unit tests
     */
    private static function themeField(mixed $theme, string $key): ?string
    {
        if (is_object($theme) && method_exists($theme, 'get')) {
            /** @var mixed $value */
            $value = $theme->get($key);

            return is_string($value) && $value !== '' ? $value : null;
        }

        return is_array($theme) ? self::str($theme[$key] ?? null) : null;
    }

    private static function updateSource(bool $wpOrgManaged, ?string $updateUri): string
    {
        if ($wpOrgManaged) {
            return 'wordpress_org';
        }
        if ($updateUri === null) {
            return 'unknown';
        }

        return in_array(strtolower($updateUri), ['false', 'x'], true) ? 'disabled' : 'external';
    }

    private static function ensurePluginApi(): void
    {
        if (function_exists('get_plugins')) {
            return;
        }

        $file = ABSPATH . 'wp-admin/includes/plugin.php';
        if (is_readable($file)) {
            require_once $file;
        }
    }

    private static function pluginPath(string $file): ?string
    {
        return defined('WP_PLUGIN_DIR') ? rtrim((string) WP_PLUGIN_DIR, '/') . '/' . $file : null;
    }

    private static function mtimeIso(?string $path): ?string
    {
        if ($path === null || !is_file($path)) {
            return null;
        }

        $mtime = @filemtime($path);

        return $mtime !== false ? gmdate('c', $mtime) : null;
    }

    private static function slugFromFile(string $file): string
    {
        return str_contains($file, '/') ? explode('/', $file, 2)[0] : basename($file, '.php');
    }

    private static function stripTags(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return function_exists('wp_strip_all_tags') ? wp_strip_all_tags($value) : trim(strip_tags($value));
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
