<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Http;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The rejection responses this plugin returns. ADR-0003: a failed verification
 * is `401 {"error":"unauthorized","reason":"<stable code>"}`. An unconfigured
 * plugin is `501 {"error":"not_configured", …}` (Part 6 spec).
 */
final class Rejection
{
    public static function unauthorized(string $reason): \WP_Error
    {
        return new \WP_Error(
            'taw_hub_unauthorized',
            'unauthorized',
            [
                'status' => 401,
                'reason' => $reason,
            ],
        );
    }

    public static function notConfigured(): \WP_Error
    {
        return new \WP_Error(
            'taw_hub_not_configured',
            'TAW Hub Companion is installed but not configured — define TAW_HUB_PUBLIC_KEY in wp-config.php.',
            ['status' => 501],
        );
    }

    public static function forbiddenIp(): \WP_Error
    {
        return new \WP_Error(
            'taw_hub_forbidden_ip',
            'forbidden',
            ['status' => 403, 'reason' => 'source_ip_not_allowed'],
        );
    }
}
