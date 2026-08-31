<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Rest;

use TAW\HubCompanion\Keys\SiteKeypair;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `POST /taw-hub/v1/keys/rotate` — regenerates this site's Ed25519 keypair,
 * keeps the key id, returns `{"public_key": "<base64>"}` (ADR-0003).
 *
 * Behind the signature guard: only a Hub already holding a valid key can
 * trigger a rotation.
 */
final class KeysController
{
    public function __construct(private SiteKeypair $keypair)
    {
    }

    public function handle(): \WP_REST_Response
    {
        return new \WP_REST_Response(['public_key' => $this->keypair->rotate()]);
    }
}
