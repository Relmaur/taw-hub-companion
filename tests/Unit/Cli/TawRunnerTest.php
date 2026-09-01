<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit\Cli;

use Brain\Monkey\Functions;
use TAW\HubCompanion\Cli\CommandAllowlist;
use TAW\HubCompanion\Cli\TawRunner;
use TAW\HubCompanion\Tests\TestCase;

final class TawRunnerTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('apply_filters')->returnArg(2);

        $this->tmp = sys_get_temp_dir() . '/taw-runner-' . getmypid() . '-' . uniqid();
        mkdir($this->tmp . '/bin', 0777, true);
        file_put_contents($this->tmp . '/bin/taw', <<<'PHP'
        <?php
        // Test stub for bin/taw.
        if (($argv[2] ?? '') === '--exit-3') {
            fwrite(STDERR, 'stub failure');
            exit(3);
        }
        fwrite(STDOUT, json_encode(['ok' => true, 'argv' => array_slice($argv, 1)]));
        exit(0);
        PHP);

        Functions\when('get_template_directory')->justReturn($this->tmp);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp . '/bin/taw');
        @rmdir($this->tmp . '/bin');
        @rmdir($this->tmp);
        parent::tearDown();
    }

    public function test_runs_an_allowed_command_and_captures_stdout(): void
    {
        $result = (new TawRunner(new CommandAllowlist()))->run('sync', ['--json']);

        $this->assertSame(0, $result['exit_code']);
        $decoded = json_decode($result['stdout'], true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['ok']);
        $this->assertSame(['sync', '--json'], $decoded['argv']);
    }

    public function test_reports_the_real_nonzero_exit_code(): void
    {
        // Regression: proc_close() returns -1 after proc_get_status() has
        // reaped the process — the code must come from proc_get_status().
        $result = (new TawRunner(new CommandAllowlist()))->run('sync', ['--exit-3']);

        $this->assertSame(3, $result['exit_code']);
        $this->assertStringContainsString('stub failure', $result['stderr']);
    }

    public function test_rejects_a_command_not_on_the_allowlist(): void
    {
        $result = (new TawRunner(new CommandAllowlist()))->run('fields:set', ['1', 'x']);

        $this->assertSame(126, $result['exit_code']);
        $this->assertStringContainsString('not allowed', $result['stderr']);
    }

    public function test_returns_127_when_bin_taw_is_missing(): void
    {
        @unlink($this->tmp . '/bin/taw');

        $result = (new TawRunner(new CommandAllowlist()))->run('sync', []);

        $this->assertSame(127, $result['exit_code']);
    }

    public function test_php_binary_resolves_to_the_cli_interpreter_under_the_cli_sapi(): void
    {
        // PHPUnit runs under the `cli` SAPI, so PHP_BINARY is already correct.
        $resolved = $this->callPrivate(new TawRunner(new CommandAllowlist()), 'phpBinary');

        $this->assertSame(PHP_BINARY, $resolved);
    }

    public function test_php_binary_filter_override_wins_when_executable(): void
    {
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value) => $hook === 'taw_hub_companion_php_binary' ? PHP_BINARY : $value,
        );

        $resolved = $this->callPrivate(new TawRunner(new CommandAllowlist()), 'phpBinary');

        $this->assertSame(PHP_BINARY, $resolved);
    }

    private function callPrivate(object $object, string $method): mixed
    {
        $ref = new \ReflectionMethod($object, $method);
        $ref->setAccessible(true);

        return $ref->invoke($object);
    }
}
