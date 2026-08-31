<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit\Wire;

use PHPUnit\Framework\Attributes\DataProvider;
use TAW\HubCompanion\Tests\TestCase;
use TAW\HubCompanion\Wire\SignatureHeaders;
use TAW\HubCompanion\Wire\VerificationException;

final class SignatureHeadersTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     * @return callable(string): string
     */
    private function lookup(array $headers): callable
    {
        $lower = [];
        foreach ($headers as $k => $v) {
            $lower[strtolower($k)] = $v;
        }

        return static fn (string $name): string => $lower[strtolower($name)] ?? '';
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'X-Taw-Hub-Algo'      => 'ed25519',
            'X-Taw-Hub-Key-Id'    => 'hub-prod',
            'X-Taw-Hub-Timestamp' => '1788134400',
            'X-Taw-Hub-Nonce'     => '9f2c1b7e4a6d40f0a1b2c3d4e5f60718',
            'X-Taw-Hub-Signature' => base64_encode(str_repeat("\1", SODIUM_CRYPTO_SIGN_BYTES)),
        ], $overrides);
    }

    public function test_parses_a_well_formed_ed25519_header_set(): void
    {
        $h = SignatureHeaders::fromLookup($this->lookup($this->valid()));

        $this->assertSame('ed25519', $h->algo);
        $this->assertSame('hub-prod', $h->keyId);
        $this->assertSame(1_788_134_400, $h->timestamp);
        $this->assertSame(SODIUM_CRYPTO_SIGN_BYTES, strlen($h->signatureRaw));
    }

    public function test_accepts_url_safe_base64_signatures(): void
    {
        $raw = random_bytes(SODIUM_CRYPTO_SIGN_BYTES);
        $urlSafe = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        $h = SignatureHeaders::fromLookup($this->lookup($this->valid(['X-Taw-Hub-Signature' => $urlSafe])));

        $this->assertSame($raw, $h->signatureRaw);
    }

    public function test_hmac_signatures_must_be_32_bytes(): void
    {
        $h = SignatureHeaders::fromLookup($this->lookup($this->valid([
            'X-Taw-Hub-Algo'      => 'hmac-sha256',
            'X-Taw-Hub-Signature' => base64_encode(str_repeat("\2", 32)),
        ])));

        $this->assertSame('hmac-sha256', $h->algo);
        $this->assertSame(32, strlen($h->signatureRaw));
    }

    /**
     * @param array<string, string> $overrides
     */
    #[DataProvider('malformedCases')]
    public function test_malformed_headers_are_rejected(array $overrides): void
    {
        try {
            SignatureHeaders::fromLookup($this->lookup($this->valid($overrides)));
            $this->fail('Expected VerificationException');
        } catch (VerificationException $e) {
            $this->assertSame(VerificationException::MALFORMED_SIGNATURE_HEADERS, $e->reason());
        }
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function malformedCases(): array
    {
        return [
            'missing algo'        => [['X-Taw-Hub-Algo' => '']],
            'unknown algo'        => [['X-Taw-Hub-Algo' => 'rsa-sha256']],
            'blank key id'        => [['X-Taw-Hub-Key-Id' => '']],
            'key id with space'   => [['X-Taw-Hub-Key-Id' => 'hub prod']],
            'non-numeric ts'      => [['X-Taw-Hub-Timestamp' => 'soon']],
            'blank nonce'         => [['X-Taw-Hub-Nonce' => '']],
            'short nonce'         => [['X-Taw-Hub-Nonce' => 'abc']],
            'nonce with slash'    => [['X-Taw-Hub-Nonce' => 'aaaa/bbbb/cccc/dddd']],
            'signature not b64'   => [['X-Taw-Hub-Signature' => '!!! not base64 !!!']],
            'ed25519 wrong len'   => [['X-Taw-Hub-Signature' => base64_encode('too short')]],
        ];
    }
}
