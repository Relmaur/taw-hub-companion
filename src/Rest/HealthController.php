<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Rest;

use TAW\HubCompanion\Telemetry\HealthReport;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `GET /taw-hub/v1/health` — environment + this site's public key for
 * registration. Read-only.
 */
final class HealthController
{
    public function __construct(private HealthReport $report)
    {
    }

    public function handle(): \WP_REST_Response
    {
        return new \WP_REST_Response($this->report->collect());
    }
}
