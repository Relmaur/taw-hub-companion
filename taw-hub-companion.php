<?php

/**
 * Plugin Name:       TAW Hub Companion
 * Description:        Signed wp-json/taw-hub/v1 receiver for the TAW Hub control hub. No passwords — every request is verified against the Hub's Ed25519 key per taw-hub ADR-0003.
 * Version:           0.1.1
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Marco Del Riego
 * License:           GPL-2.0-or-later
 * Text Domain:       taw-hub-companion
 *
 * Configuration is read from wp-config.php constants only (never the options
 * table), matching the taw/core `Cors.php` precedent:
 *
 *   define('TAW_HUB_PUBLIC_KEY', '…base64 Ed25519 public key from the Hub…');  // required
 *   define('TAW_HUB_KEY_ID',     'hub-prod');                                  // optional — expected inbound key id
 *   define('TAW_HUB_HMAC_SECRET','…');                                         // optional — the HMAC (n8n) channel
 *   define('TAW_HUB_ALLOWED_IPS','203.0.113.4, 203.0.113.5');                  // optional — IP allow-list
 *   define('TAW_HUB_SITE_KEY_ID','site-abc123');                               // optional — override the site's own key id
 *
 * With no TAW_HUB_PUBLIC_KEY the plugin is inert: routes return 501 and an
 * admin notice explains what to define.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'TAW\\HubCompanion\\')) {
            return;
        }
        $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen('TAW\\HubCompanion\\'))) . '.php';
        if (is_readable($path)) {
            require $path;
        }
    });
}

define('TAW_HUB_COMPANION_VERSION', '0.1.1');
define('TAW_HUB_COMPANION_FILE', __FILE__);

register_activation_hook(__FILE__, [\TAW\HubCompanion\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\TAW\HubCompanion\Plugin::class, 'deactivate']);

add_action('plugins_loaded', [\TAW\HubCompanion\Plugin::class, 'boot']);
