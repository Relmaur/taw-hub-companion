<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Rest;

use TAW\HubCompanion\Config;
use TAW\HubCompanion\Http\SignatureGuard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the `taw-hub/v1` routes (ADR-0003 Q5). Every route uses the same
 * {@see SignatureGuard} as its `permission_callback`.
 *
 *   GET  /health
 *   POST /framework/sync   {"dry_run": bool}
 *   POST /taw              {"command": string, "args": string[]}
 *   POST /keys/rotate
 */
final class Routes
{
    public function __construct(
        private SignatureGuard $guard,
        private HealthController $health,
        private FrameworkSyncController $frameworkSync,
        private TawController $taw,
        private KeysController $keys,
    ) {
    }

    public function register(): void
    {
        $ns    = Config::NAMESPACE;
        $guard = [$this->guard, 'check'];

        register_rest_route($ns, '/health', [
            'methods'             => 'GET',
            'callback'            => [$this->health, 'handle'],
            'permission_callback' => $guard,
        ]);

        register_rest_route($ns, '/framework/sync', [
            'methods'             => 'POST',
            'callback'            => [$this->frameworkSync, 'handle'],
            'permission_callback' => $guard,
        ]);

        register_rest_route($ns, '/taw', [
            'methods'             => 'POST',
            'callback'            => [$this->taw, 'handle'],
            'permission_callback' => $guard,
        ]);

        register_rest_route($ns, '/keys/rotate', [
            'methods'             => 'POST',
            'callback'            => [$this->keys, 'handle'],
            'permission_callback' => $guard,
        ]);
    }
}
