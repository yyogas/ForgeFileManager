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

## v0.2 limits and residual risks

Copying directory trees refuses symlinks, files larger than an aggregate 100 MiB, paths deeper than 20 levels, and more than 2,000 entries (or a stricter configured listing limit). An isolated staging tree is used for best-effort cleanup after errors. These checks are not race-free against an adversary with filesystem write privileges; a future lower-level OS adapter must use descriptor-relative, no-follow APIs for a stronger guarantee.

SHA-256 checksum requests require normal authenticated GET access, regular readable files, and a 100 MiB limit. Uploads are bounded by `FFM_UPLOAD_MAX_BYTES` (default 100 MiB) and by PHP transport-level limits; a client-side check is informational only. No malware detection, storage quota enforcement, or per-IP brute-force protection is claimed. Production deployment still requires independent security assessment.

## Virtual demonstration mode (v0.2.2-alpha)

- The well-known `demo / demo123` credentials are accepted **only** for a virtual
  read-only demonstration. They never authorize any real file access.
- Demo is enabled automatically only if all three live credentials are absent.
  Partial configuration fails closed. Explicit `FFM_DEMO_MODE=0` always disables demo.
- Demo requests never create the real `Filesystem` object and cannot read,
  download, upload, write, delete, change permissions, or enumerate real
  files; server-side denials are mandatory regardless of UI buttons.
- Live configuration that uses `demo / demo123` is rejected.
- No filesystem, session, or API isolation claim substitutes for a formal
  production deployment review. Only `public/` may be exposed via HTTP.

## v0.2.4 — directory scan controls

The authenticated list API now validates page, search, sort and hidden parameters and returns a maximum of 25 file metadata entries, with full-directory counts. The complete directory scan has a separate configurable upper bound (`FFM_LIST_SCAN_LIMIT`, default 50,000, capped at 200,000) and responds HTTP 413 when exceeded. This protects against silent truncation but does not eliminate the CPU/memory cost of an O(N log N) sort for large directories. Apply reverse-proxy request throttling and per-account runtime limits before production. The listing is an offset view, not an immutable snapshot, and concurrent writes may shift pages. Private account storage must remain isolated through the OS identity.
