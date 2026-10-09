<?php

/**
 * Plugin Name: TAW Companion (loader)
 * Description: Loads the TAW companion that ships with the active TAW theme (vendor/taw/hub-companion), so it can't be deactivated by accident. Copied here by the theme's deploy; it never needs updating.
 *
 * It does nothing while the theme has no companion (yet), and while the
 * companion is still active as a regular plugin: two copies would mix their
 * classes. Remove the regular plugin and this one takes over on the next
 * request.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

(static function (): void {
    foreach ((array) get_option('active_plugins', []) as $plugin) {
        if (is_string($plugin) && str_starts_with($plugin, 'taw-hub-companion/')) {
            return; // the regular plugin still runs this site's companion
        }
    }
    $themes = (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content') . '/themes/';
    foreach (array_unique([(string) get_option('stylesheet'), (string) get_option('template')]) as $theme) {
        if ($theme === '' || str_contains($theme, '..') || str_contains($theme, '/')) {
            continue;
        }
        $file = $themes . $theme . '/vendor/taw/hub-companion/taw-hub-companion.php';
        if (is_readable($file)) {
            require_once $file;
            return;
        }
    }
})();
