<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A read adapter over one installed security scanner. Implementations never
 * trigger a scan or write anything — they read the scanner's stored results.
 * An implementation whose scanner is not installed returns `false` from
 * {@see self::isAvailable()} and is skipped by {@see ScannerRegistry}.
 */
interface SecurityScanner
{
    /** Stable lowercase identifier, e.g. `wordfence`. */
    public function name(): string;

    public function isAvailable(): bool;

    /** The scanner plugin's version, if it can be determined. */
    public function version(): ?string;

    /** When the scanner last completed a scan (ISO-8601), or null if never / unknown. */
    public function lastScanAt(): ?string;

    /**
     * @return list<VulnerabilityFinding>
     */
    public function findings(): array;
}
