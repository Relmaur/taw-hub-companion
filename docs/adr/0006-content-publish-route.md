# ADR 0006: a signed route that publishes content, off unless the theme enables it

- **Status:** Accepted (owner, 2026-10-13: option A, "a finished product all the way": security, and a review down to each field)
- **Date:** 2026-10-13
- **Deciders:** the owner; TAW - Fleet session (author)
- **Tags:** companion-plugin, publish, content-interchange, security
- **Plan:** umbrella `docs/plans/content-publish.md`; cross-repo decision umbrella ADR-0005

## Context

taw-fleet builds a site in Local and deploys its theme, but its content (pages, fields, menus, media) reached
production by hand, often with a host migration tool that copies the whole database, Local's admin users
included. `GET /content` already gives taw-fleet production's content (`C` pulls it into Local); publishing is the
other direction. ADR-0005 requires a new ADR for any inbound route the client relies on. This one writes to
production, so it gets its own security bar.

Options considered: (A) a signed route that runs taw/core's own importer; (B) taw-fleet over SSH running
`content:import` on the server; (C) the WordPress REST API as the site's bot user. B needs SSH and wp-cli at the
host (not every host has them) and a key on the Mac; C can't carry what TAW fields hold (options pages, menus, the
front page) and would re-implement the importer. The owner chose A.

## Decision

1. **Five routes**, behind the same signature guard as every route (ADR-0003, unchanged: the body's sha256 is
   signed, the query string isn't, so every parameter is in the body):
   `POST /content/publish/plan`, `PUT /content/publish/{id}/media` (one file's raw bytes,
   `application/octet-stream`: multipart can't be signed), `POST /content/publish/{id}/apply`,
   `GET /content/publish/journals`, `POST /content/publish/undo`.
2. **Off by default.** Every publish route answers `403 publish_disabled` unless the active theme's composer.json
   says `extra.taw-companion.publish: true` (the child theme's word over the parent's; the
   `taw_hub_companion_publish_enabled` filter has the last word). It's turned on by a reviewed commit and a deploy,
   never from outside. `/health` says `publish_enabled` and `publish_available`.
3. **The companion is a thin shell.** The work is taw/core's `TAW\Core\Content\Publish` (1.98+; `501
   publish_unavailable` without it), so the CLI, the TAW Data screen and this route share one tested code path
   (the fidelity suite publishes between two real sites).
4. **Dry run, then a single-use token.** The plan writes nothing but a pending folder in `uploads/taw-private`
   (denied to the web) and answers a token whose sha256 alone is kept. Apply needs it, and spends it before
   writing; a replay, even past the non-atomic nonce store's gap, finds it spent.
5. **What was reviewed is what's written.** Apply names each approved record with the hash its changes had in the
   plan; the site plans them again and refuses the whole apply (`409 publish_moved`) if any moved. A value both
   sides changed since the base must be decided (keep this site's, or take the incoming); unresolved →
   `409 publish_conflicts_unresolved`.
6. **Files by their bytes.** A file is accepted only when its sha256 is one the plan asked for; its path comes from
   the plan, never the request. Nothing is ever downloaded from the client.
7. **Content only.** Users, comments and environment settings (permalinks, time zone…) are never published; no
   database file ever travels. Posts, terms, TAW options, menus, theme mods and media do.
8. **Bounded.** 32 MB per file, 512 MB and 500 files per publish, 48 MB per plan or apply body, a plan waits an
   hour, one apply at a time (`423 publish_busy`); `TAW_HUB_PUBLISH_MAX_FILE_MB`, `…_MAX_TOTAL_MB`, `…_MAX_FILES`
   and `…_TTL_MIN` in wp-config.php change them.
9. **Undoable and logged.** Apply writes taw/core's rollback snapshot and import journal first; undo puts back
   only values not edited since, with a dry run first. Every step is logged (`content.publish.*`), readable
   through `/logs`. Apply runs as the site's first administrator, like `content:import`.
10. **Refusals keep taw/core's codes** (`publish_invalid`, `publish_unknown`, `publish_expired`,
    `publish_token_invalid`, `publish_moved`, `publish_conflicts_unresolved`, `publish_media_missing`,
    `publish_media_unexpected`, `publish_too_large`, `publish_busy`) with their status and data; anything else is
    `500 publish_failed`, saying nothing more.

## Trade-offs

- A key that can sign can publish to a site that enabled it: the opt-in is per site and in git, and every
  publish is logged and undoable. Per-key scopes (a read-only key) would narrow it further; not needed while the
  fleet key is the only key.
- One file per request costs round trips on a site with many new images; it keeps every byte signed without a
  new protocol version.
- A same-name file with other bytes becomes a new attachment (`photo-1.jpg`) rather than replacing the file in
  place, which would break other posts using it.
- A large publish runs in one request (`set_time_limit(0)`); if a host kills it, the journal already holds what
  was done and undo reverses it. Chunked apply is a later step if hosts need it.

## Consequences

- Companion 0.5.0. Themes need `"taw/hub-companion": "^0.5"` and taw/core 1.98+ to publish.
- The signing vectors gain the three body-carrying routes (`scripts/gen-signing-vectors.php`; `body_b64` for the
  binary upload), copied to taw-fleet, which checks them too.
- taw-fleet's `p` (and `taw-fleet publish`, MCP `publish_content`) are the client.
