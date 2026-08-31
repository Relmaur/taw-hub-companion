<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit\Wire;

use TAW\HubCompanion\Tests\TestCase;
use TAW\HubCompanion\Wire\CanonicalString;
use TAW\HubCompanion\Wire\Ed25519;
use TAW\HubCompanion\Wire\HmacSha256;
use TAW\HubCompanion\Wire\KeyRing;
use TAW\HubCompanion\Wire\ReplayStore;
use TAW\HubCompanion\Wire\SignatureGate;
use TAW\HubCompanion\Wire\SignatureHeaders;
use TAW\HubCompanion\Wire\VerificationException;

/**
 * The full ADR-0003 pipeline against a real libsodium keypair. Also the place
 * the cross-implementation vector file (tests/fixtures/hub-vectors.json, from
 * the Hub's tests/Support/HubSigning.php) will be asserted once it lands.
 */
final class SignatureGateTest extends TestCase
{
    private const NOW    = 1_788_134_400;
    private const KEY_ID = 'hub-prod';
    private const PATH   = '/wp-json/taw-hub/v1/framework/sync';

    private string $hubSecret;
    private string $hubPublic;
    private ReplayStore $replay;

    protected function setUp(): void
    {
        parent::setUp();

        $pair = sodium_crypto_sign_keypair();
        $this->hubSecret = sodium_crypto_sign_secretkey($pair);
        $this->hubPublic = sodium_crypto_sign_publickey($pair);

        // In-memory replay store via a fake persistent object cache.
        $seen = [];
        \Brain\Monkey\Functions\when('wp_using_ext_object_cache')->justReturn(true);
        \Brain\Monkey\Functions\when('wp_cache_add')->alias(
            static function (string $key) use (&$seen): bool {
                if (isset($seen[$key])) {
                    return false;
                }
                $seen[$key] = true;

                return true;
            },
        );
        $this->replay = new ReplayStore();
    }

    private function gate(?string $expectedKeyId = self::KEY_ID): SignatureGate
    {
        return new SignatureGate(
            new KeyRing($this->hubPublic, $expectedKeyId),
            $this->replay,
            60,
            fn (): int => self::NOW,
        );
    }

    /**
     * @param array<string, string> $overrides
     * @return array{0: string, 1: string, 2: SignatureHeaders}  [method, path, headers]
     */
    private function signed(
        string $method = 'POST',
        string $path = self::PATH,
        string $body = '{"dry_run":false}',
        ?int $timestamp = null,
        string $nonce = 'nonce0000000000000000',
        ?string $secret = null,
        array $overrides = [],
    ): array {
        $timestamp ??= self::NOW;
        $secret ??= $this->hubSecret;

        $canonical = CanonicalString::forRequest($method, $path, $body, $timestamp, $nonce);
        $sig       = base64_encode(Ed25519::sign($canonical, $secret));

        $headers = SignatureHeaders::fromLookup($this->lookup(array_merge([
            'X-Taw-Hub-Algo'      => 'ed25519',
            'X-Taw-Hub-Key-Id'    => self::KEY_ID,
            'X-Taw-Hub-Timestamp' => (string) $timestamp,
            'X-Taw-Hub-Nonce'     => $nonce,
            'X-Taw-Hub-Signature' => $sig,
        ], $overrides)));

        return [$method, $path, $headers];
    }

    /** @param array<string, string> $h @return callable(string): string */
    private function lookup(array $h): callable
    {
        $lower = [];
        foreach ($h as $k => $v) {
            $lower[strtolower($k)] = $v;
        }

        return static fn (string $n): string => $lower[strtolower($n)] ?? '';
    }

    private function assertReason(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected {$reason}");
        } catch (VerificationException $e) {
            $this->assertSame($reason, $e->reason());
        }
    }

    public function test_a_correctly_signed_request_passes(): void
    {
        [$m, $p, $h] = $this->signed();
        $this->gate()->verify($m, $p, '{"dry_run":false}', $h);
        $this->addToAssertionCount(1);
    }

    public function test_a_stale_timestamp_is_rejected(): void
    {
        $this->assertReason(VerificationException::TIMESTAMP_OUT_OF_WINDOW, function (): void {
            [$m, $p, $h] = $this->signed(timestamp: self::NOW - 61);
            $this->gate()->verify($m, $p, '{"dry_run":false}', $h);
        });
    }

    public function test_a_future_timestamp_beyond_drift_is_rejected(): void
    {
        $this->assertReason(VerificationException::TIMESTAMP_OUT_OF_WINDOW, function (): void {
            [$m, $p, $h] = $this->signed(timestamp: self::NOW + 61);
            $this->gate()->verify($m, $p, '{"dry_run":false}', $h);
        });
    }

    public function test_an_unexpected_key_id_is_rejected(): void
    {
        $this->assertReason(VerificationException::UNKNOWN_KEY_ID, function (): void {
            [$m, $p, $h] = $this->signed(overrides: ['X-Taw-Hub-Key-Id' => 'hub-staging']);
            $this->gate()->verify($m, $p, '{"dry_run":false}', $h);
        });
    }

    public function test_a_signature_from_the_wrong_key_is_invalid(): void
    {
        $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->assertReason(VerificationException::INVALID_SIGNATURE, function () use ($other): void {
            [$m, $p, $h] = $this->signed(secret: $other);
            $this->gate()->verify($m, $p, '{"dry_run":false}', $h);
        });
    }

    public function test_a_tampered_body_is_invalid(): void
    {
        $this->assertReason(VerificationException::INVALID_SIGNATURE, function (): void {
            [$m, $p, $h] = $this->signed();
            $this->gate()->verify($m, $p, '{"dry_run":true}', $h); // body differs from what was signed
        });
    }

    public function test_a_tampered_path_is_invalid(): void
    {
        $this->assertReason(VerificationException::INVALID_SIGNATURE, function (): void {
            [$m, , $h] = $this->signed();
            $this->gate()->verify($m, '/wp-json/taw-hub/v1/taw', '{"dry_run":false}', $h);
        });
    }

    public function test_a_replayed_nonce_is_rejected_on_the_second_use(): void
    {
        [$m, $p, $h] = $this->signed();
        $this->gate()->verify($m, $p, '{"dry_run":false}', $h);

        $this->assertReason(VerificationException::REPLAYED_NONCE, function () use ($m, $p, $h): void {
            $this->gate()->verify($m, $p, '{"dry_run":false}', $h);
        });
    }

    public function test_a_failed_signature_does_not_consume_the_nonce(): void
    {
        $bad = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->assertReason(VerificationException::INVALID_SIGNATURE, function () use ($bad): void {
            [$m, $p, $h] = $this->signed(secret: $bad);
            $this->gate()->verify($m, $p, '{"dry_run":false}', $h);
        });

        // Same nonce, now correctly signed → passes (nonce was not burned).
        [$m, $p, $h] = $this->signed();
        $this->gate()->verify($m, $p, '{"dry_run":false}', $h);
        $this->addToAssertionCount(1);
    }

    public function test_hmac_channel_round_trips(): void
    {
        $secret = 'a-shared-n8n-secret';
        $gate = new SignatureGate(
            new KeyRing(null, null, $secret, 'n8n'),
            $this->replay,
            60,
            fn (): int => self::NOW,
        );

        $canonical = CanonicalString::forRequest('POST', self::PATH, '{}', self::NOW, 'hmacnonce00000000');
        $sig = base64_encode(HmacSha256::sign($canonical, $secret));

        $headers = SignatureHeaders::fromLookup($this->lookup([
            'X-Taw-Hub-Algo'      => 'hmac-sha256',
            'X-Taw-Hub-Key-Id'    => 'n8n',
            'X-Taw-Hub-Timestamp' => (string) self::NOW,
            'X-Taw-Hub-Nonce'     => 'hmacnonce00000000',
            'X-Taw-Hub-Signature' => $sig,
        ]));

        $gate->verify('POST', self::PATH, '{}', $headers);
        $this->addToAssertionCount(1);
    }
}
