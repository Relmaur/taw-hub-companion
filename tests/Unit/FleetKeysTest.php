<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use TAW\HubCompanion\Config;
use TAW\HubCompanion\Keys\SiteKeypair;
use TAW\HubCompanion\Tests\TestCase;
use TAW\HubCompanion\Wire\KeyRing;
use TAW\HubCompanion\Wire\SignatureHeaders;

/**
 * v0.3.0: the theme ships taw-fleet's key; the companion runs as an mu-plugin.
 */
final class FleetKeysTest extends TestCase
{
    private string $parent;
    private string $child;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/taw-companion-' . bin2hex(random_bytes(4));
        $this->parent = $base . '/parent';
        $this->child  = $base . '/child';
        mkdir($this->parent, 0o777, true);
        mkdir($this->child, 0o777, true);
        Functions\when('get_template_directory')->justReturn($this->parent);
        Functions\when('get_stylesheet_directory')->justReturn($this->child);
    }

    private function key(int $byte): string
    {
        return sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(str_repeat(chr($byte), 32)));
    }

    /** @param array<string, string> $keys */
    private function theme(string $dir, array $keys): void
    {
        file_put_contents($dir . '/composer.json', json_encode(['name' => 'acme/theme', 'extra' => ['taw-companion' => ['keys' => $keys]]]));
    }

    public function test_keys_come_from_the_theme_child_over_parent(): void
    {
        $this->theme($this->parent, ['taw-fleet' => base64_encode($this->key(1)), 'old' => base64_encode($this->key(2))]);
        $this->theme($this->child, ['taw-fleet' => base64_encode($this->key(3))]);

        $keys = (new Config())->fleetKeys();

        $this->assertSame(['taw-fleet' => $this->key(3), 'old' => $this->key(2)], $keys);
        $this->assertTrue((new Config())->isConfigured(), 'a theme key alone configures the plugin');
    }

    public function test_invalid_entries_are_skipped_and_the_filter_can_add(): void
    {
        $this->theme($this->parent, ['taw-fleet' => 'not-a-key', 'bad id!' => base64_encode($this->key(1))]);
        Filters\expectApplied('taw_hub_companion_fleet_keys')->andReturnUsing(fn (array $k) => $k + ['extra' => base64_encode($this->key(4))]);

        $this->assertSame(['extra' => $this->key(4)], (new Config())->fleetKeys());
    }

    public function test_no_composer_json_means_no_keys(): void
    {
        $this->assertSame([], (new Config())->fleetKeys());
        $this->assertFalse((new Config())->isConfigured());
    }

    public function test_the_key_ring_resolves_fleet_keys_by_exact_id(): void
    {
        $hub = $this->key(9);
        $ring = new KeyRing($hub, 'hub-prod', null, null, fn (): array => ['taw-fleet' => $this->key(1)]);

        $this->assertSame($hub, $ring->resolve(SignatureHeaders::ALGO_ED25519, 'hub-prod'));
        $this->assertSame($this->key(1), $ring->resolve(SignatureHeaders::ALGO_ED25519, 'taw-fleet'));
        $this->assertNull($ring->resolve(SignatureHeaders::ALGO_ED25519, 'taw-flee'));
        $this->assertNull($ring->resolve(SignatureHeaders::ALGO_HMAC, 'taw-fleet'), 'fleet keys are Ed25519 only');

        $fleetOnly = new KeyRing(null, 'hub-local', null, null, fn (): array => ['taw-fleet' => $this->key(1)]);
        $this->assertSame($this->key(1), $fleetOnly->resolve(SignatureHeaders::ALGO_ED25519, 'taw-fleet'));
        $this->assertNull($fleetOnly->resolve(SignatureHeaders::ALGO_ED25519, 'hub-local'));
    }

    public function test_first_keypair_is_claimed_once_under_a_race(): void
    {
        $options = ['taw_hub_companion_secret_key' => null];
        $winner = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $first = true;
        // Another request stores its secret between our read and our insert.
        Functions\when('get_option')->alias(function (string $k, $d = false) use (&$options, &$first, $winner) {
            if ($k === 'taw_hub_companion_secret_key' && $first) {
                $first = false;

                return $d;
            }
            if ($k === 'taw_hub_companion_secret_key') {
                return base64_encode($winner);
            }

            return $options[$k] ?? $d;
        });
        Functions\when('add_option')->alias(function (string $k, $v) use (&$options): bool {
            if ($k === 'taw_hub_companion_secret_key') {
                return false; // the row exists already
            }
            $options[$k] = $options[$k] ?? $v;

            return true;
        });
        Functions\when('update_option')->alias(function (string $k, $v) use (&$options): bool {
            $options[$k] = $v;

            return true;
        });

        $pub = (new SiteKeypair(new Config()))->publicKeyBase64();

        $this->assertSame(base64_encode(sodium_crypto_sign_publickey_from_secretkey($winner)), $pub, 'the stored secret wins');
    }

    public function test_mu_mode(): void
    {
        $this->assertFalse((new Config())->muMode(), 'without the constants: a regular plugin');
    }
}
