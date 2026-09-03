<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The narrow slice of `$wpdb` the scanner adapters need — a seam so the
 * adapters can be unit-tested without a database. {@see WpdbDatabase} is the
 * real implementation.
 *
 * Adapters only ever pass table names they built from {@see self::basePrefix()}
 * plus a hard-coded scanner table name (never request input), so the raw-SQL
 * surface here is safe.
 */
interface Database
{
    /** `$wpdb->base_prefix` — scanner tables are network-level. */
    public function basePrefix(): string;

    public function tableExists(string $table): bool;

    public function getVar(string $sql): ?string;

    /**
     * @return list<array<array-key, mixed>>
     */
    public function getRows(string $sql): array;
}
