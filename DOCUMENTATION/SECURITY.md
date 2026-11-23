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
- File operation-level durable journaling and recycle-bin retention have not been implemented.
- ZIP creation depends on optional ext-zip and has not been verified in this sandbox.
- UI modal focus trap, accessibility tests and browser E2E tests remain pending.
- Accounts able to edit site code inherently have powerful execution capability within their hosting user; hosting plans must account for this risk.

## Provenance and license

No source from the inaccessible external directory has been copied. No license from that upstream distribution is implied. Inspect upstream `LICENSE`, bundled vendors and authorship before importing or relicensing code.

## v0.2 limits and residual risks

Copying directory trees refuses symlinks, files larger than an aggregate 100 MiB, paths deeper than 20 levels, and more than 2,000 entries (or a stricter configured listing limit). An isolated staging tree is used for best-effort cleanup after errors. These checks are not race-free against an adversary with filesystem write privileges; a future lower-level OS adapter must use descriptor-relative, no-follow APIs for a stronger guarantee.

SHA-256 checksum requests require normal authenticated GET access, regular readable files, and a 100 MiB limit. Uploads are bounded by `FFM_UPLOAD_MAX_BYTES` (default 100 MiB) and by PHP transport-level limits; a client-side check is informational only. No malware detection, storage quota enforcement, or per-IP brute-force protection is claimed. Production deployment still requires independent security assessment.

## v0.2.5 — public demonstration removed

- The previous virtual read-only demo, its auto-login fallback, and its synthetic filesystem are gone.
- `demo / demo123` remains shown as **test-only information** on the login page.
- Known credentials work only after explicit `FFM_TEST_SANDBOX=1` on a local loopback connection, with development HTTP opt-in and an owner-only root wholly separate from the web and hosting filesystem.
- Existing session mode is compared to the deployment profile, invalidating sessions when the mode changes.
- Real account configuration using public known credentials is rejected, including if a matching password hash is supplied.
- `FFM_DEMO_MODE=1` is rejected; absence of real account credentials results in HTTP 503.

## v0.2.5 — private recycle bin

- Private trash path must be owner-only, same owner and filesystem, and outside the hosting, application and public web roots.
- Files and populated folders are moved using an ordinary filesystem rename, with bounded recursion and refusal of symlinks; no directory tree is immediately destroyed by the public API.
- Private manifest uses random 128-bit IDs, bounded JSON and strict IDs; restoration validates the original relative account path and never deliberately overwrites an existing path.
- If private trash is not configured, delete requests fail closed. There is no API purge action, no expiration, and no quota accounting yet.
- This is **not** a reliable backup, recovery journal, or a race-free security boundary against independent processes under the same Unix identity. Offsite backups and security review remain necessary.

## v0.2.4 — directory scan controls

The authenticated list API now validates page, search, sort and hidden parameters and returns a maximum of 25 file metadata entries, with full-directory counts. The complete directory scan has a separate configurable upper bound (`FFM_LIST_SCAN_LIMIT`, default 50,000, capped at 200,000) and responds HTTP 413 when exceeded. This protects against silent truncation but does not eliminate the CPU/memory cost of an O(N log N) sort for large directories. Apply reverse-proxy request throttling and per-account runtime limits before production. The listing is an offset view, not an immutable snapshot, and concurrent writes may shift pages. Private account storage must remain isolated through the OS identity.

## v0.2.6 — ZIP extraction and batch transfer threat model

Only ZIP (`ext-zip`) is accepted, with explicit max archive bytes, max expanded bytes, 2,000 entries, 20 levels, 250:1 expansion threshold, regular-files-and-directories-only policy, CRC verification and private-stage cleanup. The service extracts to a new folder rather than merging into a live website. This is **not a virus scanner or a secure sandbox**: once the customer publishes PHP scripts under their website, the hosting runtime's permissions and webserver controls remain the actual execution boundary. Archive contents are not trusted, and PHP filesystem path checks remain vulnerable to hostile same-UID races; production deployment requires independent security review. Bulk copy/move reports partial success; there are no bulk destructive operations or silently executed root commands.


## v0.2.7 — account boundary hardening

- The **OS worker UID is the authorization boundary**: one dedicated account user
  and PHP-FPM pool per tenant; configuration rejects a live root worker, cross-owner
  storage root, system-wide root paths and storage-root symlinks. The optional
  `FFM_EXPECTED_UID` further binds deployments to one numeric UID.
- All authenticated sessions are invalidated if account identity, canonical root,
  credential hash, execution UID or sandbox/live mode changes.
- Symlinks and hard-linked regular files are refused. A hardlink's other path may
  be outside the allowed root, which PHP cannot reliably determine.
- The editor uses an advisory file lock, optimistic SHA-256 conflict check,
  private umask for temporary files, inode/content rechecks before publication,
  and permission preservation; no claim of TOCTOU-proof filesystem operations
  against hostile same-UID writers is made.
- CHMOD accepts only ordinary file/directory targets, verifies inode and applied
  mode after syscall, blocks account-root changes and world-writable permissions
  by default. Dates are read from `lstat` and reported as UTC/epoch timestamps.
- ZIP construction checks source trees, uses private staging with cleanup, and
  must not overwrite a target. Extraction restores validated timestamps in a
  separate new directory with restrictive 0600 files and 0700 folders.
- Filesystem account roots that contain publicly served files must deny `.ffm-*`
  staging paths at the web server. Code and secrets must remain outside webroots.

**Residual risk:** PHP standard filesystem functions cannot provide atomic,
rename-no-replace operations and secure dirfd-based traversal on their own.
A dedicated per-tenant OS identity and host security review are required.

- In 0.2.7, downloads, editor reads, previews and checksums use a verified regular-file descriptor and check device/inode against the path before reading; malicious same-UID filesystem races remain a residual risk.
