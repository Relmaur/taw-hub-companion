<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Telemetry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The site end of a publish (ADR-0006): taw/core's `TAW\Core\Content\Publish`
 * does all of it (plan, files, apply with a one-time token, undo), in-process.
 * The companion only checks who's asking and whether the site allows it.
 * taw/core isn't a dependency of the companion: the theme loads it.
 *
 * A refusal comes back as a {@see PublishFailure} with taw/core's stable code.
 */
final class PublishReport
{
    public const PUBLISH = 'TAW\\Core\\Content\\Publish';

    /** @var \Closure(array<string, int>): object */
    private \Closure $make;

    /** Whether a maker was given (tests): then taw/core needn't be loaded. */
    private bool $injected;

    /**
     * @param null|\Closure(array<string, int>): object $make what makes the Publish; null = taw/core's
     */
    public function __construct(?\Closure $make = null)
    {
        $this->injected = $make !== null;
        $this->make = $make ?? static function (array $limits): object {
            $class = self::publishClass();
            return new $class($limits);
        };
    }

    /** Whether taw/core 1.98+ (publishing) is loaded on this site. */
    public static function available(): bool
    {
        return class_exists(self::publishClass());
    }

    /** Whether this report can publish: taw/core's Publish is here (or one was given). */
    public function ready(): bool
    {
        return $this->injected || self::available();
    }

    /** taw/core's Publish class; taw/core isn't a dependency of the companion, the theme loads it. */
    private static function publishClass(): string
    {
        return self::PUBLISH;
    }

    /**
     * @param array<string, int> $limits
     * @param array<string, mixed> $changeSet
     * @return array<string, mixed>
     */
    public function plan(array $limits, array $changeSet): array
    {
        return $this->call($limits, 'plan', [$changeSet]);
    }

    /**
     * @param array<string, int> $limits
     * @return array<string, mixed>
     */
    public function stage(array $limits, string $id, string $bytes): array
    {
        return $this->call($limits, 'stage', [$id, $bytes]);
    }

    /**
     * @param array<string, int>          $limits
     * @param list<mixed>                 $approved
     * @param array<string, list<string>> $keep
     * @param array<string, list<string>> $take
     * @return array<string, mixed>
     */
    public function apply(array $limits, string $id, string $token, array $approved, array $keep, array $take): array
    {
        // Written as an administrator's save, like content:import: authors
        // resolve, and a page with no author at the source gets one.
        if (function_exists('get_current_user_id') && get_current_user_id() === 0 && function_exists('get_users')) {
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID']);
            $first = reset($admins);
            if (is_numeric($first) && function_exists('wp_set_current_user')) {
                wp_set_current_user((int) $first);
            }
        }

        return $this->call($limits, 'apply', [$id, $token, $approved, $keep, $take]);
    }

    /**
     * @param array<string, int> $limits
     * @return array<string, mixed>
     */
    public function journals(array $limits): array
    {
        return ['journals' => array_values($this->call($limits, 'journals', []))];
    }

    /**
     * @param array<string, int> $limits
     * @return array<string, mixed>
     */
    public function undo(array $limits, string $journal, bool $dryRun): array
    {
        return $this->call($limits, 'undo', [$journal, $dryRun]);
    }

    /**
     * @param array<string, int> $limits
     * @param list<mixed>        $args
     * @return array<string, mixed>
     */
    private function call(array $limits, string $method, array $args): array
    {
        $out = [];
        foreach ($this->raw($limits, $method, $args) as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, int> $limits
     * @param list<mixed>        $args
     * @return array<mixed>
     */
    private function raw(array $limits, string $method, array $args): array
    {
        $publish = ($this->make)($limits);
        $callable = [$publish, $method];
        if (!is_callable($callable)) {
            throw new \RuntimeException(get_class($publish) . " has no {$method}()");
        }
        try {
            $result = $callable(...$args);
        } catch (\Throwable $e) {
            // taw/core's PublishError: a stable code, a status and what to act on.
            $code = property_exists($e, 'errorCode') ? $e->errorCode : null;
            $status = property_exists($e, 'status') ? $e->status : null;
            if (is_string($code) && is_int($status)) {
                $data = [];
                foreach (property_exists($e, 'data') && is_array($e->data) ? $e->data : [] as $key => $value) {
                    $data[(string) $key] = $value;
                }
                throw new PublishFailure($code, $e->getMessage(), $status, $data);
            }
            throw $e;
        }
        if (!is_array($result)) {
            throw new \RuntimeException(get_class($publish) . "::{$method}() returned no array");
        }

        return $result;
    }
}
