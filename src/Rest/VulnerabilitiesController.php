<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Rest;

use TAW\HubCompanion\Security\ScannerRegistry;
use TAW\HubCompanion\Security\VulnerabilityFinding;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `GET /taw-hub/v1/vulnerabilities` — the security findings the site's own
 * scanner (Defender Pro, Wordfence, …) has already computed, normalized and
 * served to the Hub so it can aggregate them across the fleet without doing its
 * own vulnerability matching.
 *
 * Read-only, DB-read-only, behind the same signature guard as every other
 * route. `scanner: null` means no supported scanner is installed;
 * `scanner.last_scan_at: null` means one is installed but has never scanned.
 */
final class VulnerabilitiesController
{
    public function __construct(private ScannerRegistry $registry)
    {
    }

    public function handle(): \WP_REST_Response
    {
        $scanner = $this->registry->active();

        if ($scanner === null) {
            return new \WP_REST_Response([
                'scanner'  => null,
                'count'    => 0,
                'findings' => [],
            ]);
        }

        $findings = array_map(
            static fn (VulnerabilityFinding $finding): array => $finding->toArray(),
            $scanner->findings(),
        );

        return new \WP_REST_Response([
            'scanner' => [
                'name'         => $scanner->name(),
                'version'      => $scanner->version(),
                'last_scan_at' => $scanner->lastScanAt(),
            ],
            'count'    => count($findings),
            'findings' => $findings,
        ]);
    }
}
