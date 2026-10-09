# ADR 0005 — WordPress companion plugin architecture

> Copied from `Relmaur/taw-hub` on 2026-10-08, when taw-hub was retired. The client is now
> [taw-fleet](https://github.com/Relmaur/taw-fleet) (`taw-fleet live`); the wire protocol is unchanged.
> Where this says "the Hub", read "the client holding the fleet key".

- **Status:** Accepted
- **Date:** 2026-08-31
- **Deciders:** Principal Architect (Hub) + the TAW-umbrella session (plugin author)
- **Domain(s):** cross-cutting (Hub ↔ managed sites); consumed by SiteFleet, Provisioning, Telemetry
- **Tags:** companion-plugin, wordpress, ed25519, wire-protocol, taw-hub-companion, submodule, wp-config

## Context

Every managed TAW site needs an authenticated agent the Hub can call — health,
framework sync, allow-listed `bin/taw` runs, key rotation — with no passwords,
resistant to replay, verifiable identically by a Laravel app and a WordPress
plugin (ADR-0003). `taw/core` briefly shipped an in-theme receiver (v1.20.0) with
a divergent wire format; it was reverted (v1.20.1) and `taw/core` is a theme
framework again (`docs/reference/taw-ecosystem.md` open-question #3).

## Decision

### Location & ownership

The companion is a **standalone repository, `Relmaur/taw-hub-companion`**, added
as a 4th TAW-umbrella git submodule (parallel to `taw-core` / `taw-theme` /
`taw-docs`). It is **built and maintained outside this repo** by the TAW-umbrella
session. `taw-hub` carries **no plugin code**. It ships to sites by
dropping into `wp-content/plugins/`.

This repo's obligations to the plugin, and only these:

- **ADR-0003** (canonical string, headers, verification order, reason codes) — frozen.
  Any change → new ADR + `TAW-HUB-v1` → `v2`, dual-support during migration.
- `docs/reference/wire-protocol.md` — the worked examples the plugin is pinned to.
- The `app/Domains/SiteFleet/Data/*::fromResponse()` payload shapes
  (`HealthSnapshot`, `SyncReport`, `TawCommandResult`).
- A signing **test-vector fixture** (`docs/reference/hub-signing-vectors.json`)
  the plugin uses for a cross-implementation golden test.

### Wire contract handed to the plugin

- Canonical: `TAW-HUB-v1\n{METHOD}\n{PATH}\n{TS}\n{NONCE}\n{sha256hex(body)}`, no
  trailing newline. `{METHOD}` = `RESPONSE` (literal) for the response direction.
- `{PATH}` = `'/wp-json/' + namespace + route`, **reconstructed plugin-side from
  the matched REST route**, never from `$_SERVER['REQUEST_URI']` (subdirectory
  installs; assumes the default `wp-json` REST prefix). Namespace default
  `taw-hub/v1` (`config('site_fleet.companion.rest_namespace')`).
- 5 headers `X-Taw-Hub-{Algo,Key-Id,Timestamp,Nonce,Signature}`; signature =
  base64 of raw bytes for **both** algorithms (Ed25519 64 B, HMAC 32 B).
- Verify order: parse headers → echo-check ts/nonce → drift ≤ 60 s → resolve key
  by (algo, keyId) → crypto verify → **consume nonce last** (TTL 150 s). Failure
  → `401 {"error":"unauthorized","reason":"<code>"}` with codes
  `malformed_signature_headers` / `invalid_signature` / `timestamp_out_of_window`
  / `unknown_key_id` / `replayed_nonce`.
- **Two key ids, two constants** (`TAW_HUB_*` always means "the Hub, as the plugin sees it"):
  - `TAW_HUB_KEY_ID` + `TAW_HUB_PUBLIC_KEY` — the **Hub's inbound** identity the plugin
    verifies requests against. `TAW_HUB_KEY_ID` defaults to `hub-local`
    (`config('security.hub.key_id')` on the Hub side).
  - The **site's own** key id — auto-generated `site-<random>` on activation, stored
    autoload-off, printed beside the site's public key for registration, stable for the
    plugin's life. Override: `TAW_HUB_SITE_KEY_ID`. The Hub stores it verbatim as
    `managed_sites.key_id` (`SiteRegistrationData.keyId`); the plugin stamps it on every
    signed **RESPONSE**. `/keys/rotate` rotates the keypair only — the id is unchanged;
    body `{"public_key":"<base64>"}`.
- **Registration handshake:** `GET /health` MAY carry `site_key_id` + `site_public_key`
  so an operator (or a future enrolment flow) can register the site in one call.
  `HealthSnapshot::fromResponse()` reads only the fields it knows and **ignores unknown
  keys**, so this is additive and safe.
- **Response bytes:** the Hub hashes the **exact received response body bytes**
  (`HttpCompanionClient` builds the canonical from `$response->body()`). The plugin must
  sign precisely the bytes it writes — `wp_json_encode($data)` output, with nothing
  re-encoding it afterwards.

### Config & routes

- Config from **wp-config constants** (matches `taw/core` `Cors.php`):
  `TAW_HUB_PUBLIC_KEY` (base64, the Hub's Ed25519 public key — **required**),
  `TAW_HUB_KEY_ID` (the Hub's inbound key id, default `hub-local`),
  `TAW_HUB_HMAC_SECRET` (only if the plugin ever accepts HMAC callers directly),
  `TAW_HUB_SITE_KEY_ID` (override the auto-generated site key id),
  `TAW_HUB_ALLOWED_IPS` (optional). Missing `TAW_HUB_PUBLIC_KEY` → plugin is inert:
  `501` + admin notice, no routes served.
- REST namespace `taw-hub/v1`. Routes the Hub calls today:
  `GET /health`, `POST /framework/sync` `{"dry_run":bool}`,
  `POST /taw` `{"command":string,"args":string[]}`, `POST /keys/rotate`.
  **Not** `/assets/sync` — the Hub's `SyncTawViteAssets` job is still deferred.
- The plugin keeps its **own** independent `/taw` allow-list (defence in depth);
  the Hub's is `config('provisioning.taw_command_allowlist')`.
- Test stack: **PHPUnit 11 + `brain/monkey` + `szepeviktor/phpstan-wordpress`** at
  max — consistent with `taw/core`, not Pest (Hub-only).

## Consequences

- Positive: the plugin releases and versions independently of the Hub; the Hub
  test suite stays PHP-only; one wire spec, two implementations, one golden fixture.
- Negative / trade-offs: a wire change is now a two-repo coordinated release. The
  registration flow is still manual (activate → copy key id + public key → register
  with the Hub) until an enrolment endpoint exists.
- Follow-up: `docs/reference/hub-signing-vectors.json` (this repo) + a taw-docs
  `taw-hub-companion.mdx` operator page. `/assets/sync` route + `SyncTawViteAssets`
  when Vite bundle-pinning is designed. An enrolment/handshake endpoint (Part 8+).

## Agent Notes

- Governed **in this repo**: `docs/ADR/0003`, `docs/reference/wire-protocol.md`,
  `docs/reference/hub-signing-vectors.json`, the `Data\*::fromResponse()` shapes,
  `config('site_fleet.companion.*')`. The plugin source is NOT in this repo — do
  not scaffold `companion-plugin/` here.
- Invariant: the vectors file is generated from `tests/Support/HubSigning.php` /
  `App\Domains\Security\Cryptography\SignedMessage`; regenerate it (never hand-edit)
  if the canonical changes, and that change needs a new ADR first.
- A new ADR is required to: move the plugin back in-repo, change the wire contract,
  change the key-id ownership model, or add an inbound route the Hub relies on.
