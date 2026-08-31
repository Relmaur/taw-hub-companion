# TAW Hub Companion

The signed `wp-json/taw-hub/v1/` receiver a TAW-framework WordPress site exposes to the
[**TAW Hub**](https://github.com/Relmaur/taw-hub) control hub. No passwords — every request
is verified against the Hub's Ed25519 key per the Hub's
[**ADR-0003**](https://github.com/Relmaur/taw-hub/blob/main/docs/ADR/0003-wire-protocol-and-signatures.md)
wire protocol. Plugin architecture:
[**ADR-0005**](https://github.com/Relmaur/taw-hub/blob/main/docs/ADR/0005-companion-plugin-architecture.md).

> Was briefly `TAW\Hub` inside `taw/core` v1.20.0. That was the wrong home (theme framework
> ≠ fleet-management control plane) and the wrong protocol (independently invented, not
> ADR-0003). Reverted in `taw/core` v1.20.1; rebuilt here.

## Install

1. Drop the plugin in `wp-content/plugins/taw-hub-companion/` and run `composer install`
   (or ship the built plugin with `vendor/` included).
2. Define these in **`wp-config.php`** (never the options table):

   ```php
   define('TAW_HUB_PUBLIC_KEY', '…base64 Ed25519 public key from the Hub…'); // required
   define('TAW_HUB_KEY_ID',     'hub-prod');                                // optional — expected inbound key id
   // define('TAW_HUB_HMAC_SECRET', '…');       // optional — the HMAC (n8n) channel
   // define('TAW_HUB_ALLOWED_IPS', '203.0.113.4, 203.0.113.5');  // optional — source-IP allow-list
   // define('TAW_HUB_SITE_KEY_ID', 'site-abc123');               // optional — override the site's own key id
   ```

3. Activate. The plugin generates this site's own Ed25519 keypair.
4. Register the site with the Hub: `GET /wp-json/taw-hub/v1/health` returns
   `site_public_key` + `site_key_id` (also shown in an admin notice). Give those to the
   Hub operator (`RegisterSite`).

Without `TAW_HUB_PUBLIC_KEY` the plugin is **inert** — every route returns `501` and an
admin notice explains what to define.

## Routes (`taw-hub/v1`)

| Method | Route | Body | Returns |
|---|---|---|---|
| `GET` | `/health` | — | `{ ok, php_version, wp_version, taw_core_version, companion_version, site_public_key, site_key_id }` |
| `POST` | `/framework/sync` | `{ "dry_run": bool }` | the `php bin/taw sync --json` report verbatim |
| `POST` | `/taw` | `{ "command": string, "args": string[] }` | `{ exit_code, stdout, stderr }` — allow-listed commands only |
| `POST` | `/keys/rotate` | — | `{ "public_key": "<base64>" }` — new keypair, same key id |

`/taw` allow-list (filter `taw_hub_companion_taw_allowlist` to change):
`sync inspect seo:extract seo:inject icons:sync export:static`. **Not** `fields:set`, **not**
the raw `wp` passthrough. `/framework/sync` drives the *existing* `bin/taw sync` — it does
not reinvent the per-site sync logic.

## The wire protocol (ADR-0003)

Every request except errors is signed. Canonical string (`\n`-joined, **no** trailing
newline):

```
TAW-HUB-v1
{METHOD}                     upper-case verb, or the literal RESPONSE
{PATH}                       /wp-json/taw-hub/v1/…  (reconstructed from the matched route)
{TIMESTAMP}                  unix seconds
{NONCE}
{lowercase hex sha256(body)}
```

Headers: `X-Taw-Hub-{Algo, Key-Id, Timestamp, Nonce, Signature}`. Signature = base64 of the
raw bytes (Ed25519 64, HMAC 32). Verification order (mandatory):

1. parse headers → `malformed_signature_headers`
2. `|now − timestamp| ≤ 60s` → `timestamp_out_of_window`
3. resolve key by `(algo, keyId)` → `unknown_key_id`
4. crypto verify → `invalid_signature`
5. consume nonce (**last**, TTL 150s) → `replayed_nonce`

Rejections: `401 {"error":"unauthorized","reason":"<code>"}`.

**Responses are signed too** — `{METHOD}` is the literal `RESPONSE`, `{PATH}` is the
request's signed path, signed with this site's key. The Hub's `HttpCompanionClient`
hard-fails a missing/bad response signature.

The signed `{PATH}` is reconstructed as `/wp-json/` + namespace + route from the matched
REST route — **not** from `$_SERVER['REQUEST_URI']` — so it matches what the Hub signs even
for a subdirectory WordPress install. A filtered `rest_get_url_prefix()` (≠ `wp-json`) is a
known unsupported configuration; the plugin raises an admin notice.

## Development

```bash
composer install
composer run test      # PHPUnit 11 + brain/monkey — no WordPress needed
composer run phpstan   # level max, szepeviktor/phpstan-wordpress
```

`tests/Unit/Wire/` is the wire contract. `CanonicalStringTest` is pinned to ADR-0003's
worked example; `SigningVectorsTest` runs `tests/fixtures/hub-signing-vectors.json` (copied
from the Hub) through `SignatureGate` for full cross-implementation parity — re-copy that
file if the Hub ever regenerates it.

## Settled by ADR-0005

- `TAW_HUB_KEY_ID` is the **Hub's** inbound key id (defaults to `hub-local`); the site's own
  id is auto-generated `site-<random>`, overridable with `TAW_HUB_SITE_KEY_ID`.
- `HealthSnapshot::fromResponse` ignores unknown keys, so `/health` carries `site_public_key`
  + `site_key_id` for the registration handshake.
- The Hub hashes the exact received response bytes; the plugin signs precisely its
  `wp_json_encode($data)` output.
- `/assets/sync` is **not** implemented — the Hub's `SyncTawViteAssets` is deferred until
  Vite bundle-pinning is designed (a future ADR).
