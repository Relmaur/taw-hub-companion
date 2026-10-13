<?php

declare(strict_types=1);

/**
 * Adds the publish routes' signing vectors (ADR-0006) to
 * tests/fixtures/hub-signing-vectors.json, signed with the fixture's own test
 * keys; every vector already there stays byte for byte (they came from the
 * archived taw-hub's generator). Run it again after changing the list below,
 * then copy the file to docs/reference/ and to taw-fleet's
 * internal/companion/testdata/ (each repo checks they're identical).
 *
 *   php scripts/gen-signing-vectors.php
 *
 * A binary body is carried as `body_b64` (JSON can't hold arbitrary bytes);
 * `body` is then "".
 */

$file = __DIR__ . '/../tests/fixtures/hub-signing-vectors.json';
$fixture = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

$hub = $fixture['keys']['hub_ed25519'];
$secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair((string) hex2bin($hub['seed_hex'])));

$binary = '';
for ($i = 0; $i < 256; $i++) {
    $binary .= chr($i);
}

$new = [
    ['post_publish_plan_ed25519', 'POST', '/wp-json/taw-hub/v1/content/publish/plan', 1788134460, 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        '{"taw_changeset":{"schema":"1.8","base_fingerprint":"' . str_repeat('ab', 32) . '"},"operations":[{"op":"update","target":{"kind":"post","type":"page","slug":"about"},"post":{"title":"About us"},"base":{"title":"About"}}]}'],
    ['put_publish_media_ed25519', 'PUT', '/wp-json/taw-hub/v1/content/publish/0123456789abcdef/media', 1788134470, 'b2c3d4e5f60718293a4b5c6d7e8f90a1', $binary],
    ['post_publish_apply_ed25519', 'POST', '/wp-json/taw-hub/v1/content/publish/0123456789abcdef/apply', 1788134480, 'c3d4e5f60718293a4b5c6d7e8f90a1b2',
        '{"token":"' . str_repeat('5f', 16) . '","approved":[{"key":"post:page:about","hash":"' . str_repeat('cd', 32) . '"}],"keep":{"post:page:about":["fields.subtitle"]},"take":{}}'],
];

$names = array_column($new, 0);
$vectors = array_values(array_filter($fixture['vectors'], static fn (array $v): bool => !in_array($v['name'], $names, true)));
foreach ($new as [$name, $method, $path, $ts, $nonce, $body]) {
    $hash = hash('sha256', $body);
    $canonical = implode("\n", ['TAW-HUB-v1', $method, $path, (string) $ts, $nonce, $hash]);
    $signature = base64_encode(sodium_crypto_sign_detached($canonical, $secret));
    $isText = mb_check_encoding($body, 'UTF-8') && !str_contains($body, "\0");
    $vector = ['name' => $name, 'algo' => 'ed25519', 'key_id' => $hub['key_id'], 'method' => $method, 'path' => $path,
        'timestamp' => $ts, 'nonce' => $nonce, 'body' => $isText ? $body : ''];
    if (!$isText) {
        $vector['body_b64'] = base64_encode($body);
    }
    $vector += ['body_sha256' => $hash, 'canonical_string' => $canonical, 'key_b64' => $hub['public_key_b64'], 'signature_b64' => $signature,
        'headers' => ['X-Taw-Hub-Algo' => 'ed25519', 'X-Taw-Hub-Key-Id' => $hub['key_id'], 'X-Taw-Hub-Timestamp' => (string) $ts,
            'X-Taw-Hub-Nonce' => $nonce, 'X-Taw-Hub-Signature' => $signature],
        'expect' => 'valid'];
    $vectors[] = $vector;
}
$fixture['vectors'] = $vectors;
$fixture['_meta']['body_b64'] = 'a binary body (the publish file upload) as base64; body is then ""';

file_put_contents($file, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo 'vectors: ', count($vectors), "\n";
