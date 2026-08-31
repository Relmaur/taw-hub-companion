<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit\Cli;

use Brain\Monkey\Functions;
use TAW\HubCompanion\Cli\CommandAllowlist;
use TAW\HubCompanion\Tests\TestCase;

final class CommandAllowlistTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('apply_filters')->returnArg(2);
    }

    public function test_read_mostly_commands_are_allowed(): void
    {
        $a = new CommandAllowlist();
        $this->assertTrue($a->allows('sync'));
        $this->assertTrue($a->allows('inspect'));
        $this->assertTrue($a->allows('seo:extract'));
    }

    public function test_dangerous_or_unknown_commands_are_denied(): void
    {
        $a = new CommandAllowlist();
        $this->assertFalse($a->allows('fields:set'));
        $this->assertFalse($a->allows('wp'));
        $this->assertFalse($a->allows(''));
        $this->assertFalse($a->allows('sync; rm -rf /'));
    }

    public function test_the_list_is_filterable(): void
    {
        Functions\when('apply_filters')->justReturn(['sync']);

        $a = new CommandAllowlist();
        $this->assertTrue($a->allows('sync'));
        $this->assertFalse($a->allows('inspect'));
    }

    public function test_a_non_array_filter_return_is_ignored(): void
    {
        Functions\when('apply_filters')->justReturn('nope');

        $this->assertTrue((new CommandAllowlist())->allows('sync'));
    }
}
