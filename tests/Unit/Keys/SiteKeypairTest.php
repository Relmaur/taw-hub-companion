<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit\Keys;

use Brain\Monkey\Functions;
use TAW\HubCompanion\Config;
use TAW\HubCompanion\Keys\SiteKeypair;
use TAW\HubCompanion\Tests\TestCase;
use TAW\HubCompanion\Wire\Ed25519;

final class SiteKeypairTest extends TestCase
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
        Functions\when('delete_option')->alias(function (string $k): bool {
            unset($this->options[$k]);

            return true;
        });
    }

    private function keypair(): SiteKeypair
    {
        return new SiteKeypair(new Config());
    }

    public function test_ensure_exists_generates_a_keypair_and_a_stable_key_id(): void
    {
        $kp = $this->keypair();
        $kp->ensureExists();

        $pub   = $kp->publicKeyBase64();
        $keyId = $kp->keyId();

        $this->assertMatchesRegularExpression('#^site-[0-9a-f]{16}$#', $keyId);
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen((string) base64_decode($pub, true)));

        // Idempotent — a fresh instance sees the same stored identity.
        $again = new SiteKeypair(new Config());
        $this->assertSame($pub, $again->publicKeyBase64());
        $this->assertSame($keyId, $again->keyId());
    }

    public function test_sign_produces_a_signature_its_public_key_verifies(): void
    {
        $kp = $this->keypair();
        $kp->ensureExists();

        $sig = $kp->sign('the-canonical-string');
        $pub = (string) base64_decode($kp->publicKeyBase64(), true);

        $this->assertTrue(Ed25519::verify($sig, 'the-canonical-string', $pub));
    }

    public function test_rotate_changes_the_keypair_but_keeps_the_key_id(): void
    {
        $kp = $this->keypair();
        $kp->ensureExists();

        $keyIdBefore = $kp->keyId();
        $pubBefore   = $kp->publicKeyBase64();

        $newPub = $kp->rotate();

        $this->assertNotSame($pubBefore, $newPub);
        $this->assertSame($newPub, $kp->publicKeyBase64());
        $this->assertSame($keyIdBefore, $kp->keyId());
    }
}
