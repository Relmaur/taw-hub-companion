<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads Wordfence's stored scan results from the `wfIssues` table.
 *
 * Wordfence records each finding as a row with a `type`, a numeric `severity`
 * (0/25/50/75/100), and a PHP-serialized `data` blob (the plugin/theme/core
 * array, plus `vulnerable`, `cvssScore`, `cvssVector`, `vulnerabilityLink` when
 * a known vulnerability applies). Verified against Wordfence 8.2.x — the
 * `wfIssues` schema and `wfConfig` `scanTime` key have been stable for years,
 * and this reader degrades to `[]` if the shape ever changes.
 *
 *   wfPluginVulnerable → a known vulnerability in the installed version
 *   wfPluginAbandoned  → not updated in ~2 years (also `vulnerable` if both)
 *   wfPluginRemoved    → pulled from the .org directory
 *   wfPluginUpgrade / wfThemeUpgrade / wfUpgrade → update available; only
 *                        reported here when `data.vulnerable` is set
 */
final class WordfenceScanner implements SecurityScanner
{
    private const NAME = 'wordfence';

    /** @var array<string, string|null> resolved scanner table names, keyed by base */
    private array $tableCache = [];

    /**
     * Security issue types → [component_type, kind]. Always reported.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SECURITY_TYPES = [
        'wfPluginVulnerable' => ['plugin', 'vulnerability'],
        'wfPluginAbandoned'  => ['plugin', 'abandoned'],
        'wfPluginRemoved'    => ['plugin', 'removed'],
    ];

    /**
     * "Update available" issue types → component_type. Reported here *only* when
     * the row also carries `data.vulnerable` (a plain outdated component is
     * already covered by the `/inventory` route's `update_version`).
     *
     * @var array<string, string>
     */
    private const UPDATE_TYPES = [
        'wfPluginUpgrade' => 'plugin',
        'wfThemeUpgrade'  => 'theme',
        'wfUpgrade'       => 'core',
    ];

    public function __construct(private Database $db)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function isAvailable(): bool
    {
        return defined('WORDFENCE_VERSION') || $this->table('wfIssues') !== null;
    }

    public function version(): ?string
    {
        if (!defined('WORDFENCE_VERSION')) {
            return null;
        }

        $version = constant('WORDFENCE_VERSION');

        return is_scalar($version) ? (string) $version : null;
    }

    public function lastScanAt(): ?string
    {
        $config = $this->table('wfConfig');
        if ($config === null) {
            return null;
        }

        $raw = $this->db->getVar("SELECT val FROM `{$config}` WHERE name = 'scanTime' LIMIT 1");
        if ($raw === null) {
            return null;
        }

        $timestamp = (int) (float) $raw; // Wordfence stores microtime(true)

        return $timestamp > 0 ? gmdate('c', $timestamp) : null;
    }

    /**
     * @return list<VulnerabilityFinding>
     */
    public function findings(): array
    {
        $issues = $this->table('wfIssues');
        if ($issues === null) {
            return [];
        }

        $rows = $this->db->getRows(
            "SELECT id, time, type, severity, shortMsg, data FROM `{$issues}` WHERE status = 'new'",
        );

        $out = [];
        foreach ($rows as $row) {
            $finding = $this->toFinding($row);
            if ($finding !== null) {
                $out[] = $finding;
            }
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function toFinding(array $row): ?VulnerabilityFinding
    {
        $type = is_string($row['type'] ?? null) ? $row['type'] : '';
        $data = $this->decode($row['data'] ?? null);
        $vulnerable = !empty($data['vulnerable']);

        if (isset(self::SECURITY_TYPES[$type])) {
            [$componentType, $kind] = self::SECURITY_TYPES[$type];
            if ($vulnerable && $kind === 'abandoned') {
                $kind = 'vulnerability';
            }
        } elseif (isset(self::UPDATE_TYPES[$type]) && $vulnerable) {
            $componentType = self::UPDATE_TYPES[$type];
            $kind = 'vulnerability';
        } elseif ($vulnerable) {
            $componentType = 'unknown';
            $kind = 'vulnerability';
        } else {
            return null;
        }

        $cvss = isset($data['cvssScore']) && is_numeric($data['cvssScore'])
            ? round((float) $data['cvssScore'], 1)
            : null;

        return new VulnerabilityFinding(
            scanner: self::NAME,
            componentType: $componentType,
            slug: $this->str($data['slug'] ?? null),
            componentFile: $this->pluginFile($data['pluginFile'] ?? null),
            installedVersion: $this->str($data['Version'] ?? ($data['version'] ?? null)),
            severity: $cvss !== null ? Severity::fromCvss($cvss) : self::wfLabel($row['severity'] ?? null),
            cvssScore: $cvss,
            cvssVector: $this->str($data['cvssVector'] ?? null),
            kind: $kind,
            title: $this->title($row['shortMsg'] ?? null),
            link: $this->str($data['vulnerabilityLink'] ?? ($data['link'] ?? null)),
            detectedAt: $this->timeIso($row['time'] ?? null),
            scannerRef: isset($row['id']) && is_scalar($row['id']) ? (string) $row['id'] : null,
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $value = @unserialize($raw, ['allowed_classes' => false]);

        return is_array($value) ? $value : [];
    }

    private function pluginFile(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        // Wordfence stores an absolute path; reduce to the `folder/entry.php` key.
        $pos = strrpos($raw, '/plugins/');
        $rel = $pos !== false ? substr($raw, $pos + strlen('/plugins/')) : $raw;
        $rel = ltrim($rel, '/');

        return str_contains($rel, '/') || str_ends_with($rel, '.php') ? $rel : null;
    }

    private function title(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $clean = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5));

        return $clean !== '' ? $clean : null;
    }

    private function timeIso(mixed $raw): ?string
    {
        $timestamp = is_numeric($raw) ? (int) $raw : 0;

        return $timestamp > 0 ? gmdate('c', $timestamp) : null;
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function wfLabel(mixed $severity): string
    {
        return match ((int) (is_scalar($severity) ? $severity : 0)) {
            100     => 'critical',
            75      => 'high',
            50      => 'medium',
            25      => 'low',
            default => 'unknown',
        };
    }

    /**
     * Resolve a Wordfence table name, tolerating the lowercase-schema variant
     * (`wfissues`) introduced in newer Wordfence versions.
     */
    private function table(string $base): ?string
    {
        if (array_key_exists($base, $this->tableCache)) {
            return $this->tableCache[$base];
        }

        $prefix = $this->db->basePrefix();
        foreach ([$prefix . $base, $prefix . strtolower($base)] as $candidate) {
            if ($this->db->tableExists($candidate)) {
                return $this->tableCache[$base] = $candidate;
            }
        }

        return $this->tableCache[$base] = null;
    }
}
