<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Holds the known scanner adapters in priority order and returns the first one
 * that is actually installed on this site. Priority matters only when a site
 * runs more than one scanner: the fleet standard (Defender Pro) is listed
 * before the fallback (Wordfence).
 *
 * Filter `taw_hub_companion_security_scanners` to add or reorder adapters.
 */
final class ScannerRegistry
{
    /** @var list<SecurityScanner> */
    private array $scanners;

    public function __construct(SecurityScanner ...$scanners)
    {
        $this->scanners = array_values($scanners);
    }

    public static function default(): self
    {
        $db = WpdbDatabase::fromGlobals();

        $scanners = [
            // A DefenderScanner adapter slots in here first, once built.
            new WordfenceScanner($db),
        ];

        if (function_exists('apply_filters')) {
            /** @var mixed $filtered */
            $filtered = apply_filters('taw_hub_companion_security_scanners', $scanners, $db);
            if (is_array($filtered)) {
                $scanners = array_values(array_filter(
                    $filtered,
                    static fn (mixed $s): bool => $s instanceof SecurityScanner,
                ));
            }
        }

        return new self(...$scanners);
    }

    public function active(): ?SecurityScanner
    {
        foreach ($this->scanners as $scanner) {
            if ($scanner->isAvailable()) {
                return $scanner;
            }
        }

        return null;
    }
}
