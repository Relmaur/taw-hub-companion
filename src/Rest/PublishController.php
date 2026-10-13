<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Rest;

use TAW\HubCompanion\Config;
use TAW\HubCompanion\Telemetry\PublishFailure;
use TAW\HubCompanion\Telemetry\PublishReport;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Publishing content to this site (ADR-0006), behind the signature guard like
 * every route, and off unless the theme's composer.json says
 * `extra.taw-companion.publish: true`:
 *
 *   POST /content/publish/plan           a change-set (taw/core 1.98, with its base) → the plan, the files it needs, a one-time token
 *   PUT  /content/publish/{id}/media     one file's raw bytes (application/octet-stream), matched by sha256
 *   POST /content/publish/{id}/apply     {"token", "approved": [{"key", "hash"}], "keep": {…}, "take": {…}}
 *   GET  /content/publish/journals       what was imported, newest first
 *   POST /content/publish/undo           {"journal", "dry_run"}
 *
 * Everything is in the signed body (the query string isn't signed). The work
 * is taw/core's `TAW\Core\Content\Publish`; refusals keep its stable codes:
 * `publish_disabled` (403), `publish_unavailable` (501), `publish_too_large`
 * (413), `publish_invalid`, `publish_unknown`, `publish_expired`,
 * `publish_token_invalid`, `publish_moved`, `publish_conflicts_unresolved`,
 * `publish_media_missing`, `publish_media_unexpected`, `publish_busy`.
 */
final class PublishController
{
    public function __construct(private Config $config, private PublishReport $report)
    {
    }

    /** @param \WP_REST_Request<array<string, mixed>> $request */
    public function plan(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->guarded($request, function (array $limits) use ($request): array {
            $decoded = json_decode($request->get_body(), true);
            if (!is_array($decoded)) {
                throw new PublishFailure('publish_invalid', 'The body must be a change-set (JSON).', 400);
            }
            $changeSet = [];
            foreach ($decoded as $key => $value) {
                $changeSet[(string) $key] = $value;
            }

            return $this->report->plan($limits, $changeSet);
        }, 'max_plan_bytes');
    }

    /** @param \WP_REST_Request<array<string, mixed>> $request */
    public function media(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->guarded($request, fn (array $limits): array => $this->report->stage($limits, self::id($request), $request->get_body()), 'max_file_bytes');
    }

    /** @param \WP_REST_Request<array<string, mixed>> $request */
    public function apply(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->guarded($request, function (array $limits) use ($request): array {
            $body = json_decode($request->get_body(), true);
            $body = is_array($body) ? $body : [];

            return $this->report->apply(
                $limits,
                self::id($request),
                is_string($body['token'] ?? null) ? $body['token'] : '',
                is_array($body['approved'] ?? null) ? array_values($body['approved']) : [],
                self::keyLists($body['keep'] ?? null),
                self::keyLists($body['take'] ?? null),
            );
        }, 'max_plan_bytes');
    }

    /** @param \WP_REST_Request<array<string, mixed>> $request */
    public function journals(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->guarded($request, fn (array $limits): array => $this->report->journals($limits), 'max_plan_bytes');
    }

    /** @param \WP_REST_Request<array<string, mixed>> $request */
    public function undo(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->guarded($request, function (array $limits) use ($request): array {
            $body = json_decode($request->get_body(), true);
            $body = is_array($body) ? $body : [];

            return $this->report->undo($limits, is_string($body['journal'] ?? null) ? $body['journal'] : '', ($body['dry_run'] ?? true) !== false);
        }, 'max_plan_bytes');
    }

    /**
     * Available, enabled, not too large; then the step, its refusal as an answer.
     *
     * @param \WP_REST_Request<array<string, mixed>>        $request
     * @param \Closure(array<string, int>): array<mixed> $step
     */
    private function guarded(\WP_REST_Request $request, \Closure $step, string $sizeLimit): \WP_REST_Response
    {
        if (!$this->report->ready()) {
            return self::refused('publish_unavailable', "Publishing needs taw/core 1.98 or newer on this site: update the theme's taw/core and deploy.", 501);
        }
        if (!$this->config->publishEnabled()) {
            return self::refused('publish_disabled', "Publishing isn't enabled for this site: set extra.taw-companion.publish to true in the theme's composer.json, and deploy.", 403);
        }
        $limits = $this->config->publishLimits();
        if (strlen($request->get_body()) > $limits[$sizeLimit]) {
            return self::refused('publish_too_large', 'The request is larger than this site accepts (' . (int) ($limits[$sizeLimit] / 1024 / 1024) . ' MB).', 413);
        }
        try {
            return new \WP_REST_Response($step($limits));
        } catch (PublishFailure $e) {
            return self::refused($e->errorCode, $e->getMessage(), $e->status, $e->data);
        } catch (\Throwable) {
            return self::refused('publish_failed', 'The publish stopped on this site: its log says why.', 500);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function refused(string $code, string $message, int $status, array $data = []): \WP_REST_Response
    {
        return new \WP_REST_Response(['error' => $code, 'message' => $message] + $data, $status);
    }

    /** @param \WP_REST_Request<array<string, mixed>> $request */
    private static function id(\WP_REST_Request $request): string
    {
        $id = $request->get_param('id');

        return is_string($id) ? $id : '';
    }

    /**
     * `{record key: [change key, …]}`, strings only.
     *
     * @return array<string, list<string>>
     */
    private static function keyLists(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $record => $keys) {
            foreach (is_array($keys) ? $keys : [] as $key) {
                if (is_string($key) && $key !== '') {
                    $out[(string) $record][] = $key;
                }
            }
        }

        return $out;
    }
}
