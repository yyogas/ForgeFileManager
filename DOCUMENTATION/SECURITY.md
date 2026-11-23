# Security Model and Current Risks

**Risk level:** alpha software; do not expose as a production hosting file manager without dedicated review.

## Implemented barriers

- Mandatory authentication; no default username or password.
- HTTP API refuses execution as OS root unless an explicit development-only override is set.
- PHP password hashing and session regeneration on login.
- HTTP 401 for unauthenticated API; CSRF token for every state-changing operation.
- HTTPS required by the API unless `FFM_ALLOW_HTTP_DEV=1` is explicitly configured.
- Account filesystem root must exist and must not be `/`.
- Relative paths are validated segment by segment; `..`, absolute paths and symlinks are rejected.
- Filesystem download uses attachment disposition, not HTML inline execution.
- Image preview supports only limited validated raster MIME types.
- Extracting arbitrary archives and recursive delete are absent.
- CHMOD world-writable policy is off by default; account root CHMOD blocked.
- No generic system shell execution from an HTTP endpoint.
- Strict HTTP headers: CSP for UI, X-Frame-Options, nosniff, noindex.

## Critical deployment conditions

1. Execute PHP under a **dedicated, non-root OS account** with only its own files accessible.
2. Set document root **only to `public/`**, never the project root or storage directory.
3. Pass credential hash and account root through trusted runtime configuration; don't commit secrets.
4. Enable HTTPS; never enable the development HTTP bypass in public deployments.
5. Restrict upload sizes and script execution policy at the hosting/web server layer.
6. Audit the user-permission and ownership mapping before adding reseller features.

## Known gaps requiring remediation

- PHP filesystem traversal checks are **not race-free** against hostile concurrent local actors; OS ownership boundaries remain mandatory. For stronger guarantees, evaluate a safe syscall provider using `openat2` or equivalent audited primitives.
- Login attempt throttling is session-local only; configure edge/reverse-proxy rate limiting before production.
- No distributed revocation / MFA / external SSO or central audit log.
- Atomic text save is best-effort and can still race an external writer between checks.
- Uploads do not yet have antivirus scans, content-type policy, quarantine, quota enforcement, or chunked recovery.
- File operation-level journaling and trash have not been implemented.
- ZIP creation depends on optional ext-zip and has not been verified in this sandbox.
- UI modal focus trap, accessibility tests and browser E2E tests remain pending.
- Accounts able to edit site code inherently have powerful execution capability within their hosting user; hosting plans must account for this risk.

## Provenance and license

No source from the inaccessible external directory has been copied. No license from that upstream distribution is implied. Inspect upstream `LICENSE`, bundled vendors and authorship before importing or relicensing code.