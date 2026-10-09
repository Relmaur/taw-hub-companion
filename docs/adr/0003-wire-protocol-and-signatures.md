# ADR 0003 — Wire protocol & request signatures

> Copied from `Relmaur/taw-hub` on 2026-10-08, when taw-hub was retired. The client is now
> [taw-fleet](https://github.com/Relmaur/taw-fleet) (`taw-fleet live`); the wire protocol is unchanged.
> Where this says "the Hub", read "the client holding the fleet key".

- **Status:** Accepted
- **Date:** 2026-08-31
- **Deciders:** Principal Architect
- **Domain(s):** Security (canonical spec); consumed by SiteFleet (Part 2), AgentOps (Part 5), the companion plugin (Part 6)
- **Tags:** ed25519, libsodium, hmac, sha256, replay, nonce, timestamp-drift, middleware, wire-protocol

## Context

Every message between the Hub and a managed WordPress site, and between the Hub and n8n,
must be authenticated without passwords, resistant to replay, and verifiable identically by
a Laravel app and a WordPress plugin. The blueprint mandates Ed25519 for the companion
channel and HMAC-SHA256 (≤ 60 s drift) for webhooks.

## Decision

### Canonical string

Both sides sign this exact byte string (`\n`-joined, no trailing newline):

```
TAW-HUB-v1
{METHOD}                     # upper-case HTTP verb
{PATH}                       # leading slash, no query string, no host  (Symfony Request::getPathInfo())
{TIMESTAMP}                  # unix seconds, integer, as decimal string
{NONCE}                      # sender-generated, unique per request
{sha256(body)}               # lowercase hex of the raw body bytes ('' body → sha256 of empty string)
```

Signing a **digest of the body**, not the body, keeps the canonical bounded and avoids any
re-encoding mismatch — each side hashes the exact bytes it holds.

### Headers

| Header | Value |
|--------|-------|
| `X-Taw-Hub-Algo` | `ed25519` or `hmac-sha256` |
| `X-Taw-Hub-Key-Id` | identifies the key (enables rotation); e.g. `hub-local`, `site-42`, `n8n` |
| `X-Taw-Hub-Timestamp` | unix seconds, must equal the value in the canonical |
| `X-Taw-Hub-Nonce` | must equal the value in the canonical |
| `X-Taw-Hub-Signature` | base64 of the **raw** signature bytes (Ed25519: 64 bytes; HMAC: 32 bytes) |

### Algorithms

- **Ed25519** (`sodium_crypto_sign_detached` / `_verify_detached`). Asymmetric: the Hub holds
  its secret key and each site's public key; each site holds its own secret key and the Hub's
  public key. Used Hub ↔ companion plugin.
- **HMAC-SHA256** (`hash_hmac('sha256', …, binary: true)`, compared with `hash_equals`).
  Symmetric shared secret. Used Hub ↔ n8n.

### Verification order (MUST be this order)

1. Parse headers — any missing/blank/malformed → `malformed_signature_headers`.
2. Headers must echo the canonical's own timestamp + nonce → else `invalid_signature`.
3. `abs(now − timestamp) ≤ max_drift_seconds` (default **60**) → else `timestamp_out_of_window`.
4. Resolve key material by `(algo, keyId)` → else `unknown_key_id`.
5. Cryptographic verify against the canonical → else `invalid_signature`.
6. Consume `(keyId, nonce)` in the replay store (atomic add, TTL `replay_ttl_seconds`, default
   **150** = 2×drift + slack) → if already present, `replayed_nonce`.

Replay is checked **last** so a forged/invalid request never populates the nonce cache
(which would otherwise be a DoS: an attacker pre-seeding nonces).

### Rejection response

`401` with body `{"error":"unauthorized","reason":"<one of the reason codes above>"}`.
The reason code is stable and safe to expose; no internal detail is leaked.

### Responses (added 2026-08-31, Part 2)

A companion plugin signs its **response** the same way, with two differences:

- `{METHOD}` in the canonical is the literal string **`RESPONSE`** (domain-separates a response
  from any request, so a signed response can't be replayed as a request or vice versa).
- `{PATH}` is the **request's** signed path (e.g. `/wp-json/taw-hub/v1/health`) — the Hub knows
  which path it called, so both sides agree without the response having to echo it.

The site signs with **its own** Ed25519 key (`X-Taw-Hub-Key-Id: site-<id>`); the Hub verifies
against `managed_sites.companion_public_key` (see ADR-0002 — `ManagedSiteKeyProvider` feeds
these into the keyring). `CompanionClient` treats a missing or invalid response signature as a
hard failure (`CompanionResponseUnverified`) — a MITM could otherwise feed the Hub forged
health/sync data. Toggle: `config('site_fleet.companion.verify_responses')`, default on.

### Implementation (this repo)

`app/Domains/Security/`:
- `Cryptography/SignedMessage` — canonical string builder
- `Cryptography/SignatureHeaders` — header (de)serialisation
- `Cryptography/Ed25519Signer`, `HmacSha256Signer` — `CryptoSigner` + `CryptoVerifier`
- `Cryptography/SignatureGate` — the ordered pipeline (steps 2–6)
- `Cryptography/ReplayGuard` — cache-backed (`Cache::add`, Redis SETNX)
- `Cryptography/SignatureManager` — builds signers/verifiers from `config/security.php`
- `Cryptography/OutboundSigner` — stamps timestamp+nonce for outbound requests
- `Http/Middleware/VerifySignedRequest` — alias `hub.signed`
- `Console/GenerateKeypairCommand` — `php artisan security:keygen`

Copy-paste examples: `docs/reference/wire-protocol.md`.

## Consequences

- The WordPress companion plugin (Part 6) reimplements steps 1–6 in PHP against this exact
  spec. Any change to the canonical string or header names is a **breaking protocol change** →
  bump `TAW-HUB-v1` → `v2` and support both during migration; needs a superseding ADR.
- `getPathInfo()` semantics must match on both ends — the plugin registers routes under
  `/wp-json/taw-hub/v1/*`, so the signed path includes that prefix.
- **Subdirectory WP installs:** the Hub builds the signed path as `'/wp-json/' . namespace . route`
  (`ManagedSite::companionPath`), *without* any site subdirectory even when `base_url` has one
  (e.g. `https://x.test/blog`). The companion plugin must therefore reconstruct the canonical path
  the same way — from its matched REST route and namespace — **not** from `$_SERVER['REQUEST_URI']`,
  which would carry the `/blog` prefix and break every signature. Assumes the default `wp-json`
  REST prefix; a filtered `rest_get_url_prefix()` is unsupported.
- Clock skew between Hub and sites must stay < 60 s. Sites behind badly-synced NTP will fail;
  the `timestamp_out_of_window` reason makes that diagnosable.
- Nonce store must be shared across Hub web nodes (Redis, not per-node array) in production.

## Agent Notes

- Governed files: everything under `app/Domains/Security/`, `config/security.php`,
  `tests/**/Security/`, `tests/Support/HubSigning.php`, and the Part 6 plugin's signature guard.
- Invariant: `SignedMessage::canonical()` output is a wire contract — the
  `tests/Unit/Security/SignedMessageTest` "stable canonical" test pins it. Changing it fails
  that test on purpose.
- A new ADR is required to: add/remove a header, change the canonical layout, change the
  algorithm set, change the default drift/replay windows, or change the verification order.
