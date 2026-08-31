<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit\Wire;

use TAW\HubCompanion\Tests\TestCase;
use TAW\HubCompanion\Wire\CanonicalString;

/**
 * The canonical string is a frozen wire contract shared with the Hub
 * (taw-site-manager ADR-0003 / docs/reference/wire-protocol.md). These tests
 * pin it byte-for-byte — a change here is a deliberate `TAW-HUB-v1` → `v2`.
 */
final class CanonicalStringTest extends TestCase
{
    public function test_empty_body_golden(): void
    {
        $canonical = CanonicalString::forRequest(
            'GET',
            '/wp-json/taw-hub/v1/health',
            '',
            1_788_134_400,
            'testnonce000000000000',
        );

        $expected = "TAW-HUB-v1\n"
            . "GET\n"
            . "/wp-json/taw-hub/v1/health\n"
            . "1788134400\n"
            . "testnonce000000000000\n"
            . 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

        $this->assertSame($expected, $canonical);
    }

    public function test_matches_the_wire_protocol_reference_example(): void
    {
        // wire-protocol.md: POST /wp-json/taw-hub/v1/framework/sync {"dry_run":false}
        $body = '{"dry_run":false}';

        $canonical = CanonicalString::forRequest(
            'post',
            '/wp-json/taw-hub/v1/framework/sync',
            $body,
            1_788_134_400,
            '9f2c1b7e4a6d40f0a1b2c3d4e5f60718',
        );

        $this->assertSame(
            implode("\n", [
                'TAW-HUB-v1',
                'POST',
                '/wp-json/taw-hub/v1/framework/sync',
                '1788134400',
                '9f2c1b7e4a6d40f0a1b2c3d4e5f60718',
                hash('sha256', $body),
            ]),
            $canonical,
        );
    }

    public function test_method_is_uppercased_and_there_is_no_trailing_newline(): void
    {
        $c = CanonicalString::forRequest('get', '/x', '', 1, 'n123456789012345');

        $this->assertStringStartsWith("TAW-HUB-v1\nGET\n", $c);
        $this->assertDoesNotMatchRegularExpression('/\n$/', $c);
        $this->assertSame(6, substr_count($c, "\n") + 1, 'exactly 6 lines');
    }

    public function test_response_canonical_uses_literal_RESPONSE_and_the_request_path(): void
    {
        $c = CanonicalString::forResponse('/wp-json/taw-hub/v1/health', '{"ok":true}', 1_788_134_461, 'respnonce00000000');

        $this->assertSame(
            implode("\n", [
                'TAW-HUB-v1',
                'RESPONSE',
                '/wp-json/taw-hub/v1/health',
                '1788134461',
                'respnonce00000000',
                hash('sha256', '{"ok":true}'),
            ]),
            $c,
        );
    }

    public function test_a_different_body_changes_the_digest_line(): void
    {
        $a = CanonicalString::forRequest('POST', '/x', '{"a":1}', 1, 'n123456789012345');
        $b = CanonicalString::forRequest('POST', '/x', '{"a":2}', 1, 'n123456789012345');

        $this->assertNotSame($a, $b);
    }
}
