# Architecture — v0.1.0-alpha

## Current composition

- `public/index.php`: HTML UI shell, no secrets or hosting data in page source.
- `public/assets/app.css`: CSS tokens and responsive layouts without external runtime dependencies.
- `public/assets/app.js`: frontend state; authentication, list fetching, context menu and dialogs.
- `public/api.php`: single API entry point.
- `src/Http.php`: authentication, server-session state, CSRF, dispatch, JSON and downloads.
- `src/Filesystem.php`: strict relative paths, directory listing and authorized file operations.

No database is used. Every request is checked against the configured environment and authenticated PHP session.

## Account boundary

The first deployment profile uses *one PHP-FPM pool per hosting account*, with `FFM_STORAGE_ROOT` pointing to files that OS user may legitimately access. The PHP interpreter must not run as root. A future standalone multi-account profile requires a distinct per-account execution boundary before accepting tenant selection. Environment variables are not a substitute for filesystem isolation.

## API (initial, subject to versioning)

GET `?action=me`, `list&path=...`, `read&path=...`, `download&path=...`, `preview&path=...`.

POST JSON: `login`, `logout`, `create`, `rename`, `chmod`, `save`, `delete`, `copy`, `move`, `zip`.

POST multipart: `upload`, file field `file`, directory field `path`, header `X-CSRF-Token`.

Response format: `{ "ok": true, "data": ... }` or `{ "ok": false, "error": "..." }`. Actual operation support is announced by `me.capabilities`. A formal OpenAPI schema and API version are pending.

## Deployment boundaries

Only `public/` may be web exposed. The storage root and application backend source must remain outside that document root. Nginx/Apache routes require PHP-FPM configuration; avoid configuring a shared PHP-FPM pool with access to multiple tenants.

## Non-goals for alpha

No privileged shell command runner; no direct root filesystem access; no FTP server; no multi-tenant hosting admin control plane; no unattended synchronization or background queue.