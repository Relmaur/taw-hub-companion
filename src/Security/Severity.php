<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maps a CVSS base score to the coarse label {@see VulnerabilityFinding} uses.
 * Standard CVSS v3 severity bands.
 */
final class Severity
{
    public static function fromCvss(float $score): string
    {
        return match (true) {
            $score >= 9.0 => 'critical',
            $score >= 7.0 => 'high',
            $score >= 4.0 => 'medium',
            $score > 0.0  => 'low',
            default       => 'unknown',
        };
    }
}
