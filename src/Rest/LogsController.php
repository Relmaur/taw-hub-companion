<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Rest;

use TAW\HubCompanion\Logs\LogReader;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `GET /taw-hub/v1/logs` — the structured log `taw/core` writes, served to
 * the Hub verbatim so it can report on a site without SSH.
 *
 * Query params (all optional):
 *   limit   int     1..500, default 100
 *   level   string  debug|info|notice|warning|error|critical
 *   code    string  prefix match on the entry `code`, e.g. "form" or "mail.emailit"
 *   since   string  ISO-8601 timestamp; entries at or after it
 *
 * Returns `{"count": int, "entries": [{ts, level, code, message, context, request_id}, …]}`.
 * Read-only, behind the same signature guard as every other route.
 */
final class LogsController
{
    private const MAX_LIMIT     = 500;
    private const DEFAULT_LIMIT = 100;

    public function __construct(private LogReader $reader)
    {
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $limitParam = $request->get_param('limit');
        $limit = is_numeric($limitParam) ? (int) $limitParam : self::DEFAULT_LIMIT;
        $limit = $limit > 0 ? min($limit, self::MAX_LIMIT) : self::DEFAULT_LIMIT;

        $level = $request->get_param('level');
        $level = is_string($level) && in_array($level, LogReader::LEVELS, true) ? $level : null;

        $code = $request->get_param('code');
        $code = is_string($code) && $code !== '' ? $code : null;

        $since = $request->get_param('since');
        $since = is_string($since) && $since !== '' ? $since : null;

        $entries = $this->reader->tail($limit, $level, $since, $code);

        return new \WP_REST_Response([
            'count'   => count($entries),
            'entries' => $entries,
        ]);
    }
}
