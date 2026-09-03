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

| Method | Route | Body / query | Returns |
|---|---|---|---|
| `GET` | `/health` | — | `{ ok, php_version, wp_version, taw_core_version, companion_version, site_public_key, site_key_id, exec_available }` |
| `GET` | `/inventory` | — | `{ schema_version, generated_at, wp_version, wp_locale, wp_multisite, php_version, plugins:[…], mu_plugins:[…], dropins:[…], themes:[…] }` — a security-focused SBOM |
| `GET` | `/inventory/checksums` | `?slug&type` | `{ schema_version, generated_at, mode, components:[…] }` — per-component SHA-256 file manifest |
| `GET` | `/vulnerabilities` | — | `{ scanner, count, findings:[…] }` — the site scanner's findings, normalized |
| `GET` | `/logs` | `?limit&level&code&since` | `{ count, entries: [...] }` — the structured log `taw/core` writes |
| `POST` | `/framework/sync` | `{ "dry_run": bool }` | the `php bin/taw sync --json` report verbatim |
| `POST` | `/taw` | `{ "command": string, "args": string[] }` | `{ exit_code, stdout, stderr }` — allow-listed commands only |
| `POST` | `/keys/rotate` | — | `{ "public_key": "<base64>" }` — new keypair, same key id |

`/inventory` is a read-only, subprocess-free software bill of materials — every plugin,
must-use plugin, drop-in and theme with the metadata the Hub needs to correlate the fleet
against vulnerability feeds (WPScan / Patchstack), spot abandoned components and flag pending
updates. Schema coordinated with the Hub's `SiteFleet\Data\InventorySnapshot` (taw-hub
ADR-0013); `schema_version` (currently `1`) lets the Hub branch on companion evolution.

Per-plugin fields: `slug` (folder-derived — unreliable for non-.org plugins), `file` (WP's
canonical key, the Hub's dedup key), `name`, `version`, `active`, `network_active`,
`auto_update`, `author`, `plugin_uri`, `update_uri` (raw header), `requires_wp`,
`requires_php`, `tested_up_to` (parsed from `readme.txt`), `update_version` (pending version
or `null`), `update_source` (`wordpress_org` \| `external` \| `disabled` \| `unknown` — i.e.
*who, if anyone, is watching this plugin for updates*; `unknown` is the abandoned-plugin
smoking gun) and `main_file_mtime` (ISO-8601; *not* an install date — redeploys and
migrations reset it, so the Hub should drive "age" off its own first-seen timestamp).

Themes carry the same signals where they apply: `slug`, `name`, `version`, `active`,
`parent_active`, `template`, `author`, `requires_wp`, `requires_php`, `auto_update`,
`update_version`, `update_source`. `mu_plugins` carry `file`, `name`, `version`, `author`,
`main_file_mtime`. `dropins` is the bare list of present drop-in filenames
(`object-cache.php`, `db.php`, `sunrise.php`, …) — an unexpected drop-in is a classic
persistence trick.

Works on every host; like `/health` and `/logs` it never spawns a subprocess. The Hub's
snapshot type ignores unknown keys (the ADR-0005 `HealthSnapshot` precedent), so fields can
be added without a lock-step Hub release.

`/inventory/checksums` is a per-component SHA-256 **file manifest** — ground truth for the
Hub to (a) detect file-integrity drift (a webshell dropped into a plugin folder), (b) diff
one version of a component against the next on update (the "quiet backdoor on update"
signal), and (c) dedupe fleet-wide analysis by `(slug, version, tree_hash)`. The companion
only produces the manifest; all comparison is the Hub's. Two modes:

- **summary** (no `slug`) — `tree_hash` + `file_count` per active component; no `files` map.
  A cheap fleet-wide "did anything change" poll.
- **detail** (`?slug=<slug>`) — adds the full `files` map (`relpath` → `sha256`) for the
  matched component(s). `?type=plugin|mu_plugin|theme` narrows the set.

Only executable / script types are hashed (`.php`, `.js`, `.sh`, … and extension-less
files); images, fonts, CSS and language files, and the `node_modules` / `.git` trees, are
skipped. Symlinks are not followed. A component past ~6000 hashed files is reported
`truncated: true`; a plugin whose directory is gone is `missing: true`. Reads the
filesystem; never spawns a subprocess.

`/vulnerabilities` reports the security findings the site's **own scanner** has already
computed — the companion does no vulnerability matching itself. A per-scanner read adapter
(`src/Security/`) reads the scanner's stored results and normalizes them; `ScannerRegistry`
picks the first installed scanner (fleet standard first, fallback after). Read-only,
DB-read-only, behind the same signature guard.

- **Wordfence** (`WordfenceScanner`) — reads the `wfIssues` table: `wfPluginVulnerable`,
  `wfPluginAbandoned`, `wfPluginRemoved`, and core/plugin/theme update rows that carry a
  `vulnerable` flag. Verified against Wordfence 8.2.x.
- **Defender Pro** — adapter pending (needs a real Pro install to read); slots in ahead of
  Wordfence in `ScannerRegistry::default()`.

Envelope: `scanner` is `null` when no supported scanner is installed, or
`{ name, version, last_scan_at }` — `last_scan_at: null` meaning installed but never scanned.
Each finding: `scanner`, `component_type` (`plugin|theme|core|unknown`), `slug`,
`component_file`, `installed_version`, `severity` (`critical|high|medium|low|unknown`),
`cvss_score`, `cvss_vector`, `kind` (`vulnerability|abandoned|removed|outdated`), `title`,
`link`, `detected_at`, `scanner_ref`. Filter `taw_hub_companion_security_scanners` to add or
reorder adapters.

`/logs` serves the JSON-Lines file `taw/core`'s `TAW\Core\Log\Logger` writes to
`wp-content/taw-logs/` — read-only, so the Hub can report on a site without SSH. `limit`
caps at 500 (default 100); `level` ∈ `debug|info|notice|warning|error|critical`; `code` is a
prefix match; `since` is an ISO-8601 timestamp. Empty `entries` if `taw/core` is old or has
never logged. The reader (`src/Logs/LogReader.php`) is a small standalone reimplementation,
not a `taw/core` dependency — same decoupling rationale as `TawRunner`.

`/taw` allow-list (filter `taw_hub_companion_taw_allowlist` to change):
`sync inspect seo:extract seo:inject icons:sync export:static`. **Not** `fields:set`, **not**
the raw `wp` passthrough. `/framework/sync` drives the *existing* `bin/taw sync` — it does
not reinvent the per-site sync logic.

**`proc_open`-disabled hosts** (most managed WordPress hosting — WPMU DEV, WP Engine, Kinsta,
…): `/framework/sync` and `/taw` return `503 {"error":"exec_unavailable"}` — they shell out
to `bin/taw` and can't run. `/health` (which reports `"exec_available": false`) and `/logs`
work everywhere; they never spawn a subprocess. Run framework sync via the theme's own
`framework-sync.yml` GitHub Action on those hosts.

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
