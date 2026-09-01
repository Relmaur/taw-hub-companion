<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit\Logs;

use TAW\HubCompanion\Logs\LogReader;
use TAW\HubCompanion\Tests\TestCase;

final class LogReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/taw-companion-logreader-' . getmypid() . '-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_returns_empty_when_taw_core_has_never_logged(): void
    {
        $this->assertSame([], (new LogReader($this->dir))->tail());
    }

    public function test_returns_empty_for_a_non_positive_limit(): void
    {
        $this->seed([['code' => 'a.b', 'message' => 'x', 'level' => 'info']]);

        $this->assertSame([], (new LogReader($this->dir))->tail(0));
    }

    public function test_tails_newest_entries_last(): void
    {
        $this->seed([
            ['code' => 't.a', 'message' => 'a', 'level' => 'info'],
            ['code' => 't.b', 'message' => 'b', 'level' => 'info'],
            ['code' => 't.c', 'message' => 'c', 'level' => 'info'],
        ]);

        $entries = (new LogReader($this->dir))->tail(2);

        $this->assertSame(['b', 'c'], array_column($entries, 'message'));
    }

    public function test_filters_by_level_code_prefix_and_since(): void
    {
        $this->seed([
            ['ts' => '2026-09-01T08:00:00+00:00', 'code' => 'form.email_delivery_failed', 'message' => 'a', 'level' => 'error'],
            ['ts' => '2026-09-01T09:00:00+00:00', 'code' => 'mail.emailit_send_failed', 'message' => 'b', 'level' => 'error'],
            ['ts' => '2026-09-01T10:00:00+00:00', 'code' => 'form.turnstile_request_failed', 'message' => 'c', 'level' => 'warning'],
            ['ts' => '2026-09-01T11:00:00+00:00', 'code' => 'form.email_delivery_failed', 'message' => 'd', 'level' => 'error'],
        ]);

        $entries = (new LogReader($this->dir))->tail(100, 'error', '2026-09-01T09:00:00+00:00', 'form.');

        $this->assertSame(['d'], array_column($entries, 'message'));
    }

    public function test_skips_malformed_lines(): void
    {
        file_put_contents(
            $this->dir . '/taw.log.jsonl',
            "{ broken\n" . json_encode(['ts' => 'x', 'code' => 'ok.ok', 'message' => 'ok', 'level' => 'info']) . "\n",
        );

        $this->assertCount(1, (new LogReader($this->dir))->tail());
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function seed(array $entries): void
    {
        $lines = '';
        foreach ($entries as $entry) {
            $lines .= json_encode($entry + ['ts' => '2026-09-01T00:00:00+00:00', 'context' => [], 'request_id' => 'r']) . "\n";
        }
        file_put_contents($this->dir . '/taw.log.jsonl', $lines);
    }
}
