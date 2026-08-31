<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Http;

use TAW\HubCompanion\Config;
use TAW\HubCompanion\Wire\SignatureGate;
use TAW\HubCompanion\Wire\SignatureHeaders;
use TAW\HubCompanion\Wire\VerificationException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The `permission_callback` for every `taw-hub/v1` route. Runs the ADR-0003
 * pipeline and returns `true` or a {@see Rejection} `WP_Error`.
 *
 * The signed path is reconstructed as `/<rest-prefix>/<namespace>/<route>` from
 * the matched REST route — NOT from `$_SERVER['REQUEST_URI']`, so it matches
 * what the Hub signs even for a WordPress install in a subdirectory.
 */
final class SignatureGuard
{
    public function __construct(
        private Config $config,
        private SignatureGate $gate,
    ) {
    }

    public static function signedPath(Config $config, string $route): string
    {
        return '/' . trim($config->restPrefix(), '/') . $route;
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @return true|\WP_Error
     */
    public function check(\WP_REST_Request $request): bool|\WP_Error
    {
        if (!$this->config->isConfigured()) {
            return Rejection::notConfigured();
        }

        if (!$this->sourceIpAllowed($request)) {
            return Rejection::forbiddenIp();
        }

        try {
            $headers = SignatureHeaders::fromLookup(
                static fn (string $name): string => (string) $request->get_header($name),
            );

            $this->gate->verify(
                (string) $request->get_method(),
                self::signedPath($this->config, (string) $request->get_route()),
                (string) $request->get_body(),
                $headers,
            );
        } catch (VerificationException $e) {
            return Rejection::unauthorized($e->reason());
        }

        return true;
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    private function sourceIpAllowed(\WP_REST_Request $request): bool
    {
        $allow = $this->config->allowedIps();
        if ($allow === []) {
            return true;
        }

        $remote = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '';

        return in_array($remote, $allow, true);
    }
}
