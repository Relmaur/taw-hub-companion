<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit\Security;

use TAW\HubCompanion\Security\Database;
use TAW\HubCompanion\Security\DefenderScanner;
use TAW\HubCompanion\Tests\TestCase;

final class DefenderScannerTest extends TestCase
{
    public function test_is_unavailable_without_the_scan_item_table(): void
    {
        $scanner = new DefenderScanner($this->db(tables: []));

        $this->assertFalse($scanner->isAvailable());
        $this->assertSame([], $scanner->findings());
        $this->assertNull($scanner->lastScanAt());
    }

    public function test_reports_the_last_scan_time_from_the_latest_finished_scan(): void
    {
        $scanner = new DefenderScanner($this->db(
            tables: ['wp_defender_scan', 'wp_defender_scan_item'],
            scan: ['id' => 42, 'date_end' => '2026-09-01 14:30:00', 'date_start' => '2026-09-01 14:00:00'],
        ));

        $this->assertSame('2026-09-01T14:30:00+00:00', $scanner->lastScanAt());
    }

    public function test_returns_empty_when_no_scan_has_finished(): void
    {
        $scanner = new DefenderScanner($this->db(tables: ['wp_defender_scan', 'wp_defender_scan_item']));

        $this->assertSame([], $scanner->findings());
    }

    public function test_emits_one_finding_per_bug_for_a_plugin_vulnerability(): void
    {
        $scanner = new DefenderScanner($this->db(
            tables: ['wp_defender_scan', 'wp_defender_scan_item'],
            scan: ['id' => 7, 'date_end' => '2026-08-20 09:00:00', 'date_start' => '2026-08-20 08:00:00'],
            items: [[
                'id'       => 15,
                'type'     => 'vulnerability',
                'raw_data' => json_encode([
                    'type'      => 'plugin',
                    'slug'      => 'contact-form-7/wp-contact-form-7.php',
                    'base_slug' => 'contact-form-7',
                    'version'   => '5.1.3',
                    'name'      => 'Contact Form 7',
                    'bugs'      => [
                        ['vuln_type' => 'XSS', 'title' => 'Reflected XSS', 'ref' => ['https://patchstack.com/x'], 'fixed_in' => '5.1.4', 'cvss_score' => '8.8'],
                        ['vuln_type' => 'CSRF', 'title' => 'CSRF in upload', 'ref' => 'https://example.com/y', 'fixed_in' => '', 'cvss_score' => '4.3'],
                    ],
                ]),
            ]],
        ));

        $findings = array_map(static fn ($f) => $f->toArray(), $scanner->findings());
        $this->assertCount(2, $findings);

        $this->assertSame('defender', $findings[0]['scanner']);
        $this->assertSame('plugin', $findings[0]['component_type']);
        $this->assertSame('contact-form-7', $findings[0]['slug']);
        $this->assertSame('contact-form-7/wp-contact-form-7.php', $findings[0]['component_file']);
        $this->assertSame('5.1.3', $findings[0]['installed_version']);
        $this->assertSame('XSS: Reflected XSS', $findings[0]['title']);
        $this->assertSame('high', $findings[0]['severity']);
        $this->assertSame(8.8, $findings[0]['cvss_score']);
        $this->assertSame('https://patchstack.com/x', $findings[0]['link']);
        $this->assertSame('2026-08-20T09:00:00+00:00', $findings[0]['detected_at']);
        $this->assertSame('def:15:0', $findings[0]['scanner_ref']);

        $this->assertSame('medium', $findings[1]['severity']);
        $this->assertSame('https://example.com/y', $findings[1]['link']);
        $this->assertSame('def:15:1', $findings[1]['scanner_ref']);
    }

    public function test_maps_a_wp_core_vulnerability_with_no_slug(): void
    {
        $scanner = new DefenderScanner($this->db(
            tables: ['wp_defender_scan', 'wp_defender_scan_item'],
            scan: ['id' => 3, 'date_end' => '2026-08-01 00:00:00', 'date_start' => '2026-08-01 00:00:00'],
            items: [[
                'id'       => 1,
                'type'     => 'vulnerability',
                'raw_data' => json_encode([
                    'type'    => 'wp_core',
                    'slug'    => '',
                    'base_slug' => '',
                    'version' => '6.5.2',
                    'name'    => 'WordPress Core',
                    'bugs'    => [['vuln_type' => 'SQLi', 'title' => 'Core SQLi', 'ref' => [], 'fixed_in' => '6.5.3', 'cvss_score' => '9.1']],
                ]),
            ]],
        ));

        $f = $scanner->findings()[0]->toArray();
        $this->assertSame('core', $f['component_type']);
        $this->assertNull($f['slug']);
        $this->assertNull($f['component_file']);
        $this->assertSame('critical', $f['severity']);
    }

    public function test_maps_closed_and_outdated_plugins(): void
    {
        $scanner = new DefenderScanner($this->db(
            tables: ['wp_defender_scan', 'wp_defender_scan_item'],
            scan: ['id' => 9, 'date_end' => '2026-08-10 12:00:00', 'date_start' => '2026-08-10 11:00:00'],
            items: [
                [
                    'id'       => 20,
                    'type'     => 'plugin_closed',
                    'raw_data' => json_encode([
                        'name' => 'Dead Plugin', 'slug' => 'dead-plugin', 'version' => '1.0.0',
                        'url' => 'https://wordpress.org/plugins/dead-plugin/', 'reason_text' => 'Guideline Violation',
                    ]),
                ],
                [
                    'id'       => 21,
                    'type'     => 'plugin_outdated',
                    'raw_data' => json_encode([
                        'name' => 'Stale Plugin', 'slug' => 'stale-plugin', 'version' => '2.3.0',
                        'url' => 'https://wordpress.org/plugins/stale-plugin/', 'reason_text' => '',
                    ]),
                ],
            ],
        ));

        $byslug = [];
        foreach ($scanner->findings() as $finding) {
            $byslug[(string) $finding->slug] = $finding->toArray();
        }

        $this->assertSame('removed', $byslug['dead-plugin']['kind']);
        $this->assertSame('high', $byslug['dead-plugin']['severity']);
        $this->assertSame('Guideline Violation', $byslug['dead-plugin']['title']);
        $this->assertSame('def:20', $byslug['dead-plugin']['scanner_ref']);

        $this->assertSame('abandoned', $byslug['stale-plugin']['kind']);
        $this->assertSame('medium', $byslug['stale-plugin']['severity']);
        $this->assertStringContainsString('abandoned', (string) $byslug['stale-plugin']['title']);
    }

    /**
     * @param list<string>                   $tables
     * @param array<string, mixed>|null      $scan
     * @param list<array<string, mixed>>     $items
     */
    private function db(array $tables = [], ?array $scan = null, array $items = []): Database
    {
        return new class ($tables, $scan, $items) implements Database {
            /**
             * @param list<string>              $tables
             * @param array<string, mixed>|null $scan
             * @param list<array<string,mixed>> $items
             */
            public function __construct(
                private array $tables,
                private ?array $scan,
                private array $items,
            ) {
            }

            public function basePrefix(): string
            {
                return 'wp_';
            }

            public function tableExists(string $table): bool
            {
                return in_array($table, $this->tables, true);
            }

            public function getVar(string $sql): ?string
            {
                return null;
            }

            public function getRows(string $sql): array
            {
                if (str_contains($sql, 'defender_scan_item')) {
                    return $this->items;
                }
                if (str_contains($sql, 'defender_scan')) {
                    return $this->scan !== null ? [$this->scan] : [];
                }

                return [];
            }
        };
    }
}
