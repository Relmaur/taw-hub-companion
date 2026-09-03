<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Rest;

use TAW\HubCompanion\Telemetry\ChecksumReport;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `GET /taw-hub/v1/inventory/checksums` — a per-component SHA-256 file manifest
 * for file-integrity monitoring and version-to-version diffing on the Hub.
 *
 *   ?slug=<slug>            one component, with its full `files` map (detail mode)
 *   ?type=plugin|mu_plugin|theme   narrow to one component kind
 *
 * With no `slug` the response is summary mode: `tree_hash` + `file_count` per
 * active component, no `files` map — a cheap fleet-wide "did anything change"
 * poll. Reads the filesystem; never spawns a subprocess. Behind the same
 * signature guard as every other route.
 */
final class ChecksumsController
{
    public function __construct(private ChecksumReport $report)
    {
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $slug = $request->get_param('slug');
        $slug = is_string($slug) && $slug !== '' ? $slug : null;

        $type = $request->get_param('type');
        $type = is_string($type) && $type !== '' ? $type : null;

        return new \WP_REST_Response($this->report->collect($slug, $type));
    }
}
