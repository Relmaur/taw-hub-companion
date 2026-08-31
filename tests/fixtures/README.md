# Cross-implementation test vectors

`hub-vectors.json` (pending) comes from the Hub's `tests/Support/HubSigning.php`
(`Relmaur/taw-site-manager`). It carries known-good `(canonical, headers, signature,
public_key)` tuples plus a deliberately-tampered case, so this plugin's `SignatureGate`
can be verified against the Hub's own `SignedMessage` / `SignatureGate` without a live
round-trip.

`SignatureGateTest` will load it once it lands.
