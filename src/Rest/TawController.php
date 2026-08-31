<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Rest;

use TAW\HubCompanion\Cli\TawRunner;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `POST /taw-hub/v1/command` — body `{"command": string, "args": string[]}`.
 * Allow-listed (see {@see \TAW\HubCompanion\Cli\CommandAllowlist}); returns
 * `{"exit_code": int, "stdout": string, "stderr": string}`.
 *
 * Route is registered at `/taw` (ADR-0003 Q5); this class handles it.
 */
final class TawController
{
    public function __construct(private TawRunner $runner)
    {
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $params  = $request->get_json_params() ?: [];
        $command = is_string($params['command'] ?? null) ? $params['command'] : '';
        $args    = is_array($params['args'] ?? null)
            ? array_values(array_filter($params['args'], 'is_string'))
            : [];

        $result = $this->runner->run($command, $args);

        return new \WP_REST_Response($result, $result['exit_code'] === 0 ? 200 : 422);
    }
}
