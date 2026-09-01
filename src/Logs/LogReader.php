<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Logs;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads the structured log that `taw/core`'s `TAW\Core\Log\JsonlFileSink`
 * writes to `wp-content/taw-logs/taw.log.jsonl`.
 *
 * Deliberately a small standalone reimplementation rather than a Composer
 * dependency on `taw/core` — the companion plugin already talks to the
 * framework only through `bin/taw` (see {@see \TAW\HubCompanion\Cli\TawRunner}),
 * and both run in the same WordPress process, so `WP_CONTENT_DIR` resolves
 * identically on either side. If `taw/core` is absent or has never logged,
 * the file simply doesn't exist yet and this returns `[]`.
 */
final class LogReader
{
    private const FILE = 'taw.log.jsonl';

    /** @var list<string> */
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];

    public function __construct(private string $directory)
    {
    }

    public static function default(): self
    {
        $base = defined('WP_CONTENT_DIR') && WP_CONTENT_DIR !== ''
            ? WP_CONTENT_DIR
            : sys_get_temp_dir();

        return new self(rtrim((string) $base, '/') . '/taw-logs');
    }

    /**
     * @return list<array<string, mixed>> Chronological — oldest first, newest last.
     */
    public function tail(int $limit = 100, ?string $level = null, ?string $since = null, ?string $codePrefix = null): array
    {
        if ($limit <= 0) {
            return [];
        }

        $file = $this->directory . '/' . self::FILE;
        if (!is_file($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $entries = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }
            if ($level !== null && ($decoded['level'] ?? null) !== $level) {
                continue;
            }
            if ($since !== null) {
                $ts = $decoded['ts'] ?? null;
                if (!is_string($ts) || $ts < $since) {
                    continue;
                }
            }
            if ($codePrefix !== null) {
                $code = $decoded['code'] ?? null;
                if (!is_string($code) || !str_starts_with($code, $codePrefix)) {
                    continue;
                }
            }

            /** @var array<string, mixed> $decoded */
            $entries[] = $decoded;
        }

        return array_slice($entries, -$limit);
    }
}
