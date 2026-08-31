<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit\Wire;

use Brain\Monkey\Functions;
use TAW\HubCompanion\Tests\TestCase;
use TAW\HubCompanion\Wire\ReplayStore;

final class ReplayStoreTest extends TestCase
{
    /**
     * @return \Closure(string): bool  in-memory wp_cache_add
     */
    private function fakeObjectCache(): \Closure
    {
        $store = [];

        return function (string $k) use (&$store): bool {
            if (isset($store[$k])) {
                return false;
            }
            $store[$k] = true;

            return true;
        };
    }

    public function test_with_a_persistent_cache_first_use_consumes_and_a_replay_is_caught(): void
    {
        Functions\when('wp_using_ext_object_cache')->justReturn(true);
        Functions\when('wp_cache_add')->alias($this->fakeObjectCache());

        $s = new ReplayStore();
        $this->assertTrue($s->consume('ed25519', 'hub', 'nonce-1'));
        $this->assertFalse($s->consume('ed25519', 'hub', 'nonce-1'));
        $this->assertTrue($s->consume('ed25519', 'hub', 'nonce-2'));
    }

    public function test_without_a_persistent_cache_it_uses_transients(): void
    {
        Functions\when('wp_using_ext_object_cache')->justReturn(false);

        $transients = [];
        Functions\when('get_transient')->alias(function (string $k) use (&$transients) {
            return $transients[$k] ?? false;
        });
        Functions\when('set_transient')->alias(function (string $k, $v) use (&$transients): bool {
            $transients[$k] = $v;

            return true;
        });

        $s = new ReplayStore();
        $this->assertTrue($s->consume('ed25519', 'hub', 'n1'));
        $this->assertFalse($s->consume('ed25519', 'hub', 'n1'));
        $this->assertTrue($s->consume('ed25519', 'hub', 'n2'));
    }

    public function test_the_same_nonce_under_a_different_key_id_is_independent(): void
    {
        Functions\when('wp_using_ext_object_cache')->justReturn(true);
        Functions\when('wp_cache_add')->alias($this->fakeObjectCache());

        $s = new ReplayStore();
        $this->assertTrue($s->consume('ed25519', 'hub-a', 'shared-nonce'));
        $this->assertTrue($s->consume('ed25519', 'hub-b', 'shared-nonce'));
    }
}
