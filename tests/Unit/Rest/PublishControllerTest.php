<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit\Rest;

use Brain\Monkey\Functions;
use TAW\HubCompanion\Config;
use TAW\HubCompanion\Rest\PublishController;
use TAW\HubCompanion\Telemetry\PublishReport;
use TAW\HubCompanion\Tests\TestCase;

require_once __DIR__ . '/../../fixtures/rest-fakes.php';

/**
 * The publish routes (ADR-0006): off unless the theme enables them, taw/core's
 * Publish does the work, and its refusals keep their codes and statuses.
 */
final class PublishControllerTest extends TestCase
{
    private string $theme;
    private FakePublish $publish;

    /** @var list<int> */
    private array $usersSet = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->theme = sys_get_temp_dir() . '/taw-companion-publish-' . bin2hex(random_bytes(4));
        mkdir($this->theme, 0o777, true);
        Functions\when('get_template_directory')->justReturn($this->theme);
        Functions\when('get_stylesheet_directory')->justReturn($this->theme);
        Functions\when('get_current_user_id')->justReturn(0);
        Functions\when('get_users')->justReturn([3]);
        Functions\when('wp_set_current_user')->alias(function (int $id): void { $this->usersSet[] = $id; });
        $this->publish = new FakePublish();
        $this->enable(true);
    }

    private function enable(bool $on): void
    {
        file_put_contents($this->theme . '/composer.json', (string) json_encode(['extra' => ['taw-companion' => ['publish' => $on]]]));
    }

    private function controller(?PublishReport $report = null): PublishController
    {
        return new PublishController(new Config(), $report ?? new PublishReport(fn (array $limits): object => $this->publish->withLimits($limits)));
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function answer(\WP_REST_Response $response): array
    {
        $data = $response->get_data();

        return [$response->get_status(), is_array($data) ? $data : []];
    }

    public function test_without_taw_core_publishing_is_unavailable(): void
    {
        [$status, $body] = $this->answer($this->controller(new PublishReport())->plan(new \WP_REST_Request('{}')));

        $this->assertSame(501, $status);
        $this->assertSame('publish_unavailable', $body['error']);
    }

    public function test_off_until_the_theme_enables_it(): void
    {
        $this->enable(false);
        [$status, $body] = $this->answer($this->controller()->plan(new \WP_REST_Request('{"taw_changeset":{}}')));

        $this->assertSame(403, $status);
        $this->assertSame('publish_disabled', $body['error']);
        $this->assertSame([], $this->publish->calls, 'nothing reached taw/core');
    }

    public function test_plan_hands_the_change_set_to_taw_core_with_the_sites_limits(): void
    {
        [$status, $body] = $this->answer($this->controller()->plan(new \WP_REST_Request('{"taw_changeset":{"schema":"1.8"},"operations":[]}')));

        $this->assertSame(200, $status);
        $this->assertSame('abcdabcdabcdabcd', $body['publish_id']);
        $this->assertSame(['plan', [['taw_changeset' => ['schema' => '1.8'], 'operations' => []]]], $this->publish->calls[0]);
        $this->assertSame(32 * 1024 * 1024, $this->publish->limits['max_file_bytes']);
        $this->assertSame(3600, $this->publish->limits['ttl']);
    }

    public function test_a_body_that_isnt_json_is_refused(): void
    {
        [$status, $body] = $this->answer($this->controller()->plan(new \WP_REST_Request('not json')));

        $this->assertSame(400, $status);
        $this->assertSame('publish_invalid', $body['error']);
    }

    public function test_a_file_larger_than_the_limit_is_refused_before_taw_core(): void
    {
        Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $value);
        $big = str_repeat('x', 32 * 1024 * 1024 + 1);
        [$status, $body] = $this->answer($this->controller()->media(new \WP_REST_Request($big, ['id' => 'abcdabcdabcdabcd'])));

        $this->assertSame(413, $status);
        $this->assertSame('publish_too_large', $body['error']);
        $this->assertSame([], $this->publish->calls);
    }

    public function test_a_file_goes_to_taw_core_as_its_bytes(): void
    {
        [$status] = $this->answer($this->controller()->media(new \WP_REST_Request("\x00\x01binary", ['id' => 'abcdabcdabcdabcd'])));

        $this->assertSame(200, $status);
        $this->assertSame(['stage', ['abcdabcdabcdabcd', "\x00\x01binary"]], $this->publish->calls[0]);
    }

    public function test_apply_passes_the_decisions_as_strings_only_and_as_an_administrator(): void
    {
        $body = (string) json_encode(['token' => 't0k3n', 'approved' => [['key' => 'post:page:about', 'hash' => 'h']],
            'keep' => ['post:page:about' => ['title', 5, '']], 'take' => ['post:page:about' => ['fields.f_text']], 'extra' => 'ignored']);

        [$status] = $this->answer($this->controller()->apply(new \WP_REST_Request($body, ['id' => 'abcdabcdabcdabcd'])));

        $this->assertSame(200, $status);
        $this->assertSame([3], $this->usersSet, 'as the first administrator');
        $this->assertSame(['apply', ['abcdabcdabcdabcd', 't0k3n', [['key' => 'post:page:about', 'hash' => 'h']],
            ['post:page:about' => ['title']], ['post:page:about' => ['fields.f_text']]]], $this->publish->calls[0]);
    }

    public function test_taw_cores_refusals_keep_their_code_status_and_data(): void
    {
        $this->publish->refuse = ['publish_moved', 409, ['moved' => [['key' => 'post:page:about']]]];
        [$status, $body] = $this->answer($this->controller()->apply(new \WP_REST_Request('{"token":"x","approved":[]}', ['id' => 'abcdabcdabcdabcd'])));

        $this->assertSame(409, $status);
        $this->assertSame('publish_moved', $body['error']);
        $this->assertSame([['key' => 'post:page:about']], $body['moved']);
        $this->assertSame('refused: publish_moved', $body['message']);
    }

    public function test_anything_else_is_a_failure_that_says_nothing_more(): void
    {
        $this->publish->crash = true;
        [$status, $body] = $this->answer($this->controller()->plan(new \WP_REST_Request('{}')));

        $this->assertSame(500, $status);
        $this->assertSame('publish_failed', $body['error']);
        $this->assertStringNotContainsString('database password', (string) json_encode($body));
    }

    public function test_journals_and_undo(): void
    {
        [, $journals] = $this->answer($this->controller()->journals(new \WP_REST_Request('')));
        [, $undo] = $this->answer($this->controller()->undo(new \WP_REST_Request('{"journal":"import-1.json"}')));

        $this->assertSame([['file' => 'import-1.json']], $journals['journals']);
        $this->assertSame(['undo', ['import-1.json', true]], $this->publish->calls[1], 'a dry run unless it says otherwise');
        $this->assertSame(1, $undo['restored']);
    }

    public function test_the_child_themes_word_wins(): void
    {
        $child = $this->theme . '-child';
        mkdir($child);
        Functions\when('get_stylesheet_directory')->justReturn($child);
        file_put_contents($child . '/composer.json', (string) json_encode(['extra' => ['taw-companion' => ['publish' => false]]]));

        $this->assertFalse((new Config())->publishEnabled(), 'the child turns it off');

        file_put_contents($child . '/composer.json', '{"name":"acme/child"}');
        $this->assertTrue((new Config())->publishEnabled(), 'silent child: the parent\'s word');

        file_put_contents($this->theme . '/composer.json', (string) json_encode(['extra' => ['taw-companion' => ['publish' => 'yes']]]));
        $this->assertFalse((new Config())->publishEnabled(), 'only true enables it');
    }
}

/** Stands in for taw/core's Publish: records calls, refuses like PublishError when told to. */
final class FakePublish
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    public array $calls = [];

    /** @var array<string, int> */
    public array $limits = [];

    /** @var array{0: string, 1: int, 2: array<string, mixed>}|null */
    public ?array $refuse = null;

    public bool $crash = false;

    /** @param array<string, int> $limits */
    public function withLimits(array $limits): self
    {
        $this->limits = $limits;

        return $this;
    }

    /** @param list<mixed> $args */
    private function call(string $method, array $args): void
    {
        $this->calls[] = [$method, $args];
        if ($this->crash) {
            throw new \RuntimeException('database password is hunter2');
        }
        if ($this->refuse !== null) {
            throw new FakePublishError(...$this->refuse);
        }
    }

    /** @return array<string, mixed> */
    public function plan(array $changeSet): array
    {
        $this->call('plan', [$changeSet]);

        return ['publish_id' => 'abcdabcdabcdabcd', 'token' => 't', 'plan' => []];
    }

    /** @return array<string, mixed> */
    public function stage(string $id, string $bytes): array
    {
        $this->call('stage', [$id, $bytes]);

        return ['staged' => ['file' => 'x.jpg'], 'remaining' => 0];
    }

    /** @return array<string, mixed> */
    public function apply(string $id, string $token, array $approved, array $keep, array $take): array
    {
        $this->call('apply', [$id, $token, $approved, $keep, $take]);

        return ['report' => [], 'applied' => []];
    }

    /** @return list<array<string, mixed>> */
    public function journals(): array
    {
        $this->call('journals', []);

        return [['file' => 'import-1.json']];
    }

    /** @return array<string, mixed> */
    public function undo(string $journal, bool $dryRun): array
    {
        $this->call('undo', [$journal, $dryRun]);

        return ['restored' => 1];
    }
}

/** Shaped like taw/core's PublishError. */
final class FakePublishError extends \RuntimeException
{
    /** @param array<string, mixed> $data */
    public function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $data = [])
    {
        parent::__construct('refused: ' . $errorCode);
    }
}
