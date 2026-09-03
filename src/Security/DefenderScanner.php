<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads WPMU DEV Defender / Defender Pro's stored scan results from its
 * `defender_scan` + `defender_scan_item` tables.
 *
 * Defender records one row per scan (`defender_scan`, latest = highest `id`
 * with status `finish`/`idle`) and one row per issue (`defender_scan_item`,
 * `parent_id` → the scan, `status` `active`/`ignore`, `raw_data` a JSON blob).
 * Verified against Defender 6.2.4. The three security-relevant item types:
 *
 *   vulnerability    → known CVE(s); raw_data.type is wp_core|plugin|theme,
 *                      raw_data.bugs[] each { vuln_type, title, ref, fixed_in,
 *                      cvss_score }. One companion finding per bug.
 *   plugin_closed    → plugin pulled from the .org directory → kind `removed`
 *   plugin_outdated  → plugin not maintained (Defender "abandoned") → kind `abandoned`
 *
 * Degrades to `[]` if the schema or JSON shape ever changes.
 */
final class DefenderScanner implements SecurityScanner
{
    private const NAME = 'defender';

    /** @var array<string, string|null> resolved table names, keyed by base */
    private array $tableCache = [];

    public function __construct(private Database $db)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function isAvailable(): bool
    {
        return defined('DEFENDER_VERSION') || $this->table('defender_scan_item') !== null;
    }

    public function version(): ?string
    {
        if (!defined('DEFENDER_VERSION')) {
            return null;
        }

        $version = constant('DEFENDER_VERSION');

        return is_scalar($version) ? (string) $version : null;
    }

    public function lastScanAt(): ?string
    {
        return $this->latestScan()['date'] ?? null;
    }

    /**
     * @return list<VulnerabilityFinding>
     */
    public function findings(): array
    {
        $scan  = $this->latestScan();
        $items = $this->table('defender_scan_item');
        if ($scan === null || $items === null) {
            return [];
        }

        $rows = $this->db->getRows(
            "SELECT id, type, raw_data FROM `{$items}` "
            . "WHERE parent_id = {$scan['id']} AND status = 'active' "
            . "AND type IN ('vulnerability', 'plugin_closed', 'plugin_outdated')",
        );

        $out = [];
        foreach ($rows as $row) {
            foreach ($this->toFindings($row, $scan['date']) as $finding) {
                $out[] = $finding;
            }
        }

        return $out;
    }

    /**
     * @return array{id: int, date: ?string}|null
     */
    private function latestScan(): ?array
    {
        $scans = $this->table('defender_scan');
        if ($scans === null) {
            return null;
        }

        $rows = $this->db->getRows(
            "SELECT id, date_end, date_start FROM `{$scans}` "
            . "WHERE status IN ('finish', 'idle') ORDER BY id DESC LIMIT 1",
        );
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $id  = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
        if ($id <= 0) {
            return null;
        }

        $when = self::str($row['date_end'] ?? null) ?? self::str($row['date_start'] ?? null);

        return ['id' => $id, 'date' => self::toIso($when)];
    }

    /**
     * @param array<array-key, mixed> $row
     * @return list<VulnerabilityFinding>
     */
    private function toFindings(array $row, ?string $scanDate): array
    {
        $type   = is_string($row['type'] ?? null) ? $row['type'] : '';
        $data   = $this->decode($row['raw_data'] ?? null);
        $itemId = isset($row['id']) && is_scalar($row['id']) ? (string) $row['id'] : '0';

        return match ($type) {
            'vulnerability'   => $this->vulnFindings($data, $itemId, $scanDate),
            'plugin_closed'   => [$this->abandonedFinding($data, 'removed', 'high', $itemId, $scanDate)],
            'plugin_outdated' => [$this->abandonedFinding($data, 'abandoned', 'medium', $itemId, $scanDate)],
            default           => [],
        };
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<VulnerabilityFinding>
     */
    private function vulnFindings(array $data, string $itemId, ?string $scanDate): array
    {
        $componentType = match (is_string($data['type'] ?? null) ? $data['type'] : '') {
            'wp_core' => 'core',
            'plugin'  => 'plugin',
            'theme'   => 'theme',
            default   => 'unknown',
        };

        $isCore        = $componentType === 'core';
        $componentFile = $isCore ? null : self::str($data['slug'] ?? null);       // Defender stores the plugin file here
        $slug          = $isCore ? null : self::str($data['base_slug'] ?? null);  // …and the folder here
        $version       = self::str($data['version'] ?? null);
        $name          = self::str($data['name'] ?? null);

        $bugs = is_array($data['bugs'] ?? null) ? $data['bugs'] : [];
        $out  = [];
        $index = 0;
        foreach ($bugs as $bug) {
            if (is_array($bug)) {
                $cvss = isset($bug['cvss_score']) && is_numeric($bug['cvss_score']) && (float) $bug['cvss_score'] > 0.0
                    ? round((float) $bug['cvss_score'], 1)
                    : null;

                $title    = self::str($bug['title'] ?? null);
                $vulnType = self::str($bug['vuln_type'] ?? null);

                $out[] = new VulnerabilityFinding(
                    scanner: self::NAME,
                    componentType: $componentType,
                    slug: $slug,
                    componentFile: $componentFile,
                    installedVersion: $version,
                    severity: $cvss !== null ? Severity::fromCvss($cvss) : 'unknown',
                    cvssScore: $cvss,
                    cvssVector: null,
                    kind: 'vulnerability',
                    title: self::composeTitle($vulnType, $title, $name),
                    link: $this->firstRef($bug['ref'] ?? null),
                    detectedAt: $scanDate,
                    scannerRef: 'def:' . $itemId . ':' . $index,
                );
            }
            $index++;
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function abandonedFinding(
        array $data,
        string $kind,
        string $severity,
        string $itemId,
        ?string $scanDate,
    ): VulnerabilityFinding {
        $name   = self::str($data['name'] ?? null);
        $reason = self::str($data['reason_text'] ?? null);
        $suffix = $name !== null ? ': ' . $name : '';
        $title  = $reason ?? ($kind === 'removed'
            ? 'Removed from the WordPress.org plugin directory' . $suffix
            : 'Plugin appears abandoned' . $suffix);

        return new VulnerabilityFinding(
            scanner: self::NAME,
            componentType: 'plugin',
            slug: self::str($data['slug'] ?? null),
            componentFile: null,
            installedVersion: self::str($data['version'] ?? null),
            severity: $severity,
            cvssScore: null,
            cvssVector: null,
            kind: $kind,
            title: $title,
            link: self::str($data['url'] ?? null),
            detectedAt: $scanDate,
            scannerRef: 'def:' . $itemId,
        );
    }

    private static function composeTitle(?string $vulnType, ?string $title, ?string $name): ?string
    {
        if ($title !== null) {
            return $vulnType !== null ? $vulnType . ': ' . $title : $title;
        }

        return $name !== null ? 'Vulnerability in ' . $name : $vulnType;
    }

    private function firstRef(mixed $ref): ?string
    {
        if (is_string($ref)) {
            return $ref !== '' ? $ref : null;
        }
        if (is_array($ref)) {
            foreach ($ref as $entry) {
                if (is_string($entry) && $entry !== '') {
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $value = json_decode($raw, true);

        return is_array($value) ? $value : [];
    }

    private static function toIso(?string $mysqlUtc): ?string
    {
        if ($mysqlUtc === null) {
            return null;
        }

        // Defender writes gmdate('Y-m-d H:i:s') — always UTC.
        $timestamp = strtotime($mysqlUtc . ' UTC');

        return $timestamp !== false ? gmdate('c', $timestamp) : null;
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function table(string $base): ?string
    {
        if (array_key_exists($base, $this->tableCache)) {
            return $this->tableCache[$base];
        }

        $candidate = $this->db->basePrefix() . $base;

        return $this->tableCache[$base] = $this->db->tableExists($candidate) ? $candidate : null;
    }
}
