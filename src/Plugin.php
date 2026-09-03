<?php

declare(strict_types=1);

namespace TAW\HubCompanion;

use TAW\HubCompanion\Cli\CommandAllowlist;
use TAW\HubCompanion\Cli\TawRunner;
use TAW\HubCompanion\Http\ResponseSigning;
use TAW\HubCompanion\Http\SignatureGuard;
use TAW\HubCompanion\Keys\SiteKeypair;
use TAW\HubCompanion\Rest\ChecksumsController;
use TAW\HubCompanion\Rest\FrameworkSyncController;
use TAW\HubCompanion\Logs\LogReader;
use TAW\HubCompanion\Rest\HealthController;
use TAW\HubCompanion\Rest\InventoryController;
use TAW\HubCompanion\Rest\KeysController;
use TAW\HubCompanion\Rest\LogsController;
use TAW\HubCompanion\Rest\Routes;
use TAW\HubCompanion\Rest\TawController;
use TAW\HubCompanion\Rest\VulnerabilitiesController;
use TAW\HubCompanion\Security\ScannerRegistry;
use TAW\HubCompanion\Telemetry\ChecksumReport;
use TAW\HubCompanion\Telemetry\HealthReport;
use TAW\HubCompanion\Telemetry\InventoryReport;
use TAW\HubCompanion\Wire\KeyRing;
use TAW\HubCompanion\Wire\ResponseSigner;
use TAW\HubCompanion\Wire\ReplayStore;
use TAW\HubCompanion\Wire\SignatureGate;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin composition root. Wires the ADR-0003 verification pipeline into the
 * REST API and signs every `taw-hub/v1` response.
 *
 * The routes are always registered — an unconfigured plugin returns `501`
 * (with an admin notice) rather than a bare `404`, so an operator can tell it
 * is installed-but-not-set-up.
 */
final class Plugin
{
    public static function activate(): void
    {
        (new SiteKeypair(new Config()))->ensureExists();
    }

    public static function deactivate(): void
    {
        // Keep the site keypair — deactivation/reactivation must not churn the
        // site's identity (that would silently break the Hub's registration).
    }

    public static function boot(): void
    {
        $config = new Config();

        add_action('admin_notices', [self::class, 'renderAdminNotices']);

        $keypair = new SiteKeypair($config);

        $gate = new SignatureGate(
            KeyRing::fromConfig($config),
            new ReplayStore($config->replayTtlSeconds()),
            $config->maxDriftSeconds(),
        );

        $guard  = new SignatureGuard($config, $gate);
        $runner = new TawRunner(new CommandAllowlist());

        $routes = new Routes(
            $guard,
            new HealthController(new HealthReport($keypair)),
            new InventoryController(new InventoryReport()),
            new ChecksumsController(new ChecksumReport()),
            new VulnerabilitiesController(ScannerRegistry::default()),
            new LogsController(LogReader::default()),
            new FrameworkSyncController($runner),
            new TawController($runner),
            new KeysController($keypair),
        );
        add_action('rest_api_init', [$routes, 'register']);

        $signing = new ResponseSigning($config, new ResponseSigner($keypair));
        add_filter('rest_post_dispatch', [$signing, 'filter'], 10, 3);
    }

    public static function renderAdminNotices(): void
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return;
        }

        $config = new Config();
        $messages = [];

        if (!$config->isConfigured()) {
            $messages[] = 'TAW Hub Companion is <strong>not configured</strong>. Define <code>TAW_HUB_PUBLIC_KEY</code> in <code>wp-config.php</code> — until then the <code>taw-hub/v1</code> routes return 501.';
        }

        if (function_exists('rest_get_url_prefix') && rest_get_url_prefix() !== $config->restPrefix()) {
            $messages[] = sprintf(
                'TAW Hub Companion: the REST URL prefix is <code>%s</code>, not <code>%s</code>. The Hub signs requests against <code>/%s/…</code> — a filtered prefix is a known unsupported configuration and signatures will fail.',
                esc_html(rest_get_url_prefix()),
                esc_html($config->restPrefix()),
                esc_html($config->restPrefix()),
            );
        }

        foreach ($messages as $message) {
            printf('<div class="notice notice-warning"><p>%s</p></div>', wp_kses($message, ['strong' => [], 'code' => []]));
        }
    }
}
