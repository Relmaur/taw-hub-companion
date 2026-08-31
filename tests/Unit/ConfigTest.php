<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit;

use TAW\HubCompanion\Config;
use TAW\HubCompanion\Tests\TestCase;

final class ConfigTest extends TestCase
{
    public function test_not_configured_when_the_hub_public_key_constant_is_absent(): void
    {
        // TAW_HUB_PUBLIC_KEY is not defined in the test process.
        $this->assertFalse((new Config())->isConfigured());
        $this->assertNull((new Config())->hubPublicKey());
    }

    public function test_fixed_protocol_parameters(): void
    {
        $c = new Config();
        $this->assertSame('taw-hub/v1', Config::NAMESPACE);
        $this->assertSame(60, $c->maxDriftSeconds());
        $this->assertSame(150, $c->replayTtlSeconds());
        $this->assertSame('wp-json', $c->restPrefix());
        $this->assertSame([], $c->allowedIps());
    }
}
