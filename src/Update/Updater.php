<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Update;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub-Releases self-updater for the plugin — the companion's answer to
 * "how do I keep this current on N fleet sites without SSHing into each one".
 *
 * It feeds WordPress's own plugin-update transient from
 * `Relmaur/taw-hub-companion`'s latest GitHub release (a CI-built
 * `taw-hub-companion-<version>.zip` asset), so every site shows the standard
 * "Update available" row and `wp plugin update taw-hub-companion` works. When
 * {@see \TAW\HubCompanion\Config::autoUpdateEnabled()} is true (the default —
 * set `TAW_HUB_COMPANION_AUTO_UPDATE` to `false` in `wp-config.php` to opt a
 * site out) it also opts itself into WordPress's background auto-update cron,
 * so a release rolls out fleet-wide within a cron cycle with no operator action.
 *
 * Deliberately self-contained — no `taw/core`, no Composer runtime deps
 * (same principle as {@see \TAW\HubCompanion\Logs\LogReader}). Mirrors
 * `taw/core`'s `TAW\Core\Theme\ThemeUpdater`.
 *
 * @phpstan-type Release array{version: string, download_url: string, homepage: string, changelog: string, published_at: string}
 */
final class Updater
{
    private const API_URL  = 'https://api.github.com/repos/Relmaur/taw-hub-companion/releases/latest';
    private const CACHE_KEY = 'taw_hub_companion_update';
    private const CACHE_TTL = 43200;  // 12h — GitHub's unauthenticated API is 60 req/h/IP
    private const MISS_TTL  = 1800;   // 30m negative cache on a fetch/parse failure

    public function __construct(
        private string $pluginFile,
        private string $currentVersion,
        private bool $autoUpdate,
    ) {
    }

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'injectUpdate']);
        add_filter('plugins_api', [$this, 'pluginInfo'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'normalizeSourceDir'], 10, 4);

        if ($this->autoUpdate) {
            add_filter('auto_update_plugin', [$this, 'enableAutoUpdate'], 10, 2);
        }

        add_action('upgrader_process_complete', [$this, 'onUpgradeComplete'], 10, 2);
    }

    /** `taw-hub-companion/taw-hub-companion.php` */
    public function basename(): string
    {
        return plugin_basename($this->pluginFile);
    }

    /** `taw-hub-companion` */
    public function slug(): string
    {
        return dirname($this->basename());
    }

    /**
     * @param mixed $transient The `update_plugins` site transient (a stdClass).
     * @return mixed
     */
    public function injectUpdate(mixed $transient): mixed
    {
        if (!$transient instanceof \stdClass) {
            return $transient;
        }

        $remote = $this->remoteRelease();
        if ($remote === null) {
            return $transient;
        }

        $key       = $this->basename();
        $hasUpdate = version_compare($this->currentVersion, $remote['version'], '<');
        $entry     = $this->formatFor($remote, $hasUpdate);

        $response = isset($transient->response) && is_array($transient->response) ? $transient->response : [];
        $noUpdate = isset($transient->no_update) && is_array($transient->no_update) ? $transient->no_update : [];

        if ($hasUpdate) {
            $response[$key] = $entry;
            unset($noUpdate[$key]);
        } else {
            $noUpdate[$key] = $entry;
            unset($response[$key]);
        }

        $transient->response  = $response;
        $transient->no_update = $noUpdate;

        return $transient;
    }

    /**
     * @param Release $remote
     */
    private function formatFor(array $remote, bool $hasUpdate): object
    {
        return (object) [
            'slug'         => $this->slug(),
            'plugin'       => $this->basename(),
            'new_version'  => $remote['version'],
            'url'          => $remote['homepage'],
            'package'      => $hasUpdate ? $remote['download_url'] : '',
            'icons'        => [],
            'banners'      => [],
            'banners_rtl'  => [],
            'requires'     => '6.4',
            'requires_php' => '8.2',
        ];
    }

    /**
     * @param mixed $result
     * @param mixed $args
     * @return mixed
     */
    public function pluginInfo(mixed $result, string $action, mixed $args): mixed
    {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (!is_object($args) || !isset($args->slug) || $args->slug !== $this->slug()) {
            return $result;
        }

        $remote = $this->remoteRelease();
        if ($remote === null) {
            return $result;
        }

        return (object) [
            'name'          => 'TAW Hub Companion',
            'slug'          => $this->slug(),
            'version'       => $remote['version'],
            'author'        => '<a href="https://mlizardo.com">Marco Del Riego</a>',
            'homepage'      => $remote['homepage'],
            'download_link' => $remote['download_url'],
            'requires'      => '6.4',
            'requires_php'  => '8.2',
            'last_updated'  => $remote['published_at'],
            'sections'      => [
                'description' => 'Signed <code>wp-json/taw-hub/v1</code> receiver for the TAW Hub control hub. Every request is verified against the Hub\'s Ed25519 key (taw-hub ADR-0003).',
                'changelog'   => $this->changelogHtml($remote['changelog']),
            ],
        ];
    }

    /**
     * GitHub's source zipball unpacks to `Relmaur-taw-hub-companion-<sha>/`,
     * not `taw-hub-companion/` — rename it so WordPress upgrades the existing
     * plugin folder in place instead of installing a second copy beside it.
     * A no-op for the CI-built asset zip (already `taw-hub-companion/`) and for
     * every other plugin's upgrade.
     *
     * @param mixed $source
     * @param mixed $remoteSource
     * @param mixed $upgrader
     * @param mixed $hookExtra
     * @return mixed
     */
    public function normalizeSourceDir(mixed $source, mixed $remoteSource, mixed $upgrader = null, mixed $hookExtra = null): mixed
    {
        if (!is_string($source) || !is_string($remoteSource)) {
            return $source;
        }

        $plugin = is_array($hookExtra) && isset($hookExtra['plugin']) ? $hookExtra['plugin'] : '';
        if ($plugin !== $this->basename()) {
            return $source;
        }

        $desired = trailingslashit($remoteSource) . $this->slug() . '/';
        if (untrailingslashit($source) === untrailingslashit($desired)) {
            return $source;
        }

        global $wp_filesystem;
        if ($wp_filesystem instanceof \WP_Filesystem_Base && $wp_filesystem->move($source, $desired, true)) {
            return $desired;
        }

        return $source;
    }

    /**
     * @param mixed $update Current decision (bool|null).
     * @param mixed $item   The update offer object.
     * @return mixed
     */
    public function enableAutoUpdate(mixed $update, mixed $item): mixed
    {
        $plugin = is_object($item) && isset($item->plugin) ? $item->plugin : '';

        return $plugin === $this->basename() ? true : $update;
    }

    /**
     * @param mixed $upgrader
     * @param mixed $data
     */
    public function onUpgradeComplete(mixed $upgrader, mixed $data): void
    {
        if (!is_array($data) || ($data['type'] ?? '') !== 'plugin') {
            return;
        }
        $plugins = isset($data['plugins']) && is_array($data['plugins']) ? $data['plugins'] : [];
        if (in_array($this->basename(), $plugins, true)) {
            delete_transient(self::CACHE_KEY);
        }
    }

    public function flushCache(): void
    {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * @return Release|null
     */
    private function remoteRelease(): ?array
    {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            if ($cached === [] || !isset($cached['version'], $cached['download_url'])) {
                return null;
            }

            /** @var array<string, mixed> $cached */
            return self::coerce($cached);
        }

        $response = wp_remote_get(self::API_URL, [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/vnd.github+json'],
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            set_transient(self::CACHE_KEY, [], self::MISS_TTL);

            return null;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        $parsed = null;
        if (is_array($decoded)) {
            /** @var array<string, mixed> $decoded */
            $parsed = self::parseRelease($decoded);
        }

        set_transient(self::CACHE_KEY, $parsed ?? [], $parsed !== null ? self::CACHE_TTL : self::MISS_TTL);

        return $parsed;
    }

    /**
     * Turn GitHub's `releases/latest` JSON into the shape the rest of this
     * class uses, or null if it is not a usable stable release. Pure.
     *
     * @param array<string, mixed> $release
     * @return Release|null
     */
    public static function parseRelease(array $release): ?array
    {
        $tagRaw = $release['tag_name'] ?? null;
        $tag    = is_string($tagRaw) ? trim($tagRaw) : '';
        if ($tag === '' || ($release['draft'] ?? false) === true || ($release['prerelease'] ?? false) === true) {
            return null;
        }

        $downloadUrl = '';
        $assets      = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $url  = $asset['browser_download_url'] ?? null;
            $name = $asset['name'] ?? null;
            if (is_string($url) && is_string($name) && str_ends_with(strtolower($name), '.zip')) {
                $downloadUrl = $url;
                break;
            }
        }
        if ($downloadUrl === '' && isset($release['zipball_url']) && is_string($release['zipball_url'])) {
            $downloadUrl = $release['zipball_url'];
        }
        if ($downloadUrl === '') {
            return null;
        }

        $htmlUrl = $release['html_url'] ?? null;
        $body    = $release['body'] ?? null;
        $pubAt   = $release['published_at'] ?? null;

        return [
            'version'      => ltrim($tag, 'vV'),
            'download_url' => $downloadUrl,
            'homepage'     => is_string($htmlUrl) && $htmlUrl !== '' ? $htmlUrl : 'https://github.com/Relmaur/taw-hub-companion',
            'changelog'    => is_string($body) ? $body : '',
            'published_at' => is_string($pubAt) ? $pubAt : '',
        ];
    }

    /**
     * @param array<string, mixed> $cached
     * @return Release
     */
    private static function coerce(array $cached): array
    {
        return [
            'version'      => is_string($cached['version'] ?? null) ? $cached['version'] : '0.0.0',
            'download_url' => is_string($cached['download_url'] ?? null) ? $cached['download_url'] : '',
            'homepage'     => is_string($cached['homepage'] ?? null) ? $cached['homepage'] : 'https://github.com/Relmaur/taw-hub-companion',
            'changelog'    => is_string($cached['changelog'] ?? null) ? $cached['changelog'] : '',
            'published_at' => is_string($cached['published_at'] ?? null) ? $cached['published_at'] : '',
        ];
    }

    private function changelogHtml(string $markdownBody): string
    {
        $body = trim($markdownBody);
        if ($body === '') {
            return 'See the <a href="https://github.com/Relmaur/taw-hub-companion/releases">GitHub releases</a>.';
        }

        return '<pre style="white-space:pre-wrap">' . esc_html($body) . '</pre>';
    }
}
