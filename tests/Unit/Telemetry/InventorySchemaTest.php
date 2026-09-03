<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Tests\Unit\Telemetry;

use Brain\Monkey\Functions;
use TAW\HubCompanion\Telemetry\InventoryReport;
use TAW\HubCompanion\Tests\TestCase;

/**
 * Cross-implementation parity for `GET /inventory` against
 * `tests/fixtures/inventory-snapshot.schema.json` — copied verbatim from the
 * Hub (`Relmaur/taw-hub`, ADR-0013). The schema is the contract; if the Hub
 * changes it, re-copy the file and `InventoryReport` follows.
 *
 * Uses a small hand-rolled validator covering the JSON-Schema subset the
 * contract actually uses (type unions incl. null, required, properties, items,
 * `$ref` into `#/$defs`, enum, integer minimum) — same "small standalone
 * reimplementation, no new Composer dependency" rationale as `LogReader` and
 * `SigningVectorsTest`.
 */
final class InventorySchemaTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $raw = file_get_contents(__DIR__ . '/../../fixtures/inventory-snapshot.schema.json');
        self::assertIsString($raw);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $this->schema = $decoded;
    }

    public function test_the_schema_example_validates_against_the_schema(): void
    {
        /** @var list<mixed> $examples */
        $examples = $this->schema['examples'] ?? [];
        self::assertNotEmpty($examples, 'schema must carry a worked example');

        $this->assertValid($examples[0], $this->schema, '$.examples[0]');
    }

    public function test_a_realistic_inventory_report_payload_validates(): void
    {
        $this->stubWpEnvironment();

        $payload = (new InventoryReport())->collect();

        $this->assertValid($payload, $this->schema, '$');
        self::assertSame(1, $payload['schema_version']);
    }

    public function test_validator_rejects_a_missing_required_key(): void
    {
        /** @var array<string, mixed> $example */
        $example = $this->schema['examples'][0];
        unset($example['php_version']);

        $this->expectException(\RuntimeException::class);
        $this->assertValid($example, $this->schema, '$');
    }

    public function test_validator_rejects_a_bad_enum_value(): void
    {
        /** @var array<string, mixed> $example */
        $example = $this->schema['examples'][0];
        $example['plugins'][0]['update_source'] = 'w.org';

        $this->expectException(\RuntimeException::class);
        $this->assertValid($example, $this->schema, '$');
    }

    private function stubWpEnvironment(): void
    {
        Functions\when('get_bloginfo')->justReturn('6.6.1');
        Functions\when('get_locale')->justReturn('en_US');
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('wp_strip_all_tags')->alias(static fn (string $s): string => trim(strip_tags($s)));
        Functions\when('get_option')->alias(static fn (string $k, $d = false) => $d);
        Functions\when('get_plugins')->justReturn([
            'akismet/akismet.php' => ['Name' => 'Akismet', 'Version' => '5.3', 'Author' => 'Automattic'],
            'hello.php'           => ['Name' => 'Hello Dolly', 'Version' => '1.7.2'],
        ]);
        Functions\when('is_plugin_active')->justReturn(true);
        Functions\when('is_plugin_active_for_network')->justReturn(false);
        Functions\when('get_mu_plugins')->justReturn([
            'taw-mu-loader.php' => ['Name' => 'TAW MU Loader', 'Version' => '1.0.0', 'Author' => 'TAW'],
        ]);
        Functions\when('get_dropins')->justReturn(['object-cache.php' => ['Name' => 'Redis']]);
        Functions\when('wp_get_themes')->justReturn([
            'taw' => ['Name' => 'TAW', 'Version' => '1.22.0', 'Author' => 'TAW'],
        ]);
        Functions\when('get_stylesheet')->justReturn('taw');
        Functions\when('get_template')->justReturn('taw');
        Functions\when('get_site_transient')->justReturn(false);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function assertValid(mixed $value, array $schema, string $path): void
    {
        $this->addToAssertionCount(1);
        $schema = $this->resolveRef($schema);

        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            throw new \RuntimeException("{$path}: " . var_export($value, true) . ' not in enum');
        }

        $types = (array) ($schema['type'] ?? []);
        if ($types !== [] && !$this->matchesAnyType($value, $types)) {
            throw new \RuntimeException("{$path}: expected " . implode('|', $types) . ', got ' . get_debug_type($value));
        }

        if (in_array('integer', $types, true) && is_int($value) && isset($schema['minimum']) && $value < $schema['minimum']) {
            throw new \RuntimeException("{$path}: {$value} below minimum");
        }

        if (is_array($value) && $this->jsonList($value) && isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $i => $item) {
                $this->assertValid($item, $schema['items'], "{$path}[{$i}]");
            }
        }

        if ($this->jsonObject($value) || (is_array($value) && $value === [] && isset($schema['properties']))) {
            /** @var array<string, mixed> $value */
            foreach ((array) ($schema['required'] ?? []) as $key) {
                if (!array_key_exists($key, $value)) {
                    throw new \RuntimeException("{$path}: missing required key '{$key}'");
                }
            }
            /** @var array<string, mixed> $properties */
            $properties = $schema['properties'] ?? [];
            foreach ($properties as $key => $subSchema) {
                if (array_key_exists($key, $value) && is_array($subSchema)) {
                    $this->assertValid($value[$key], $subSchema, "{$path}.{$key}");
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function resolveRef(array $schema): array
    {
        $ref = $schema['$ref'] ?? null;
        if (!is_string($ref) || !str_starts_with($ref, '#/$defs/')) {
            return $schema;
        }

        $name = substr($ref, strlen('#/$defs/'));
        /** @var array<string, array<string, mixed>> $defs */
        $defs = $this->schema['$defs'] ?? [];
        $resolved = $defs[$name] ?? [];

        // A $ref sibling may add a description; the shape comes from the def.
        return array_merge($resolved, array_diff_key($schema, ['$ref' => true]));
    }

    /**
     * @param list<string> $types
     */
    private function matchesAnyType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'null'    => $value === null,
                'string'  => is_string($value),
                'integer' => is_int($value),
                'number'  => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array'   => is_array($value) && ($value === [] || $this->jsonList($value)),
                'object'  => $this->jsonObject($value) || $value === [],
                default   => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private function jsonList(mixed $value): bool
    {
        return is_array($value) && ($value === [] || array_is_list($value));
    }

    private function jsonObject(mixed $value): bool
    {
        return is_array($value) && $value !== [] && !array_is_list($value);
    }
}
