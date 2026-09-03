<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit\Update;

use Brain\Monkey\Functions;
use TAW\HubCompanion\Tests\TestCase;
use TAW\HubCompanion\Update\Updater;

final class UpdaterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('plugin_basename')->justReturn('taw-hub-companion/taw-hub-companion.php');
    }

    private function updater(string $version = '0.2.0', bool $auto = true): Updater
    {
        return new Updater('/wp-content/plugins/taw-hub-companion/taw-hub-companion.php', $version, $auto);
    }

    // -- parseRelease --------------------------------------------------

    public function test_parse_release_prefers_a_zip_asset_and_strips_the_v_prefix(): void
    {
        $parsed = Updater::parseRelease([
            'tag_name'     => 'v0.3.1',
            'html_url'     => 'https://github.com/Relmaur/taw-hub-companion/releases/tag/v0.3.1',
            'body'         => '## Notes',
            'published_at' => '2026-09-10T12:00:00Z',
            'assets'       => [
                ['name' => 'source.tar.gz', 'browser_download_url' => 'https://example.test/x.tar.gz'],
                ['name' => 'taw-hub-companion-0.3.1.zip', 'browser_download_url' => 'https://example.test/plugin.zip'],
            ],
            'zipball_url'  => 'https://api.github.com/repos/Relmaur/taw-hub-companion/zipball/v0.3.1',
        ]);

        $this->assertNotNull($parsed);
        $this->assertSame('0.3.1', $parsed['version']);
        $this->assertSame('https://example.test/plugin.zip', $parsed['download_url']);
        $this->assertSame('## Notes', $parsed['changelog']);
    }

    public function test_parse_release_falls_back_to_the_zipball_when_no_asset(): void
    {
        $parsed = Updater::parseRelease([
            'tag_name'    => '0.2.5',
            'zipball_url' => 'https://api.github.com/repos/Relmaur/taw-hub-companion/zipball/0.2.5',
        ]);

        $this->assertNotNull($parsed);
        $this->assertSame('0.2.5', $parsed['version']);
        $this->assertSame('https://api.github.com/repos/Relmaur/taw-hub-companion/zipball/0.2.5', $parsed['download_url']);
        $this->assertSame('https://github.com/Relmaur/taw-hub-companion', $parsed['homepage']);
    }

    public function test_parse_release_rejects_drafts_prereleases_and_untagged(): void
    {
        $this->assertNull(Updater::parseRelease(['tag_name' => 'v1.0.0', 'draft' => true, 'zipball_url' => 'z']));
        $this->assertNull(Updater::parseRelease(['tag_name' => 'v1.0.0', 'prerelease' => true, 'zipball_url' => 'z']));
        $this->assertNull(Updater::parseRelease(['zipball_url' => 'z']));
        $this->assertNull(Updater::parseRelease(['tag_name' => 'v1.0.0'])); // no download anywhere
    }

    // -- injectUpdate -----------------------------------------------

    public function test_inject_update_adds_a_response_row_when_behind(): void
    {
        Functions\when('get_transient')->justReturn([
            'version'      => '0.3.0',
            'download_url' => 'https://example.test/plugin.zip',
            'homepage'     => 'https://github.com/Relmaur/taw-hub-companion',
            'changelog'    => '',
            'published_at' => '',
        ]);

        $transient = $this->updater('0.2.0')->injectUpdate((object) ['response' => [], 'no_update' => []]);

        $row = $transient->response['taw-hub-companion/taw-hub-companion.php'];
        $this->assertSame('0.3.0', $row->new_version);
        $this->assertSame('https://example.test/plugin.zip', $row->package);
        $this->assertArrayNotHasKey('taw-hub-companion/taw-hub-companion.php', $transient->no_update);
    }

    public function test_inject_update_records_no_update_when_current(): void
    {
        Functions\when('get_transient')->justReturn([
            'version'      => '0.2.0',
            'download_url' => 'https://example.test/plugin.zip',
            'homepage'     => 'https://github.com/Relmaur/taw-hub-companion',
            'changelog'    => '',
            'published_at' => '',
        ]);

        $transient = $this->updater('0.2.0')->injectUpdate((object) ['response' => [], 'no_update' => []]);

        $this->assertArrayNotHasKey('taw-hub-companion/taw-hub-companion.php', $transient->response);
        $this->assertArrayHasKey('taw-hub-companion/taw-hub-companion.php', $transient->no_update);
        $this->assertSame('', $transient->no_update['taw-hub-companion/taw-hub-companion.php']->package);
    }

    public function test_inject_update_leaves_a_non_object_transient_untouched(): void
    {
        $this->assertFalse($this->updater()->injectUpdate(false));
    }

    // -- enableAutoUpdate -----------------------------------------

    public function test_enable_auto_update_only_opts_in_this_plugin(): void
    {
        $u = $this->updater();

        $this->assertTrue($u->enableAutoUpdate(false, (object) ['plugin' => 'taw-hub-companion/taw-hub-companion.php']));
        $this->assertFalse($u->enableAutoUpdate(false, (object) ['plugin' => 'akismet/akismet.php']));
        $this->assertNull($u->enableAutoUpdate(null, (object) ['plugin' => 'other/other.php']));
    }

    // -- normalizeSourceDir --------------------------------------

    public function test_normalize_source_dir_ignores_other_plugins(): void
    {
        $u   = $this->updater();
        $src = '/tmp/upgrade/some-other-plugin/';

        $this->assertSame(
            $src,
            $u->normalizeSourceDir($src, '/tmp/upgrade/', null, ['plugin' => 'some-other/some-other.php']),
        );
    }
}
