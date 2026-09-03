<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Rest;

use TAW\HubCompanion\Telemetry\InventoryReport;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `GET /taw-hub/v1/inventory` — a security-focused software bill of materials
 * (plugins, must-use plugins, drop-ins, themes) served to the Hub so it can
 * correlate the fleet against vulnerability feeds without SSH. Read-only,
 * subprocess-free, behind the same signature guard as every other route.
 */
final class InventoryController
{
    public function __construct(private InventoryReport $report)
    {
    }

    public function handle(): \WP_REST_Response
    {
        return new \WP_REST_Response($this->report->collect());
    }
}
