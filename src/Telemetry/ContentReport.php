<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Telemetry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The site's published content as a taw/core Content Interchange snapshot
 * (`TAW\Core\Content\Exporter`): posts and CPT entries by slug, their TAW
 * fields, TAW options, terms and the media they reference. Never drafts,
 * users, comments or settings: this route only reads, and only what's
 * already public or TAW-owned. Runs in-process, so it works on hosts that
 * disable proc_open.
 */
final class ContentReport
{
    public const EXPORTER = 'TAW\\Core\\Content\\Exporter';

    /** @var \Closure(array<string, mixed>): array<string, mixed> */
    private \Closure $export;

    /**
     * @param null|\Closure(array<string, mixed>): array<string, mixed> $export
     *        what makes the snapshot; null = taw/core's Exporter
     */
    public function __construct(?\Closure $export = null)
    {
        $this->export = $export ?? static function (array $scope): array {
            $class = self::exporterClass();
            $call  = [new $class(), 'snapshot'];
            if (!is_callable($call)) {
                throw new \RuntimeException($class . ' has no snapshot()');
            }
            $snapshot = $call($scope);
            if (!is_array($snapshot)) {
                throw new \RuntimeException($class . '::snapshot() returned no array');
            }
            /** @var array<string, mixed> $snapshot */
            return $snapshot;
        };
    }

    /**
     * Whether taw/core's Content Interchange is loaded on this site.
     */
    public static function available(): bool
    {
        return class_exists(self::exporterClass());
    }

    /**
     * The Exporter's class name; taw/core isn't a dependency of the companion,
     * the theme loads it.
     */
    private static function exporterClass(): string
    {
        return self::EXPORTER;
    }

    /**
     * @param list<string> $types post types to limit the snapshot to; [] = all
     * @return array<string, mixed>
     */
    public function snapshot(array $types = []): array
    {
        $scope = ['include_media' => true, 'include_drafts' => false];
        if ($types !== []) {
            $scope['types'] = $types;
        }

        return ($this->export)($scope);
    }
}
