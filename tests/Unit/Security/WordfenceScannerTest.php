<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit\Security;

use TAW\HubCompanion\Security\Database;
use TAW\HubCompanion\Security\WordfenceScanner;
use TAW\HubCompanion\Tests\TestCase;

final class WordfenceScannerTest extends TestCase
{
    public function test_is_unavailable_when_the_issues_table_is_absent(): void
    {
        $scanner = new WordfenceScanner($this->db(tables: []));

        $this->assertFalse($scanner->isAvailable());
        $this->assertSame([], $scanner->findings());
        $this->assertNull($scanner->lastScanAt());
    }

    public function test_resolves_the_lowercase_schema_table_name(): void
    {
        $scanner = new WordfenceScanner($this->db(tables: ['wp_wfissues']));

        $this->assertTrue($scanner->isAvailable());
    }

    public function test_reads_the_last_scan_time_from_wf_config(): void
    {
        $scanner = new WordfenceScanner($this->db(
            tables: ['wp_wfIssues', 'wp_wfConfig'],
            vars: ['scanTime' => '1756814400.1234'],
        ));

        $this->assertSame(gmdate('c', 1_756_814_400), $scanner->lastScanAt());
    }

    public function test_maps_a_plugin_vulnerability_with_cvss(): void
    {
        $scanner = new WordfenceScanner($this->db(
            tables: ['wp_wfIssues'],
            rows: [$this->issue('wfPluginVulnerable', 100, 'The Plugin "Contact Form 7" has a security vulnerability.', [
                'Name'               => 'Contact Form 7',
                'Version'            => '5.1.3',
                'slug'               => 'contact-form-7',
                'pluginFile'         => '/srv/www/site/wp-content/plugins/contact-form-7/wp-contact-form-7.php',
                'vulnerable'         => true,
                'vulnerabilityLink'  => 'https://www.wordfence.com/threat-intel/vulnerabilities/x',
                'cvssScore'          => '8.8',
                'cvssVector'         => 'CVSS:3.1/AV:N/AC:L/PR:L/UI:N/S:U/C:H/I:H/A:H',
            ], 1_756_000_000)],
        ));

        $findings = $scanner->findings();
        $this->assertCount(1, $findings);

        $f = $findings[0]->toArray();
        $this->assertSame('wordfence', $f['scanner']);
        $this->assertSame('plugin', $f['component_type']);
        $this->assertSame('contact-form-7', $f['slug']);
        $this->assertSame('contact-form-7/wp-contact-form-7.php', $f['component_file']);
        $this->assertSame('5.1.3', $f['installed_version']);
        $this->assertSame('vulnerability', $f['kind']);
        $this->assertSame('high', $f['severity']);
        $this->assertSame(8.8, $f['cvss_score']);
        $this->assertStringStartsWith('CVSS:3.1', (string) $f['cvss_vector']);
        $this->assertStringContainsString('threat-intel', (string) $f['link']);
        $this->assertSame(gmdate('c', 1_756_000_000), $f['detected_at']);
        $this->assertSame('1', $f['scanner_ref']);
    }

    public function test_maps_an_abandoned_plugin_and_promotes_it_when_also_vulnerable(): void
    {
        $scanner = new WordfenceScanner($this->db(
            tables: ['wp_wfIssues'],
            rows: [
                $this->issue('wfPluginAbandoned', 50, 'The Plugin "Old Slider" appears to be abandoned.', [
                    'slug' => 'old-slider', 'version' => '2.0.0', 'abandoned' => true, 'vulnerable' => false,
                ], 1_756_000_000),
                $this->issue('wfPluginAbandoned', 100, 'The Plugin "Risky Forms" appears to be abandoned.', [
                    'slug' => 'risky-forms', 'version' => '1.1.0', 'abandoned' => true, 'vulnerable' => true,
                    'cvssScore' => '9.4',
                ], 1_756_000_000),
            ],
        ));

        $byslug = [];
        foreach ($scanner->findings() as $finding) {
            $byslug[(string) $finding->slug] = $finding->toArray();
        }

        $this->assertSame('abandoned', $byslug['old-slider']['kind']);
        $this->assertSame('medium', $byslug['old-slider']['severity']);
        $this->assertSame('vulnerability', $byslug['risky-forms']['kind']);
        $this->assertSame('critical', $byslug['risky-forms']['severity']);
    }

    public function test_maps_a_core_update_carrying_a_vulnerability(): void
    {
        $scanner = new WordfenceScanner($this->db(
            tables: ['wp_wfIssues'],
            rows: [$this->issue('wfUpgrade', 75, 'Your WordPress version is out of date', [
                'vulnerable' => true, 'version' => '6.6.1',
            ], 1_756_000_000)],
        ));

        $f = $scanner->findings()[0]->toArray();
        $this->assertSame('core', $f['component_type']);
        $this->assertSame('vulnerability', $f['kind']);
        $this->assertNull($f['slug']);
    }

    public function test_ignores_non_security_issues(): void
    {
        $scanner = new WordfenceScanner($this->db(
            tables: ['wp_wfIssues'],
            rows: [
                $this->issue('file', 100, 'Modified core file', ['file' => 'wp-load.php'], 1_756_000_000),
                $this->issue('wfPluginUpgrade', 50, 'Plugin update available', ['slug' => 'akismet', 'vulnerable' => false], 1_756_000_000),
            ],
        ));

        $this->assertSame([], $scanner->findings());
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function issue(string $type, int $severity, string $shortMsg, array $data, int $time): array
    {
        return [
            'id'       => 1,
            'time'     => $time,
            'type'     => $type,
            'severity' => $severity,
            'shortMsg' => $shortMsg,
            'data'     => serialize($data),
        ];
    }

    /**
     * @param list<string>               $tables
     * @param array<string, string>       $vars
     * @param list<array<string, mixed>>  $rows
     */
    private function db(array $tables = [], array $vars = [], array $rows = []): Database
    {
        return new class ($tables, $vars, $rows) implements Database {
            /**
             * @param list<string>              $tables
             * @param array<string, string>     $vars
             * @param list<array<string,mixed>> $rows
             */
            public function __construct(
                private array $tables,
                private array $vars,
                private array $rows,
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
                foreach ($this->vars as $needle => $value) {
                    if (str_contains($sql, $needle)) {
                        return $value;
                    }
                }

                return null;
            }

            public function getRows(string $sql): array
            {
                return $this->rows;
            }
        };
    }
}
