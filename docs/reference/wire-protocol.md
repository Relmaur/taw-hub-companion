# Reference — Signed request wire protocol (worked examples)

> Copied from `Relmaur/taw-hub` on 2026-10-08, when taw-hub was retired. The client is now
> [taw-fleet](https://github.com/Relmaur/taw-fleet) (`taw-fleet live`); the wire protocol is unchanged.
> Where this says "the Hub", read "the client holding the fleet key".

Canonical spec: [`docs/ADR/0003-wire-protocol-and-signatures.md`](../ADR/0003-wire-protocol-and-signatures.md).
This page is copy-paste examples for implementers (the Part 6 WordPress plugin especially).

## The canonical string

```
TAW-HUB-v1\n
{METHOD}\n
{PATH}\n
{TIMESTAMP}\n
{NONCE}\n
{lowercase hex sha256 of the raw body}
```

Example — `POST /wp-json/taw-hub/v1/framework/sync` with body `{"dry_run":false}` at
`2026-08-31T12:00:00Z` (unix `1788134400`), nonce `9f2c…`:

```
TAW-HUB-v1
POST
/wp-json/taw-hub/v1/framework/sync
1788134400
9f2c1b7e4a6d40f0a1b2c3d4e5f60718
b0e5…（sha256 of {"dry_run":false}）
```

## Ed25519 — sign (Hub side, PHP)

```php
$canonical = "TAW-HUB-v1\n{$method}\n{$path}\n{$ts}\n{$nonce}\n".hash('sha256', $body);
$sigB64    = base64_encode(sodium_crypto_sign_detached($canonical, $secretKeyRaw)); // 64→base64
```

Headers to send:

```
X-Taw-Hub-Algo: ed25519
X-Taw-Hub-Key-Id: hub-local
X-Taw-Hub-Timestamp: 1788134400
X-Taw-Hub-Nonce: 9f2c1b7e4a6d40f0a1b2c3d4e5f60718
X-Taw-Hub-Signature: <base64, 88 chars>
```

## Ed25519 — verify (WordPress companion plugin side, PHP)

```php
// $hubPublicKeyRaw = base64_decode( TAW_HUB_PUBLIC_KEY );  // 32 bytes, from wp-config.php
$canonical = "TAW-HUB-v1\n"
    . strtoupper($_SERVER['REQUEST_METHOD']) . "\n"
    . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . "\n"
    . $headers['X-Taw-Hub-Timestamp'] . "\n"
    . $headers['X-Taw-Hub-Nonce'] . "\n"
    . hash('sha256', file_get_contents('php://input'));

if (abs(time() - (int) $headers['X-Taw-Hub-Timestamp']) > 60) {
    // 401 timestamp_out_of_window
}
$ok = sodium_crypto_sign_verify_detached(
    base64_decode($headers['X-Taw-Hub-Signature']),
    $canonical,
    $hubPublicKeyRaw,
);
// then: replay check on (key_id, nonce) via a transient, TTL 150s
```

## Responses (companion → Hub)

Same scheme, but `{METHOD}` is the literal `RESPONSE` and `{PATH}` is the request's signed path.
The site signs with its own key; the Hub verifies against `managed_sites.companion_public_key`.

```
TAW-HUB-v1
RESPONSE
/wp-json/taw-hub/v1/health
1788134461
<fresh nonce>
<sha256 of the response body>
```

## Site enrolment — `POST /api/fleet/enroll` (ADR-0011)

Direction is **site → Hub**, and the request is **not** signed — the site has no
key on file yet. Auth is a one-time, Hub-minted `enrolment_token` in the body:

```json
POST /api/fleet/enroll
{ "name": "ML Portfolio", "base_url": "http://localhost:10023",
  "site_public_key": "<base64 ed25519>", "site_key_id": "site-<hex>",
  "enrolment_token": "enrol_<48 random>" }
```

**Every** response (`201` / `409` / `401` / `422`) is signed by the **Hub** with
its own key — `{METHOD}` = `RESPONSE`, `{PATH}` = `/api/fleet/enroll`, body = the
exact JSON bytes returned. `bin/taw hub:enroll` verifies against
`TAW_HUB_PUBLIC_KEY` and may treat a signed `409 {"site_id":N}` as idempotent
success.

```
TAW-HUB-v1
RESPONSE
/api/fleet/enroll
1788134461
<fresh nonce>
<sha256 of {"site_id":12,"key_id":"site-…","hub_key_id":"hub-local","status":"pending"}>
```

Golden vector: `response_enrol_ed25519` in `hub-signing-vectors.json`.

## HMAC-SHA256 — sign / verify (n8n channel)

```php
$raw      = hash_hmac('sha256', $canonical, $sharedSecret, true); // 32 bytes
$sigB64   = base64_encode($raw);
// verify:
hash_equals(hash_hmac('sha256', $canonical, $sharedSecret, true), base64_decode($sigB64));
```

## curl (using this repo's key generator)

```bash
php artisan security:keygen                    # prints HUB_SIGNING_PUBLIC_KEY / _SECRET_KEY
```

## Cross-implementation test vectors

`docs/reference/hub-signing-vectors.json` holds deterministic golden vectors
(canonical string + base64 signature + key material + full `X-Taw-Hub-*` header
set) for GET `/health`, POST `/framework/sync`, POST `/taw`, an HMAC (n8n) case,
a `RESPONSE`-direction case, and one tampered-body case (`invalid_signature`).
The Part 6 companion plugin (`Relmaur/taw-hub-companion`, ADR-0005) verifies
against it. Regenerate with `php scripts/gen-signing-vectors.php` — never
hand-edit; `tests/Unit/Security/SigningVectorsTest.php` keeps it honest.

## Rejection reasons (HTTP 401 body `reason`)

| reason | meaning |
|--------|---------|
| `malformed_signature_headers` | a header missing / blank / bad algo / non-numeric timestamp / non-base64 signature |
| `invalid_signature` | signature doesn't verify, or headers don't echo the canonical's timestamp/nonce, or wrong signature length |
| `timestamp_out_of_window` | `|now − timestamp|` > 60 s |
| `unknown_key_id` | no key material registered for `(algo, key_id)` |
| `replayed_nonce` | this `(key_id, nonce)` was already seen within the replay TTL |
