<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Rest;

use TAW\HubCompanion\Cli\TawRunner;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `POST /taw-hub/v1/framework/sync` — body `{"dry_run": bool}`.
 *
 * Runs `php bin/taw sync [--apply] --json` (does NOT reinvent the per-site
 * sync — it drives the same command `framework-sync.yml` uses) and returns
 * that JSON report verbatim:
 * `{"taw_core":{"installed","latest","behind"},"applied":[…],"tier2":[{"changed":bool},…]}`.
 */
final class FrameworkSyncController
{
    public function __construct(private TawRunner $runner)
    {
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $params = $request->get_json_params() ?: [];
        $dryRun = (bool) ($params['dry_run'] ?? false);

        $args   = $dryRun ? ['--json'] : ['--apply', '--json'];
        $result = $this->runner->run('sync', $args);

        $report = json_decode($result['stdout'], true);
        if (!is_array($report)) {
            return new \WP_REST_Response([
                'error'     => 'sync_report_unparseable',
                'exit_code' => $result['exit_code'],
                'stderr'    => $result['stderr'],
            ], 502);
        }

        return new \WP_REST_Response($report, $result['exit_code'] === 0 ? 200 : 502);
    }
}
