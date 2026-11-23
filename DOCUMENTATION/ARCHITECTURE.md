# Architecture — v0.2.5-alpha

## Current composition

- `public/index.php`: HTML UI shell, no secrets or hosting data in page source.
- `public/assets/app.css`: CSS tokens and responsive layouts without external runtime dependencies.
- `public/assets/app.js`: frontend state; authentication, list fetching, context menu and dialogs.
- `public/api.php`: single API entry point.
- `src/Http.php`: authentication, server-session state, CSRF, dispatch, JSON and downloads.
- `src/Filesystem.php`: strict relative paths, bounded directory scans, authorized file operations.
- `src/Trash.php`: private same-device recycle-bin moves, listing, restore and source safety checks.
- `src/DeploymentMode.php`: no auto-demo; explicit localhost-only sandbox for known test credentials.
- `src/DirectoryPager.php`: tested server-side search, directory-first sort, and 25-entry response slice.

No database is used. Every request is checked against the configured environment and authenticated PHP session.

## Account boundary

The first deployment profile uses *one PHP-FPM pool per hosting account*, with `FFM_STORAGE_ROOT` pointing to files that OS user may legitimately access. The PHP interpreter must not run as root. A future standalone multi-account profile requires a distinct per-account execution boundary before accepting tenant selection. Environment variables are not a substitute for filesystem isolation.

## API (initial, subject to versioning)

GET `?action=me`, `list&path=...`, `read&path=...`, `download&path=...`, `preview&path=...`.

POST JSON: `login`, `logout`, `create`, `rename`, `chmod`, `save`, `delete`, `copy`, `move`, `zip`, `trash-restore`.

POST multipart: `upload`, file field `file`, directory field `path`, header `X-CSRF-Token`.

Response format: `{ "ok": true, "data": ... }` or `{ "ok": false, "error": "..." }`. Actual operation support is announced by `me.capabilities`. A formal OpenAPI schema and API version are pending.

## Deployment boundaries

Only `public/` may be web exposed. The storage root and application backend source must remain outside that document root. Nginx/Apache routes require PHP-FPM configuration; avoid configuring a shared PHP-FPM pool with access to multiple tenants.

## Non-goals for alpha

No privileged shell command runner; no direct root filesystem access; no FTP server; no multi-tenant hosting admin control plane; no unattended synchronization or background queue.

## v0.2 bounded filesystem operations

Directory copying is a synchronous, account-local operation. Before writing, the application traverses the source tree to check every path, reject symbolic links/special entries, and enforce hard limits (100 MiB total, 2,000 entries or configured lower threshold, 20 levels). A private randomly named staging path is created in the destination folder, populated, and renamed to the visible target. Failed copies attempt cleanup without following links. Destination name conflicts produce ` (copy)` / ` (copy 2)` names and cannot silently overwrite. These controls are best-effort with PHP pathname APIs and are not resistant to a malicious parallel process running as the same OS identity.

The SHA-256 API only accepts authorized, readable regular files of at most 100 MiB, and returns their checksum and size. It does not sign or attest to file provenance. Upload size is also bounded by a configurable per-file `FFM_UPLOAD_MAX_BYTES` (100 MiB by default), independent of the PHP runtime post/upload limits.

## Directory pagination contract — v0.2.4

`GET api.php?action=list&path=<relative>&page=<positive integer>&search=<UTF-8>&sort=<name|name-desc|date|size>&hidden=<0|1>`

All query parameters except `path` are optional. `page` defaults to 1, `search` to empty, `sort` to `name`, `hidden` to 0. `page` must be 1..1,000,000; search is bounded to 200 UTF-8 bytes. Invalid input returns HTTP 400.

Example JSON data shape:

```json
{
  "path": "public_html", "items": [], "total": 125, "page": 2,
  "pages": 5, "pageSize": 25, "hasNext": true, "scanLimit": 50000,
  "disk": {"free": null, "total": null, "isAccountQuota": false}
}
```

The entire authorized directory is scanned and sorted before returning a maximum of 25 detailed entries. The scan stops with a **413** error when more than `FFM_LIST_SCAN_LIMIT` entries exist (default 50,000; maximum 200,000). Search is case-insensitive and uses `mb_stripos` when available. All directories sort ahead of regular files and symlinks; equal sort keys use name ordering. The removed virtual demo does not participate in pagination; local test storage uses the normal scoped filesystem backend.

This avoids transferring all records into the browser but is still an O(N log N) in-memory sort, not a database-backed indexed search. It is not transactionally consistent under concurrent renames/deletes; an entry may disappear between listing and rendering. Strong cursor/snapshot semantics remain future work. `FFM_MAX_ENTRIES` continues to constrain copy/ZIP operations independently.

## Private recycle-bin contracts — v0.2.5

- `GET ?action=trash-list&page=1` returns private entries and metadata, 25 per page.
- `POST ?action=delete` with `{ "path":"..." }` moves the selected file/folder, requiring CSRF, session and a configured `FFM_TRASH_ROOT`; returns an opaque, random ID.
- `POST ?action=trash-restore` with `{ "id":"32-lowercase-hex" }` attempts to restore only to its original account path; no caller-controlled destination is accepted.
- No HTTP permanent-delete endpoint exists. `Filesystem::remove()` remains an internal legacy helper but is not routed to an API action.
- Private trash location must be outside storage root/application/webroot, owner-only, same owner and device as account storage. Renames are non-copying; cross-device moves fail.
- Restores reject missing parent directories or existing destinations. Concurrent modifications by external processes sharing the OS account remain a TOCTOU risk.
- The private storage and data model are alpha-level, without retention, quota accounting, purge, transactional journal, or cross-node coordination.
