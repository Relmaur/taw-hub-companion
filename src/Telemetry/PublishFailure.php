<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Telemetry;

if (!defined('ABSPATH')) {
    exit;
}

/** A publish step refused, with taw/core's stable code (see {@see PublishReport}). */
final class PublishFailure extends \RuntimeException
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status, public readonly array $data = [])
    {
        parent::__construct($message);
    }
}
