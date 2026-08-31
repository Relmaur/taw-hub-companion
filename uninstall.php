<?php

declare(strict_types=1);

// Fired by WordPress when the plugin is deleted (not merely deactivated).

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// The site keypair and key id.
delete_option('taw_hub_companion_secret_key');
delete_option('taw_hub_companion_public_key');
delete_option('taw_hub_companion_key_id');

// Replay-protection nonces are short-lived transients (≤ 150s) — they expire on
// their own; no cleanup needed here.
