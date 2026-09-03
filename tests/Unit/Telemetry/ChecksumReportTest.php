<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit\Telemetry;

use Brain\Monkey\Functions;
use TAW\HubCompanion\Telemetry\ChecksumReport;
use TAW\HubCompanion\Tests\TestCase;

final class ChecksumReportTest extends TestCase
{
    private string $pluginDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Same path string as InventoryReportTest — WP_PLUGIN_DIR is a constant
        // and only the first definer wins, so both suites must agree on it.
        $this->pluginDir = sys_get_temp_dir() . '/taw-companion-inv-plugins';
        $this->rmrf($this->pluginDir);
        mkdir($this->pluginDir, 0777, true);

        if (!defined('WP_PLUGIN_DIR')) {
            define('WP_PLUGIN_DIR', $this->pluginDir);
        }

        Functions\when('is_plugin_active')->justReturn(true);
        Functions\when('get_plugins')->justReturn([]);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->pluginDir);
        parent::tearDown();
    }

    public function test_summary_mode_omits_the_file_map(): void
    {
        $this->plugin('acme', [
            'acme.php'        => "<?php // main\n",
            'inc/helper.php'  => "<?php return 1;\n",
            'assets/app.css'  => "body{}",
        ]);

        $payload = (new ChecksumReport())->collect();

        $this->assertSame('summary', $payload['mode']);
        $this->assertCount(1, $payload['components']);

        $acme = $payload['components'][0];
        $this->assertSame('plugin', $acme['type']);
        $this->assertSame('acme', $acme['slug']);
        $this->assertSame(2, $acme['file_count']); // the .css is skipped
        $this->assertFalse($acme['truncated']);
        $this->assertFalse($acme['missing']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $acme['tree_hash']);
        $this->assertArrayNotHasKey('files', $acme);
    }

    public function test_detail_mode_includes_hashes_keyed_by_relative_path(): void
    {
        $this->plugin('acme', [
            'acme.php'       => "<?php // main\n",
            'inc/helper.php' => "<?php return 1;\n",
        ]);

        $payload = (new ChecksumReport())->collect(slug: 'acme');

        $this->assertSame('detail', $payload['mode']);
        $files = $payload['components'][0]['files'];

        $this->assertSame(
            ['acme.php', 'inc/helper.php'],
            array_keys($files),
        );
        $this->assertSame(hash('sha256', "<?php // main\n"), $files['acme.php']);
    }

    public function test_tree_hash_is_stable_across_runs_and_moves_when_a_file_changes(): void
    {
        $this->plugin('acme', ['acme.php' => "<?php // v1\n"]);
        $first = (new ChecksumReport())->collect()['components'][0]['tree_hash'];

        $again = (new ChecksumReport())->collect()['components'][0]['tree_hash'];
        $this->assertSame($first, $again);

        file_put_contents($this->pluginDir . '/acme/acme.php', "<?php // v2 with a backdoor\n");
        $changed = (new ChecksumReport())->collect()['components'][0]['tree_hash'];
        $this->assertNotSame($first, $changed);
    }

    public function test_skips_the_node_modules_and_vcs_trees(): void
    {
        $this->plugin('acme', [
            'acme.php'                  => "<?php\n",
            'node_modules/lib/index.js' => "console.log(1)",
            '.git/hooks/pre-commit'     => "#!/bin/sh",
        ]);

        $files = (new ChecksumReport())->collect(slug: 'acme')['components'][0]['files'];

        $this->assertSame(['acme.php'], array_keys($files));
    }

    public function test_hashes_extension_less_files(): void
    {
        $this->plugin('acme', [
            'acme.php'   => "<?php\n",
            'bin/runner' => "#!/usr/bin/env php\n<?php\n",
        ]);

        $files = (new ChecksumReport())->collect(slug: 'acme')['components'][0]['files'];

        $this->assertArrayHasKey('bin/runner', $files);
    }

    public function test_reports_a_missing_component_directory(): void
    {
        Functions\when('get_plugins')->justReturn([
            'ghost/ghost.php' => ['Version' => '1.0'],
        ]);

        $ghost = (new ChecksumReport())->collect(slug: 'ghost')['components'][0];

        $this->assertTrue($ghost['missing']);
        $this->assertSame(0, $ghost['file_count']);
    }

    public function test_only_active_plugins_in_summary_mode(): void
    {
        $this->plugin('active-one', ['a.php' => "<?php\n"]);
        $this->plugin('inactive-one', ['b.php' => "<?php\n"]);

        Functions\when('is_plugin_active')->alias(
            static fn (string $file): bool => str_starts_with($file, 'active-one/'),
        );

        $slugs = array_column((new ChecksumReport())->collect()['components'], 'slug');

        $this->assertSame(['active-one'], $slugs);
    }

    /**
     * @param array<string, string> $files relpath => contents
     */
    private function plugin(string $slug, array $files): void
    {
        foreach ($files as $rel => $contents) {
            $path = $this->pluginDir . '/' . $slug . '/' . $rel;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $contents);
        }

        $registered = Functions\when('get_plugins');
        $all = [];
        foreach ((array) glob($this->pluginDir . '/*', GLOB_ONLYDIR) as $dir) {
            $name = basename((string) $dir);
            $entry = is_file($dir . '/' . $name . '.php') ? $name . '.php' : ($this->firstPhp((string) $dir) ?? $name . '.php');
            $all[$name . '/' . $entry] = ['Version' => '1.0'];
        }
        $registered->justReturn($all);
    }

    private function firstPhp(string $dir): ?string
    {
        foreach ((array) glob($dir . '/*.php') as $php) {
            return basename((string) $php);
        }

        return null;
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
