# Forge File Manager

**A modern, account-scoped PHP file manager for web hosting environments.**

> **Status:** `v0.2.3-alpha` — an original, testable **UI + secure-core foundation**. Not production-ready. Not yet a replacement for cPanel File Manager. This repository has **not** imported the external `filemanager-master` distribution because `https://download.wcp360.com/?dir=filemanager-master` could not be retrieved during this development pass. An audited upstream import/migration remains pending; no upstream source code or provenance is falsely claimed.

Forge File Manager is designed to provide a clean, responsive file-management experience for hosting users while enforcing access through a configured hosting-account filesystem root. The current implementation uses native PHP 8.2+, framework-free JavaScript and CSS with no runtime CDN dependency.

## UI preview: product structure

```text
┌────────────────────────────────────────────────────────────────────────────┐
│ Forge File Manager                  [Language] [Settings] [USER ▾]        │
├────────────────────────────────────────────────────────────────────────────┤
│          [+ Folder] [+ File] [Upload] [Copy] [Move] [CHMOD] [Refresh]     │
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
| Authentication | Password-hash verification and session for live accounts; virtual read-only demo uses `demo / demo123` when no live account is configured |
| Security | CSRF on mutations, scoped storage root, path-segment and symlink rejection, HTTPS required except explicit development setting |
| Navigation | Full-width directory browser with breadcrumbs (no sidebar), list/grid, current-folder search, hidden-file toggle, sorting |
| Header | Dynamic account username, profile dropdown, preferences, notifications |
| Action toolbar | Create file/folder, multi-file upload, single-file download, copy single file, move, rename, CHMOD, ZIP if extension available, refresh, delete |
| Context menu | Right-click actions for file, folder and empty background; per-row action button for touch devices |
| CHMOD | Interactive owner/group/other matrix, live octal and symbolic display, explicit server-side policy |
| Editor | UTF-8 text editor with CTRL/CMD+S and optimistic SHA-256 conflict checks, 1 MB default limit |
| Transfers | Authenticated file upload with configurable maximum (100 MiB default), attachment download; no overwrites |
| ZIP | Archive a file or directory if `ext-zip` is present; 100 MB uncompressed directory limit, depth and item-count limits |
| Folder copy | Bounded recursive copies (max 100 MiB / 2,000 items / 20 levels), no symlinks, temporary private staging and best-effort rollback |
| File integrity | On-demand SHA-256 checksum in the Properties dialog for readable regular files up to 100 MiB |
| Context menu accessibility | Arrow Up / Down, Home, End navigation and menuitem semantics (browser acceptance still required) |
| Interface | Flat responsive design, centered action sub-header, up to 25 entries per page, numbered pagination and vertically scrollable file area; dark/light mode, density options, bilingual English/French UI |
| Sample login | `demo / demo123` remains displayed only on the unconfigured sign-in page; there is no banner in the file manager. The backend still isolates this public access to read-only synthetic files. |
| Testing | PHP filesystem tests and authenticated HTTP smoke tests |

**Pagination note:** The UI displays at most **25 files/folders** per page, in both list and grid modes. Pagination follows current-folder filtering and sorting, and page changes clear the selection. The existing PHP listing API still caps a directory response at `FFM_MAX_ENTRIES` (default 2,000): full server-side pagination for extremely large directories remains future work.

**Not implemented yet:** recursive delete, safe extraction, recycle bin, file versioning, ACL management, advanced syntax highlighting, multi-tenant SSO, quota enforcement, background transfers, resumable chunks, external storage, search indexing, localization of remaining server errors, developer terminal, and plugin SDK. Buttons for unavailable operations are disabled or absent. The UI shows server disk usage **as server volume usage, not as an account quota**.

## Requirements

- PHP **8.2+** (test target: 8.2, 8.3, 8.4).
- Linux hosting environment; standard readable/writable directory controlled by the account.
- PHP extensions: `fileinfo` for previews; `zip` optional for ZIP creation.
- HTTPS with a web server configured so the **only web document root is `public/`**.
- Dedicated **non-root** hosting account PHP-FPM worker or equivalent verified OS isolation.

No database, Node build system or Composer installation is required for the first alpha.

## Sample login for an unconfigured test installation

If **none** of `FFM_USERNAME`, `FFM_PASSWORD_HASH` or `FFM_STORAGE_ROOT` is set,
Forge File Manager permits a restricted sample login backed by a **virtual, read-only filesystem** (no live hosting access):

- **Username:** `demo`
- **Password:** `demo123`
- UI language: **English** by default; switch to **Français** on the login screen or in the header/settings.
- Demonstration files live in `src/DemoFilesystem.php` as immutable sample data.
- **No real server files are exposed**; uploads, CHMOD, editing, deletion and downloads are forbidden in this demo.
- Forcing the demo with `FFM_DEMO_MODE=1` ignores real hosting credentials. Set `FFM_DEMO_MODE=0` to disable the automatic demo in production.
- Partially configured production credentials **fail closed** instead of falling back to demo.

The user interface has no demonstration-mode banner; the account name is shown normally. This **public test identity** is not an administrative account and does not grant hosting filesystem access.
**Never configure a real account as `demo / demo123`.** That combination is rejected in live mode.

This sample login can be tested locally with:

```bash
php -S 127.0.0.1:8080 -t public public/router.php
```

PHP must run as a non-root account, and insecure HTTP mode (`FFM_ALLOW_HTTP_DEV=1`) may only be enabled in disposable local development.

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
| `FFM_MAX_ENTRIES` | No | Max results per listed directory, default `2000` |
| `FFM_UPLOAD_MAX_BYTES` | No | Max upload file size, default `104857600` bytes |
| `FFM_ALLOW_WORLD_WRITABLE` | No | `0` by default; blocks modes granting write to others |
| `FFM_ALLOW_HTTP_DEV` | No | Must be `0`/unset in production; only for local development |
| `FFM_DEMO_MODE` | No | Unset: automatic virtual read-only demo only if all three live credentials are absent; `0`: never enable demo; `1`: force isolated demo |
| `FFM_ALLOW_ROOT_DEV` | No | Must remain `0`/unset in production; root worker prohibited |

See `.env.example`. This is a **reference list**, not an automatically loaded `.env` file: configure actual process environment securely.

## Filesystem behavior and limits

- All backend paths are relative to `FFM_STORAGE_ROOT`; empty path represents the account home, **not Linux `/`**.
- A `..`, absolute path, invalid path component, or symlink traversal is rejected.
- Changing mode on the account root is blocked; **CHMOD applies only to the selected file/folder** (no recursive changes yet).
- Deletion is permanent in this alpha and requires confirmation. Directory removal works only for empty folders.
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
bash tests/demo-i18n-smoke.sh
php tests/deployment-mode.php
node --check public/assets/app.js
node tests/ui-structure.test.js
find src public bin tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

Optional offline Chromium tests `tests/browser-i18n.py` and `tests/browser-layout.py` verify locale switching, footer disk usage, no sidebar, a centered toolbar, 25-per-page pagination, search/sort behavior and restricted public sample credentials using mocked HTTP responses. Live-site browser E2E and accessibility review are still outstanding.

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
│   ├── Filesystem.php        # Account-scoped file operations
│   ├── DemoFilesystem.php    # Virtual read-only example data
│   ├── DeploymentMode.php    # Fail-closed deployment profile detection
│   └── Http.php              # Authentication, CSRF and API routing
├── bin/
│   └── hash-password.php     # Secure credential hash generation helper
├── tests/
│   ├── filesystem.php
│   ├── http-smoke.sh
│   ├── browser-layout.py  # Offline Chrome pagination verification
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

The longer-term goal is an extensible alternative to traditional hosting file managers with file recovery, secure archive extraction, per-account quotas, full ACLs, multi-tab code editor, resumable uploads, tenant-aware audit logging, plugin SDK, storage providers and ForgePanel SSO integration.

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
