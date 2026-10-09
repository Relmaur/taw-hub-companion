<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Rest;

use TAW\HubCompanion\Telemetry\ContentReport;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `GET /taw-hub/v1/content` — the site's published content as a taw/core
 * Content Interchange snapshot, for taw-fleet to pull into a local copy.
 *
 * Query params (optional):
 *   types   string  comma-separated post types, e.g. "page,post"
 *
 * Returns the snapshot as `TAW\Core\Content\Exporter::snapshot()` makes it
 * (schema `content-interchange-1.x`), or 501 `content_unavailable` when
 * taw/core's Content Interchange isn't loaded. Read-only, behind the same
 * signature guard as every other route.
 */
final class ContentController
{
    public function __construct(private ContentReport $report)
    {
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!ContentReport::available()) {
            return new \WP_REST_Response([
                'error'   => 'content_unavailable',
                'message' => "taw/core's Content Interchange (v1.25+) isn't loaded on this site.",
            ], 501);
        }

        $types = $request->get_param('types');
        $types = is_string($types) && $types !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $types)), static fn (string $t): bool => $t !== ''))
            : [];

        try {
            return new \WP_REST_Response($this->report->snapshot($types));
        } catch (\Throwable) {
            return new \WP_REST_Response(['error' => 'internal_error'], 500);
        }
    }
}
