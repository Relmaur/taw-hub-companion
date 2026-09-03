<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit\Security;

use TAW\HubCompanion\Security\ScannerRegistry;
use TAW\HubCompanion\Security\SecurityScanner;
use TAW\HubCompanion\Security\VulnerabilityFinding;
use TAW\HubCompanion\Tests\TestCase;

final class ScannerRegistryTest extends TestCase
{
    public function test_returns_null_when_no_scanner_is_installed(): void
    {
        $registry = new ScannerRegistry(
            $this->scanner('defender', available: false),
            $this->scanner('wordfence', available: false),
        );

        $this->assertNull($registry->active());
    }

    public function test_returns_the_first_available_scanner_in_priority_order(): void
    {
        $registry = new ScannerRegistry(
            $this->scanner('defender', available: false),
            $this->scanner('wordfence', available: true),
        );

        $this->assertSame('wordfence', $registry->active()?->name());
    }

    public function test_priority_wins_when_more_than_one_is_available(): void
    {
        $registry = new ScannerRegistry(
            $this->scanner('defender', available: true),
            $this->scanner('wordfence', available: true),
        );

        $this->assertSame('defender', $registry->active()?->name());
    }

    private function scanner(string $name, bool $available): SecurityScanner
    {
        return new class ($name, $available) implements SecurityScanner {
            public function __construct(private string $name, private bool $available)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function version(): ?string
            {
                return null;
            }

            public function lastScanAt(): ?string
            {
                return null;
            }

            /** @return list<VulnerabilityFinding> */
            public function findings(): array
            {
                return [];
            }
        };
    }
}
