<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit\Telemetry;

use Brain\Monkey\Functions;
use TAW\HubCompanion\Config;
use TAW\HubCompanion\Keys\SiteKeypair;
use TAW\HubCompanion\Telemetry\HealthReport;
use TAW\HubCompanion\Tests\TestCase;

final class HealthReportTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->options = [];
        Functions\when('get_option')->alias(fn (string $k, $d = false) => $this->options[$k] ?? $d);
        Functions\when('update_option')->alias(function (string $k, $v): bool {
            $this->options[$k] = $v;

            return true;
        });
        Functions\when('get_bloginfo')->justReturn('7.1');
    }

    public function test_collects_the_expected_payload_shape(): void
    {
        $keypair = new SiteKeypair(new Config());
        $keypair->ensureExists();

        $payload = (new HealthReport($keypair))->collect();

        $this->assertTrue($payload['ok']);
        $this->assertSame(PHP_VERSION, $payload['php_version']);
        $this->assertSame('7.1', $payload['wp_version']);
        $this->assertIsString($payload['site_public_key']);
        $this->assertStringStartsWith('site-', (string) $payload['site_key_id']);
    }

    public function test_reports_exec_availability_as_a_bool(): void
    {
        $keypair = new SiteKeypair(new Config());
        $keypair->ensureExists();

        $payload = (new HealthReport($keypair))->collect();

        $this->assertArrayHasKey('exec_available', $payload);
        $this->assertIsBool($payload['exec_available']);
        // The test environment has proc_open.
        $this->assertSame(function_exists('proc_open'), $payload['exec_available']);
    }
}
