# Forge File Manager

**A modern, account-scoped PHP file manager for web hosting environments.**

> **Status:** `v0.2.7-alpha` — an original, testable **UI + secure-core foundation**. Not production-ready. Not yet a replacement for cPanel File Manager. This repository has **not** imported the external `filemanager-master` distribution because `https://download.wcp360.com/?dir=filemanager-master` could not be retrieved during this development pass. An audited upstream import/migration remains pending; no upstream source code or provenance is falsely claimed.

Forge File Manager is designed to provide a clean, responsive file-management experience for hosting users while enforcing access through a configured hosting-account filesystem root. The current implementation uses native PHP 8.2+, framework-free JavaScript and CSS with no runtime CDN dependency.

## UI preview: product structure

```text
┌────────────────────────────────────────────────────────────────────────────┐
│ Forge File Manager                  [Language] [Settings] [USER ▾]        │
├────────────────────────────────────────────────────────────────────────────┤
│          [+ Folder] [+ File] [Upload] [Copy] [Move] [CHMOD] [Trash] [Refresh]     │
├────────────────────────────────────────────────────────────────────────────┤
│ home > public_html                                       [List] [Grid]  │
│ [Search within this folder...]  [Hidden files]  [Sort]                   │
│ □ Name              Type        Size      Modified       CHMOD  Actions   │
│ □ assets            Directory   —         ...            755     ⋯       │
│ □ index.php         PHP         ...       ...            644     ⋯       │
│                          ⇵ vertically scrollable file area              │
│ Items 1–25 of 61                            [‹] [1] [2] [3] [›]          │
├────────────────────────────────────────────────────────────────────────────┤
│ /public_html   61 items       Server disk usage: Unavailable  ● Online    │
│                                Server volume, not account quota          │
└────────────────────────────────────────────────────────────────────────────┘
```

The username in the top-right header is returned by the authenticated server session, **not** a hard-coded demo string. The top bar contains working account, settings, notifications and logout menus. The second header prioritizes core file operations.

## Implemented features in this alpha

| Area | Actual implementation |
| --- | --- |
| Authentication | Password-hash login and scoped sessions for live accounts; **no automatic demo**; optional explicitly isolated localhost test login `demo / demo123` |
| Security | CSRF on mutations, scoped storage root, path-segment and symlink rejection, HTTPS required except explicit development setting |
| Navigation | Full-width directory browser with breadcrumbs (no sidebar), list/grid, current-folder search, hidden-file toggle, sorting |
| Header | Dynamic account username, profile dropdown, preferences, notifications |
| Action toolbar | Create file/folder, multi-file upload, single-file download, copy/move up to 25 selected items, rename, CHMOD, ZIP creation and extraction if extension available, recycle bin (with private storage), restore, refresh |
| Context menu | Right-click actions for file, folder and empty background; per-row action button for touch devices |
| CHMOD | Interactive owner/group/other matrix, live octal and symbolic display, explicit server-side policy |
| Editor | UTF-8 text editor with CTRL/CMD+S and optimistic SHA-256 conflict checks, 1 MB default limit |
| Transfers | Authenticated file upload with configurable maximum (100 MiB default), attachment download; no overwrites |
| ZIP | Archive a file or directory; bounded, isolated extraction to a new folder (`ext-zip` required), CRC check, path/symlink/zip-bomb guards |
| Multi-selection | Batch copy or move up to 25 selected files/folders per request, per-item success/failure (not atomic) |
| Folder copy | Bounded recursive copies (max 100 MiB / 2,000 items / 20 levels), no symlinks, temporary private staging and best-effort rollback |
| File integrity | On-demand SHA-256 checksum in the Properties dialog for readable regular files up to 100 MiB |
| Context menu accessibility | Arrow Up / Down, Home, End navigation and menuitem semantics (browser acceptance still required) |
| Interface | Flat responsive design, centered sub-header, **server-paginated** 25-item list/grid, numbered pages and a jump-to-page control, vertical scroll, dark/light themes, English/French UI |
| Test credentials | `demo / demo123` are displayed as an example but are **not accepted on real hosting accounts**. For working local testing, enable `FFM_TEST_SANDBOX=1` with a private owner-only test directory. |
| Recycle bin | Explicitly configured `FFM_TRASH_ROOT` outside web/account roots; same-device atomic moves; restore files and non-empty directories without deliberate overwrite; HTTP delete blocked if not configured |
| Testing | PHP filesystem tests and authenticated HTTP smoke tests |

**Server pagination:** `GET /api.php?action=list&path=...&page=...&search=...&sort=...&hidden=...` sorts and filters the **whole directory on the server**, returns only **25 items per page**, and includes `total`, `pages`, `page`, `pageSize` and `hasNext`. Selection applies only to the current page; search and sort reset the page to 1. The jump-to-page control appears when there are more than 7 pages. The default maximum scan is **50,000 entries** (`FFM_LIST_SCAN_LIMIT`), configurable up to 200,000. Exceeding it returns HTTP 413 instead of a misleading truncated result. This is a bounded in-memory, offset-based directory scan (not an index or consistent snapshot); future cursor/indexed pagination is planned for extremely large or concurrently modified folders.

**Not implemented yet:** permanent delete, retention/purge automation, file versioning, ACL management, advanced syntax highlighting, multi-tenant SSO, quota enforcement, background transfers, resumable chunks, external storage, search indexing, localization of remaining server errors, developer terminal, and plugin SDK. Buttons for unavailable operations are disabled or absent. The UI shows server disk usage **as server volume usage, not as an account quota**.

## Requirements

- PHP **8.2+** (test target: 8.2, 8.3, 8.4).
- Linux hosting environment; standard readable/writable directory controlled by the account.
- PHP extensions: `fileinfo` for previews; `zip` required for ZIP creation and extraction (capabilities disabled if missing).
- HTTPS with a web server configured so the **only web document root is `public/`**.
- Dedicated **non-root** hosting account PHP-FPM worker or equivalent verified OS isolation.

No database, Node build system or Composer installation is required for the first alpha.

## Test login: credentials shown, virtual demonstration disabled

The previous **automatic virtual demo has been removed**. The login screen
still displays these known, **test-only** credentials:

- Username: `demo`
- Password: `demo123`

On any publicly reachable server, these credentials **must not work** for
actual hosting files. When `FFM_TEST_SANDBOX` is unset, the server requires
`FFM_USERNAME`, `FFM_PASSWORD_HASH`, and `FFM_STORAGE_ROOT` and fails closed
(HTTP 503) if configuration is absent. The live configuration also rejects
`demo / demo123` even if its password hash is supplied.

To try real file creation and recovery **only on a local loopback test server**:

```bash
mkdir -p /tmp/ffm-private-test /tmp/ffm-private-trash
chmod 700 /tmp/ffm-private-test /tmp/ffm-private-trash
# Set FFM_TEST_SANDBOX=1, FFM_ALLOW_HTTP_DEV=1, and the test paths.
export FFM_TEST_SANDBOX=1
export FFM_ALLOW_HTTP_DEV=1
export FFM_TEST_STORAGE_ROOT=/tmp/ffm-private-test
export FFM_TEST_TRASH_ROOT=/tmp/ffm-private-trash
php -S 127.0.0.1:8080 -t public public/router.php
```

Only local connections without forwarded IP headers are accepted. The test
root must exist, be private (mode 0700), be separate from the application and
web directories, and not be mixed with live hosting credentials or storage.
**Do not put this test profile behind a public reverse proxy.**

`FFM_DEMO_MODE=1` is no longer supported. It fails closed instead of
silently enabling a shared public account.

## Private recycle bin and restoration

The recycle bin is an **optional, explicitly configured** recoverability
feature. In an isolated PHP-FPM pool per hosting account, create a private
directory outside both the hosting files root and the web/application root,
on the **same filesystem** as `FFM_STORAGE_ROOT`:

```bash
# Example paths only; replace USER with the real hosting user and verify
# your mount layout before deploying.
install -d -m 700 -o USER -g USER /srv/forge-private/USER-trash
# Supply this setting securely to that account's dedicated PHP-FPM pool:
# FFM_TRASH_ROOT=/srv/forge-private/USER-trash
```

Deleting through the web API now **moves** a file or non-empty directory into
private storage; the toolbar's **Recycle bin** opens entries and allows
restoring their original names and paths. Restores reject existing
 destinations and missing parent folders; directories containing symlinks are
refused. The private store is not a backup, and concurrent local filesystem
changes remain a race. An independent backup policy is still essential.

When no valid `FFM_TRASH_ROOT` is set, the delete action is **disabled** and
the API returns an explicit 503; it does **not** fall back to permanent deletion.
Permanent purge, expiry, audit journal, quotas, and retention policies have
**not** been implemented. Restored items remove their private metadata entry.

## Local development

```bash
# From the repository root
mkdir -p /tmp/forge-file-manager-demo
php bin/hash-password.php

# Export the generated password hash via your preferred secret manager.
export FFM_USERNAME='hosting_user'
export FFM_PASSWORD_HASH='<generated password_hash string>'
export FFM_STORAGE_ROOT='/tmp/forge-file-manager-demo'
export FFM_ALLOW_HTTP_DEV=1    # LOCAL DEVELOPMENT ONLY
# If your *disposable testing container* runs as root, also set FFM_ALLOW_ROOT_DEV=1.
# Never set this on a real hosting deployment.

php -S 127.0.0.1:8080 -t public public/router.php
# Then open http://127.0.0.1:8080
```

**Production:** configure `FFM_*` in a secure PHP-FPM pool or deployment secret store, enable TLS, remove `FFM_ALLOW_HTTP_DEV`, and run as the actual hosting account, never root. **Do not publish `src/`, `.env` or account files under the document root.** The first alpha supports one authorized account per process/pool; a reseller or shared superuser access model has not been implemented.

### Generate a real account password (SSH/CLI only)

`bin/hash-password.php` **does not work in a browser by design**. It is a CLI-only helper and must remain outside the HTTP document root.

```bash
cd /path/to/ForgeFileManager
php bin/hash-password.php
```

Configure the resulting hash securely through a dedicated PHP-FPM pool / secret manager.
Never publish a script that generates or changes real hosting credentials from an unauthenticated URL.

### Configuration

| Environment variable | Required | Meaning |
| --- | --- | --- |
| `FFM_USERNAME` | Live mode | Exact login username returned in the header |
| `FFM_PASSWORD_HASH` | Live mode | PHP `password_hash()` value; no default password |
| `FFM_STORAGE_ROOT` | Live mode | Existing, account-owned filesystem root, never `/` |
| `FFM_EDITOR_MAX_BYTES` | No | Max editor file size, default `1048576` bytes |
| `FFM_MAX_ENTRIES` | No | Limit used for bounded file operations (copy/ZIP); default `2000` |
| `FFM_LIST_SCAN_LIMIT` | No | Maximum scanned directory entries before HTTP 413 (default `50000`, maximum `200000`) |
| `FFM_UPLOAD_MAX_BYTES` | No | Max upload file size, default `104857600` bytes |
| `FFM_ALLOW_WORLD_WRITABLE` | No | `0` by default; blocks modes granting write to others |
| `FFM_ALLOW_HTTP_DEV` | No | Must be `0`/unset in production; only for local development |
| `FFM_DEMO_MODE` | Deprecated | `1` is rejected; virtual demo removed |
| `FFM_TEST_SANDBOX` | No | `1` enables a local-only test identity, never public production |
| `FFM_TEST_STORAGE_ROOT` | Test only | Private owner-only local test directory outside webroot |
| `FFM_TEST_TRASH_ROOT` | Test only | Optional private recycle bin for localhost tests |
| `FFM_TRASH_ROOT` | For recycling | Private same-filesystem directory owned by the account; if unset, HTTP deletion is disabled |
| `FFM_ALLOW_ROOT_DEV` | No | Must remain `0`/unset in production; root worker prohibited |

See `.env.example`. This is a **reference list**, not an automatically loaded `.env` file: configure actual process environment securely.

## Filesystem behavior and limits

- All backend paths are relative to `FFM_STORAGE_ROOT`; empty path represents the account home, **not Linux `/`**.
- A `..`, absolute path, invalid path component, or symlink traversal is rejected.
- Changing mode on the account root is blocked; **CHMOD applies only to the selected file/folder** (no recursive changes yet).
- Deletion via the API moves files/non-empty folders to an isolated configured private recycle bin and requires confirmation; if not configured, deletion is denied. Restore never intentionally overwrites an existing name.
- Uploads reject an existing destination rather than overwrite it. File size is bounded by `FFM_UPLOAD_MAX_BYTES` (default 100 MiB) in addition to PHP `upload_max_filesize` and `post_max_size`.
- Copying a directory performs a preflight and stages a bounded tree. Copying into the same directory produces names such as `directory (copy)`; no overwrites are allowed.
- The checksum endpoint returns a SHA-256 digest for files up to 100 MiB; it is a best-effort snapshot and is not a signed provenance proof.
- Text editing accepts UTF-8 files without NUL bytes; files larger than configured limits are rejected.
- Cross-tenant filesystem security depends on correct OS-level isolation. PHP-level path checks alone are not a secure boundary against hostile concurrent processes and symlink races.

## Keyboard and interaction conventions

| Action | Input |
| --- | --- |
| Open | Double-click or `Open` in context menu |
| Select | Row click / checkbox |
| Multi-select | Ctrl/Cmd-click or checkboxes |
| Select all visible | Ctrl/Cmd + A (while explorer focused) |
| Context menu | Right-click, `Shift + F10`, context menu key, or per-item `⋯` |
| Rename | F2 |
| Delete selected | Delete (confirmation) |
| Save text | Ctrl/Cmd + S |
| Dismiss menu/modal | Escape |

## Testing

```bash
php tests/filesystem.php
php tests/advanced-operations.php
bash tests/http-smoke.sh
bash tests/test-sandbox-i18n-smoke.sh
php tests/trash.php
php tests/deployment-mode.php
node --check public/assets/app.js
node tests/ui-structure.test.js
find src public bin tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

Optional offline Chromium tests `tests/browser-i18n.py` and `tests/browser-layout.py` verify locale switching, footer disk usage, no sidebar, a centered toolbar, server-side 25-per-page pagination, search/sort behavior and jump-to-page and local-only sandbox credentials using mocked HTTP responses. Live-site browser E2E and accessibility review are still outstanding.

## Project layout

```text
.
├── public/
│   ├── index.php             # Main UI shell and icon sprite
│   ├── api.php               # Authenticated HTTP API entry point
│   ├── router.php            # PHP dev router
│   ├── languages/             # en.json / fr.json translation catalogs
│   └── assets/
│       ├── app.css           # Flat responsive design system
│       └── app.js            # Explorer, context menus and dialogs
├── src/
│   ├── DirectoryPager.php    # Server-side 25-item filtered/sorted pagination
│   ├── Filesystem.php        # Account-scoped file operations
│   ├── Trash.php             # Private recycle bin and restoration
│   ├── DeploymentMode.php    # Local-only test sandbox detection
│   └── Http.php              # Authentication, CSRF and API routing
├── bin/
│   └── hash-password.php     # Secure credential hash generation helper
├── tests/
│   ├── filesystem.php
│   ├── http-smoke.sh
│   ├── server-pagination.php # >2,000-item listing regression
│   ├── server-pagination-http.sh # HTTP pagination and validation
│   ├── browser-layout.py  # Offline Chrome pagination/jump verification
│   ├── ui-structure.test.js  # Dependency-free CI checks
│   └── browser-smoke.py      # Optional; not passed in this environment
├── DOCUMENTATION/
│   ├── ARCHITECTURE.md
│   ├── DESIGN-SYSTEM.md
│   ├── FEATURE-MATRIX.md
│   ├── SECURITY.md
│   ├── SOURCE-IMPORT.md
│   └── ROADMAP.md
├── .github/workflows/ci.yml
├── .env.example
├── .gitignore
└── CHANGELOG.md
```

## Product direction

The longer-term goal is an extensible alternative to traditional hosting file managers with file recovery, per-account quotas, full ACLs, multi-tab code editor, resumable uploads, tenant-aware audit logging, plugin SDK, storage providers and ForgePanel SSO integration.

The current baseline is intentionally a **small, independently testable foundation**. No code from the unavailable `filemanager-master` package is represented as imported. See the documentation for accepted decisions, unresolved constraints, and future releases.

## Licensing

A final distribution license is **not yet selected**. Do not assume third-party imported code can be relicensed. Any upstream source later imported from TinyFileManager or another project must be audited for licensing and attribution before inclusion.

### Subdirectory deployment troubleshooting

When an evaluation URL uses a prefix (e.g. `https://example.com/ForgeFileManager/public/`),
asset and API URLs **must be relative to that prefix**. Version `0.2.2-alpha`
fixes absolute `/assets/` and `/api.php` references and scopes the PHP session
cookie to the application URL prefix. See [deployment and security requirements](DOCUMENTATION/DEPLOYMENT.md).

**Important:** The repository root must not be public. The recommended setup
configures the web server's document root to `ForgeFileManager/public/`.
A publicly accessible parent `/ForgeFileManager/` can expose private files
(e.g. `.env`, docs or dependencies), even when `/public/` itself appears to work.
Never leave real hosting data or live credentials on an unreviewed alpha deployment.

## v0.2.7-alpha — secure ZIP extraction and batch transfers

- ZIP files are inspected and extracted **only** into a new, collision-free directory after entry path/type/CRC/size/rate checks; no overwrites or general-purpose arbitrary archive extraction. Requires ext-zip.
- Multi-selection Copy/Move handles **1–25 items** per synchronous request and returns item-level results; partial success is explicitly reported and not automatically rolled back.
- Interactive ZIP extraction is available through the file context menu and action sub-header only when `me.capabilities.zipExtract` is true and the selected file has a `.zip` extension.
- Verified locally: existing filesystem, auth, recycle-bin, pagination and batch tests. Full ZIP tests require an ext-zip-enabled CI runner; a skip in a local environment is not proof of ZIP correctness.

See [archive and multi-selection contracts](DOCUMENTATION/ARCHIVES-AND-BULK.md). Never treat this alpha as production-ready without staging verification and an independent security review.


## v0.2.7-alpha — isolation and filesystem correctness hardening

The following behavior is **implemented and locally regression-tested**:

- **Account isolation:** session binding now includes the account, canonical storage root,
  password-hash setting, PHP worker UID and sandbox/live mode. Changing an identity,
  credential hash or storage root invalidates previous sessions. The configured root
  cannot be a symlink or a system-wide/shared directory; when POSIX APIs are present,
  the **hosting account must own its storage root** and the PHP worker must have the
  same Unix UID. An optional `FFM_EXPECTED_UID` setting locks deployment to one UID.
- **Symlinks and hardlinks:** both are refused as sources for protected file operations.
  Hardlinks to files inside the account are intentionally also refused because the other
  pathname is not reliably discoverable from PHP.
- **Text editor:** bounded UTF-8 reads and writes, revision hash and advisory lock,
  complete writes to private temporary files, metadata/contents revalidation before
  replacement, preservation of the original permission bits, and response metadata
  containing last modification time. Concurrent external same-UID writers may still
  race; run distinct hosting customers under distinct Unix identities.
- **CHMOD:** validated three-digit octal input, world-writable policy, filesystem
  owner permission enforcement, post-operation inode/mode verification. CHMOD changes
  file ctime but does not change content mtime. It refuses changing the account root.
- **Last modified:** directory listing now includes numeric Unix `modified` and
  ISO UTC `modifiedIso`; editor read/save, CHMOD and ZIP creation report modification
  timestamps. ZIP extraction restores sane file/directory timestamps if provided.
- **ZIP compression:** enforced input budget (100 MiB, 2,000 entries, 20 levels),
  no symbolic links or special files, no overwrite, private temporary ZIP staging,
  size checks and cleaned-up errors. ZIP extraction continues to enforce traversal,
  symlink/type, ratio, size, CRC and no-overwrite checks.

### Security requirements and limitations

- **Do not host multiple tenants in one Unix account or PHP-FPM pool.** The PHP path
  validator is not a substitute for operating-system isolation; file permissions,
  symlink and hardlink checks do not defeat malicious same-UID processes racing them.
- Use a dedicated non-root PHP-FPM user for every hosting account. Set
  `FFM_STORAGE_ROOT` to the account-owned directory, and preferably set
  `FFM_EXPECTED_UID` to the account UID. Only `public/` may be exposed by HTTP;
  hide/deny `.ffm-*` temporary paths in managed sites, never expose private `.env`
  settings. Temporary files are created with restrictive umask, but do not rely
  on random temporary names alone as an authorization boundary.
- Development root override is accepted only by the PHP built-in server on an
  actual loopback connection and with both `FFM_ALLOW_HTTP_DEV=1` and
  `FFM_ALLOW_ROOT_DEV=1`; **never** use these on a public server.
- Public `demo/demo123` credentials remain refused for any real hosting account;
  the explicitly configured isolated local test sandbox remains separate.
- **ZIP extraction security requires real ext-zip CI execution**, not a local
  `SKIP`. Local builds without ext-zip return HTTP 501. No claim of complete
  production security is made pending independent review, staging tests, and an
  operational filesystem isolation audit.

### Validation

Run `php tests/integrity-isolation.php` and `bash tests/integrity-http.sh`, in
addition to the existing tests. In CI, the ZIP extension is mandatory and tests
will fail rather than silently pass when it is missing.

**Verified read handles:** text editing, downloads, image previews and SHA-256 checksums now open bounded regular-file descriptors and verify inode identity against the authorized pathname before reading. This mitigates link substitution but does not make shared-UID hosting secure.
