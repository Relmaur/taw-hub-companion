<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * {@see Database} backed by WordPress's global `$wpdb`. A thin pass-through —
 * the interesting logic lives in the scanner adapters, which take the
 * {@see Database} seam so they stay unit-testable.
 */
final class WpdbDatabase implements Database
{
    public function __construct(private ?\wpdb $db)
    {
    }

    public static function fromGlobals(): self
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;

        return new self($wpdb instanceof \wpdb ? $wpdb : null);
    }

    public function basePrefix(): string
    {
        return $this->db instanceof \wpdb ? (string) $this->db->base_prefix : '';
    }

    public function tableExists(string $table): bool
    {
        if (!$this->db instanceof \wpdb) {
            return false;
        }

        $found = $this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s', $table));

        return is_string($found);
    }

    public function getVar(string $sql): ?string
    {
        if (!$this->db instanceof \wpdb) {
            return null;
        }

        $value = $this->db->get_var($sql);

        return $value === null ? null : (string) $value;
    }

    public function getRows(string $sql): array
    {
        if (!$this->db instanceof \wpdb) {
            return [];
        }

        $rows = $this->db->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = $row;
        }

        return $out;
    }
}
