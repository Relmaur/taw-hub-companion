<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit\Wire;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use TAW\HubCompanion\Tests\TestCase;
use TAW\HubCompanion\Wire\CanonicalString;
use TAW\HubCompanion\Wire\Ed25519;
use TAW\HubCompanion\Wire\KeyRing;
use TAW\HubCompanion\Wire\ReplayStore;
use TAW\HubCompanion\Wire\SignatureGate;
use TAW\HubCompanion\Wire\SignatureHeaders;
use TAW\HubCompanion\Wire\VerificationException;

/**
 * Cross-implementation parity against the Hub's own signer. The fixture
 * (tests/fixtures/hub-signing-vectors.json) is generated from
 * taw-hub's `SignedMessage` / `Ed25519Signer` / `HmacSha256Signer`
 * — if these pass, both implementations agree on the wire.
 *
 * Regenerate the fixture on the Hub side (never hand-edit); a canonical
 * change needs a new ADR first.
 */
final class SigningVectorsTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $fixture;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(
            (string) file_get_contents(__DIR__ . '/../../fixtures/hub-signing-vectors.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::$fixture = $decoded;
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function allVectors(): iterable
    {
        $decoded = json_decode(
            (string) file_get_contents(__DIR__ . '/../../fixtures/hub-signing-vectors.json'),
            true,
        );
        /** @var list<array<string, mixed>> $vectors */
        $vectors = $decoded['vectors'];
        foreach ($vectors as $v) {
            yield (string) $v['name'] => [$v];
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function requestVectors(): iterable
    {
        foreach (self::allVectors() as $name => $case) {
            if ($case[0]['method'] !== 'RESPONSE') {
                yield $name => $case;
            }
        }
    }

    /**
     * Every vector's canonical string must be reproduced byte-for-byte.
     *
     * @param array<string, mixed> $v
     */
    #[DataProvider('allVectors')]
    public function test_canonical_string_matches(array $v): void
    {
        $built = $v['method'] === 'RESPONSE'
            ? CanonicalString::forResponse((string) $v['path'], (string) $v['body'], (int) $v['timestamp'], (string) $v['nonce'])
            : CanonicalString::forRequest((string) $v['method'], (string) $v['path'], (string) $v['body'], (int) $v['timestamp'], (string) $v['nonce']);

        $this->assertSame($v['canonical_string'], $built);
        $this->assertSame($v['body_sha256'], hash('sha256', (string) $v['body']));
    }

    /**
     * Request vectors run through the full gate at the fixture's timestamp.
     *
     * @param array<string, mixed> $v
     */
    #[DataProvider('requestVectors')]
    public function test_signature_gate_agrees_with_the_hub(array $v): void
    {
        Functions\when('wp_using_ext_object_cache')->justReturn(true);
        $seen = [];
        Functions\when('wp_cache_add')->alias(function (string $k) use (&$seen): bool {
            if (isset($seen[$k])) {
                return false;
            }
            $seen[$k] = true;

            return true;
        });

        $algo  = (string) $v['algo'];
        $keyId = (string) $v['key_id'];
        $keyMaterial = $algo === 'ed25519'
            ? (string) base64_decode((string) $v['key_b64'], true)
            : (string) $v['key_b64'];

        $keyRing = $algo === 'ed25519'
            ? new KeyRing($keyMaterial, $keyId)
            : new KeyRing(null, null, $keyMaterial, $keyId);

        $gate = new SignatureGate($keyRing, new ReplayStore(), 60, fn (): int => (int) $v['timestamp']);

        /** @var array<string, string> $headers */
        $headers = $v['headers'];
        $parsed  = SignatureHeaders::fromLookup(
            static fn (string $n): string => $headers[$n] ?? '',
        );

        $verify = fn () => $gate->verify((string) $v['method'], (string) $v['path'], (string) $v['body'], $parsed);

        if ($v['expect'] === 'valid') {
            $verify();
            $this->addToAssertionCount(1);

            return;
        }

        try {
            $verify();
            $this->fail("Expected {$v['reason']}");
        } catch (VerificationException $e) {
            $this->assertSame($v['reason'], $e->reason());
        }
    }

    /**
     * The RESPONSE vector proves our response canonical + the site's signature
     * agree with what `HttpCompanionClient::verifyResponse` builds.
     */
    public function test_response_vector_verifies_against_the_site_key(): void
    {
        $v = null;
        foreach (self::allVectors() as $case) {
            if ($case[0]['name'] === 'response_health_ed25519') {
                $v = $case[0];
                break;
            }
        }
        self::assertIsArray($v);

        $canonical = CanonicalString::forResponse(
            (string) $v['path'],
            (string) $v['body'],
            (int) $v['timestamp'],
            (string) $v['nonce'],
        );
        $this->assertSame($v['canonical_string'], $canonical);

        $ok = Ed25519::verify(
            (string) base64_decode((string) $v['signature_b64'], true),
            $canonical,
            (string) base64_decode((string) $v['key_b64'], true),
        );
        $this->assertTrue($ok, 'the RESPONSE signature must verify against our canonical');
    }
}
