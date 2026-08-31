<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Http;

use TAW\HubCompanion\Config;
use TAW\HubCompanion\Wire\ResponseSigner;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `rest_post_dispatch` filter that adds the `X-Taw-Hub-*` response-signature
 * headers to every `taw-hub/v1` response, so the Hub's `HttpCompanionClient`
 * can verify it (it hard-fails on a missing/bad response signature).
 *
 * The signed body is `wp_json_encode($response->get_data())` — the same
 * function WordPress uses to serialise the response. COORDINATION (ADR-0005):
 * the Hub must hash the exact response bytes it receives; if WP's serialiser
 * and the Hub's expectation ever diverge (slash/unicode escaping), that's the
 * first place to look. The cross-implementation vector check covers this.
 */
final class ResponseSigning
{
    public function __construct(
        private Config $config,
        private ResponseSigner $signer,
    ) {
    }

    /**
     * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response
     * @param \WP_REST_Request<array<string, mixed>>             $request
     * @return \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed
     */
    public function filter(mixed $response, mixed $server, \WP_REST_Request $request): mixed
    {
        if (!$response instanceof \WP_REST_Response) {
            return $response;
        }

        $route = (string) $request->get_route();
        if (!str_starts_with($route, '/' . Config::NAMESPACE . '/')) {
            return $response;
        }

        $signedPath = SignatureGuard::signedPath($this->config, $route);
        $body       = (string) wp_json_encode($response->get_data());

        foreach ($this->signer->headersFor($signedPath, $body) as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }
}
