<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Cli;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The allow-list for `POST /taw`. Kept deliberately independent of the Hub's
 * own `TawCommandAllowlist` — the Hub checks in the coordinator, this checks
 * again at the site (defence in depth). Read-mostly commands only by default;
 * `fields:set` and the raw `wp` passthrough are NOT included.
 *
 * Filter to adjust per-site: `add_filter('taw_hub_companion_taw_allowlist', …)`.
 */
final class CommandAllowlist
{
    private const DEFAULT = [
        'sync',
        'inspect',
        'seo:extract',
        'seo:inject',
        'icons:sync',
        'export:static',
    ];

    public function allows(string $command): bool
    {
        return in_array($command, $this->all(), true);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        $list = self::DEFAULT;

        if (function_exists('apply_filters')) {
            /** @var mixed $filtered */
            $filtered = apply_filters('taw_hub_companion_taw_allowlist', $list);
            if (is_array($filtered)) {
                $list = array_values(array_filter($filtered, 'is_string'));
            }
        }

        return $list;
    }
}
