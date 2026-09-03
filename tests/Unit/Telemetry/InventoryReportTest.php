<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit\Telemetry;

use Brain\Monkey\Functions;
use TAW\HubCompanion\Telemetry\InventoryReport;
use TAW\HubCompanion\Tests\TestCase;

final class InventoryReportTest extends TestCase
{
    private string $pluginDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pluginDir = sys_get_temp_dir() . '/taw-companion-inv-plugins';
        $this->rmrf($this->pluginDir);
        mkdir($this->pluginDir . '/akismet', 0777, true);

        if (!defined('WP_PLUGIN_DIR')) {
            define('WP_PLUGIN_DIR', $this->pluginDir);
        }

        Functions\when('get_bloginfo')->justReturn('6.6.1');
        Functions\when('get_locale')->justReturn('en_US');
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('wp_strip_all_tags')->alias(static fn (string $s): string => trim(strip_tags($s)));
        Functions\when('get_option')->alias(static fn (string $k, $d = false) => $d);
        Functions\when('is_plugin_active')->alias(static fn (string $f): bool => $f === 'akismet/akismet.php');
        Functions\when('is_plugin_active_for_network')->justReturn(false);

        // Defaults — once any test in the run mocks a WP function it exists
        // process-wide, so every test must give it a per-test expectation.
        Functions\when('get_site_transient')->justReturn(false);
        Functions\when('get_mu_plugins')->justReturn([]);
        Functions\when('get_dropins')->justReturn([]);
        Functions\when('wp_get_themes')->justReturn([]);
        Functions\when('get_stylesheet')->justReturn('');
        Functions\when('get_template')->justReturn('');
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->pluginDir);
        parent::tearDown();
    }

    public function test_reports_the_wp_and_php_environment(): void
    {
        $this->stubPlugins([]);

        $payload = (new InventoryReport())->collect();

        $this->assertSame(1, $payload['schema_version']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $payload['generated_at']);
        $this->assertSame('6.6.1', $payload['wp_version']);
        $this->assertSame('en_US', $payload['wp_locale']);
        $this->assertFalse($payload['wp_multisite']);
        $this->assertSame(PHP_VERSION, $payload['php_version']);
        $this->assertSame([], $payload['plugins']);
    }

    public function test_maps_plugin_headers_active_state_and_slug(): void
    {
        file_put_contents(
            $this->pluginDir . '/akismet/readme.txt',
            "=== Akismet ===\nTested up to: 6.5\nStable tag: 5.3\n",
        );
        file_put_contents($this->pluginDir . '/akismet/akismet.php', "<?php\n");
        $this->stubPlugins([
            'akismet/akismet.php' => [
                'Name'        => 'Akismet Anti-spam',
                'Version'     => '5.3',
                'Author'      => '<a href="https://automattic.com">Automattic</a>',
                'PluginURI'   => 'https://akismet.com/',
                'UpdateURI'   => '',
                'RequiresWP'  => '5.8',
                'RequiresPHP' => '5.6.20',
            ],
            'hello.php' => [
                'Name'    => 'Hello Dolly',
                'Version' => '1.7.2',
            ],
        ]);
        $this->stubUpdateTransient(response: ['akismet/akismet.php' => ['new_version' => '5.3.1']]);

        $plugins = (new InventoryReport())->collect()['plugins'];

        $this->assertCount(2, $plugins);

        $akismet = $this->byFile($plugins, 'akismet/akismet.php');
        $this->assertSame('akismet', $akismet['slug']);
        $this->assertSame('Akismet Anti-spam', $akismet['name']);
        $this->assertSame('5.3', $akismet['version']);
        $this->assertTrue($akismet['active']);
        $this->assertSame('Automattic', $akismet['author']);
        $this->assertSame('6.5', $akismet['tested_up_to']);
        $this->assertSame('5.3.1', $akismet['update_version']);
        $this->assertSame('wordpress_org', $akismet['update_source']);
        $this->assertIsString($akismet['main_file_mtime']);

        $hello = $this->byFile($plugins, 'hello.php');
        $this->assertSame('hello', $hello['slug']);
        $this->assertFalse($hello['active']);
        $this->assertNull($hello['tested_up_to']);
        $this->assertNull($hello['update_version']);
    }

    public function test_update_source_reflects_who_is_watching_the_plugin(): void
    {
        $this->stubPlugins([
            'watched/watched.php'  => ['Name' => 'Watched', 'Version' => '1.0'],
            'external/external.php' => ['Name' => 'External', 'Version' => '1.0', 'UpdateURI' => 'https://example.com/update.json'],
            'orphan/orphan.php'    => ['Name' => 'Orphan', 'Version' => '1.0'],
            'pinned/pinned.php'    => ['Name' => 'Pinned', 'Version' => '1.0', 'UpdateURI' => 'false'],
        ]);
        $this->stubUpdateTransient(noUpdate: ['watched/watched.php' => (object) ['new_version' => '1.0']]);

        $plugins = (new InventoryReport())->collect()['plugins'];

        $this->assertSame('wordpress_org', $this->byFile($plugins, 'watched/watched.php')['update_source']);
        $this->assertSame('external', $this->byFile($plugins, 'external/external.php')['update_source']);
        $this->assertSame('unknown', $this->byFile($plugins, 'orphan/orphan.php')['update_source']);
        $this->assertSame('disabled', $this->byFile($plugins, 'pinned/pinned.php')['update_source']);
    }

    public function test_auto_update_flag_comes_from_the_option(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $k, $d = false) => $k === 'auto_update_plugins' ? ['akismet/akismet.php'] : $d,
        );
        $this->stubPlugins([
            'akismet/akismet.php' => ['Name' => 'Akismet', 'Version' => '5.3'],
            'hello.php'           => ['Name' => 'Hello Dolly', 'Version' => '1.7.2'],
        ]);

        $plugins = (new InventoryReport())->collect()['plugins'];

        $this->assertTrue($this->byFile($plugins, 'akismet/akismet.php')['auto_update']);
        $this->assertFalse($this->byFile($plugins, 'hello.php')['auto_update']);
    }

    public function test_collects_mu_plugins_and_dropins(): void
    {
        $this->stubPlugins([]);
        Functions\when('get_mu_plugins')->justReturn([
            'taw-mu-loader.php' => ['Name' => 'TAW MU Loader', 'Version' => '1.0.0', 'Author' => 'TAW'],
        ]);
        Functions\when('get_dropins')->justReturn([
            'object-cache.php'   => ['Name' => 'Redis Object Cache'],
            'advanced-cache.php' => ['Name' => 'WP Rocket'],
        ]);

        $payload = (new InventoryReport())->collect();

        $this->assertSame('taw-mu-loader.php', $payload['mu_plugins'][0]['file']);
        $this->assertSame('1.0.0', $payload['mu_plugins'][0]['version']);
        $this->assertSame(['object-cache.php', 'advanced-cache.php'], $payload['dropins']);
    }

    public function test_collects_themes_with_active_and_parent_state(): void
    {
        $this->stubPlugins([]);
        Functions\when('get_stylesheet')->justReturn('taw-child');
        Functions\when('get_template')->justReturn('taw');
        Functions\when('wp_get_themes')->justReturn([
            'taw'       => ['Name' => 'TAW', 'Version' => '1.22.0', 'Author' => 'TAW', 'RequiresPHP' => '8.2'],
            'taw-child' => ['Name' => 'TAW Child', 'Version' => '1.0.0', 'Template' => 'taw'],
        ]);
        $this->stubThemeUpdateTransient(
            response: ['taw' => ['new_version' => '1.23.0']],
            noUpdate: ['taw-child' => ['new_version' => '1.0.0']],
        );

        $themes = (new InventoryReport())->collect()['themes'];

        $parent = $this->bySlug($themes, 'taw');
        $child  = $this->bySlug($themes, 'taw-child');
        $this->assertFalse($parent['active']);
        $this->assertTrue($parent['parent_active']);
        $this->assertSame('1.23.0', $parent['update_version']);
        $this->assertSame('wordpress_org', $parent['update_source']);
        $this->assertSame('TAW', $parent['author']);
        $this->assertSame('8.2', $parent['requires_php']);
        $this->assertTrue($child['active']);
        $this->assertFalse($child['parent_active']);
        $this->assertSame('taw', $child['template']);
        $this->assertSame('wordpress_org', $child['update_source']);
    }

    /**
     * @param array<string, array<string, string>> $plugins
     */
    private function stubPlugins(array $plugins): void
    {
        Functions\when('get_plugins')->justReturn($plugins);
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $noUpdate
     */
    private function stubUpdateTransient(array $response = [], array $noUpdate = []): void
    {
        $transient = (object) ['response' => $response, 'no_update' => $noUpdate];
        Functions\when('get_site_transient')->alias(
            static fn (string $name) => $name === 'update_plugins' ? $transient : false,
        );
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $noUpdate
     */
    private function stubThemeUpdateTransient(array $response, array $noUpdate = []): void
    {
        $transient = (object) ['response' => $response, 'no_update' => $noUpdate];
        Functions\when('get_site_transient')->alias(
            static fn (string $name) => $name === 'update_themes' ? $transient : false,
        );
    }

    /**
     * @param list<array<string, mixed>> $plugins
     * @return array<string, mixed>
     */
    private function byFile(array $plugins, string $file): array
    {
        foreach ($plugins as $plugin) {
            if ($plugin['file'] === $file) {
                return $plugin;
            }
        }
        $this->fail("plugin {$file} not found");
    }

    /**
     * @param list<array<string, mixed>> $themes
     * @return array<string, mixed>
     */
    private function bySlug(array $themes, string $slug): array
    {
        foreach ($themes as $theme) {
            if ($theme['slug'] === $slug) {
                return $theme;
            }
        }
        $this->fail("theme {$slug} not found");
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
