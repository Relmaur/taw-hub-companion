<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit\Telemetry;

use TAW\HubCompanion\Telemetry\ContentReport;
use TAW\HubCompanion\Tests\TestCase;

final class ContentReportTest extends TestCase
{
    public function test_asks_for_published_content_with_media_only(): void
    {
        $scopes = [];
        $report = new ContentReport(static function (array $scope) use (&$scopes): array {
            $scopes[] = $scope;

            return ['meta' => ['schema' => '1.1'], 'posts' => []];
        });

        $this->assertSame(['meta' => ['schema' => '1.1'], 'posts' => []], $report->snapshot());
        $report->snapshot(['page', 'post']);

        $this->assertSame([
            ['include_media' => true, 'include_drafts' => false],
            ['include_media' => true, 'include_drafts' => false, 'types' => ['page', 'post']],
        ], $scopes, 'never drafts, users, comments or settings');
    }

    public function test_unavailable_without_taw_core(): void
    {
        // The unit tests don't load taw/core.
        $this->assertFalse(ContentReport::available());
    }
}
