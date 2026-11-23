# Forge File Manager

**A modern, account-scoped PHP file manager for web hosting environments.**

> **Status:** `v0.1.0-alpha` — an original, testable **UI + secure-core foundation**. Not production-ready. Not yet a replacement for cPanel File Manager. This repository has **not** imported the external `filemanager-master` distribution because `https://download.wcp360.com/?dir=filemanager-master` could not be retrieved during this development pass. An audited upstream import/migration remains pending; no upstream source code or provenance is falsely claimed.

Forge File Manager is designed to provide a clean, responsive file-management experience for hosting users while enforcing access through a configured hosting-account filesystem root. The current implementation uses native PHP 8.2+, framework-free JavaScript and CSS with no runtime CDN dependency.

## UI preview: product structure

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│  [▣] Forge File Manager                 [Notifications] [Settings] [USER ▾] │
├──────────────────────────────────────────────────────────────────────────────┤
│ [+ Folder] [+ File] [Upload] [Download] [Copy] [Move] [Rename] [CHMOD] [ZIP]│
├───────────────┬──────────────────────────────────────────────────────────────┤
│ Navigation    │ My files                                      [List] [Grid] │
│  Home         │ home  >  public_html                                          │
│  Folders      │ [Search within directory...]   [Hidden]  [Sort]             │
│   public_html │ □ Name           Type       Size       Modified      CHMOD   │
│   logs        │ □ assets         Directory     —         ...          755   │
│   backups     │ □ index.php      PHP         ...        ...          644   │
│               │                                                              │
│ Server volume │ Right-click any item to open its contextual actions.        │
├───────────────┴──────────────────────────────────────────────────────────────┤
│ /public_html                   3 items                             ● Online │
└──────────────────────────────────────────────────────────────────────────────┘
```

The username in the top-right header is returned by the authenticated server session, **not** a hard-coded demo string. The top bar contains working account, settings, notifications and logout menus. The second header prioritizes core file operations.

## Implemented features in this alpha

| Area | Actual implementation |
| --- | --- |
| Authentication | Password-hash verification, PHP session, logout, no default credentials |
| Security | CSRF on mutations, scoped storage root, path-segment and symlink rejection, HTTPS required except explicit development setting |
| Navigation | Directory browser, breadcrumbs, root-folder sidebar, list/grid switch, search current directory, hidden-file toggle, sorting |
| Header | Dynamic account username, profile dropdown, preferences, notifications |
| Action toolbar | Create file/folder, multi-file upload, single-file download, copy single file, move, rename, CHMOD, ZIP if extension available, refresh, delete |
| Context menu | Right-click actions for file, folder and empty background; per-row action button for touch devices |
| CHMOD | Interactive owner/group/other matrix, live octal and symbolic display, explicit server-side policy |
| Editor | UTF-8 text editor with CTRL/CMD+S and optimistic SHA-256 conflict checks, 1 MB default limit |
| Transfers | Authenticated file upload and attachment download; no overwrites |
| ZIP | Archive a file or directory if `ext-zip` is present; 100 MB uncompressed directory limit, depth and item-count limits |
| Interface | Flat responsive design, dark/light mode, density options, notification toasts, keyboard shortcuts |
| Testing | PHP filesystem tests and authenticated HTTP smoke tests |

**Not implemented yet:** full folder copy, recursive delete, safe extraction, recycle bin, file versioning, ACL management, advanced syntax highlighting, multi-tenant SSO, quota enforcement, background transfers, resumable chunks, external storage, search indexing, fully localized UI, developer terminal, and plugin SDK. Buttons for unavailable operations are disabled or absent. The UI shows server disk usage **as server volume usage, not as an account quota**.

## Requirements

- PHP **8.2+** (test target: 8.2, 8.3, 8.4).
- Linux hosting environment; standard readable/writable directory controlled by the account.
- PHP extensions: `fileinfo` for previews; `zip` optional for ZIP creation.
- HTTPS with a web server configured so the **only web document root is `public/`**.
- Dedicated **non-root** hosting account PHP-FPM worker or equivalent verified OS isolation.

No database, Node build system or Composer installation is required for the first alpha.

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

### Configuration

| Environment variable | Required | Meaning |
| --- | --- | --- |
| `FFM_USERNAME` | Yes | Exact login username returned in the header |
| `FFM_PASSWORD_HASH` | Yes | PHP `password_hash()` value; no default password |
| `FFM_STORAGE_ROOT` | Yes | Existing, account-owned filesystem root, never `/` |
| `FFM_EDITOR_MAX_BYTES` | No | Max editor file size, default `1048576` bytes |
| `FFM_MAX_ENTRIES` | No | Max results per listed directory, default `2000` |
| `FFM_ALLOW_WORLD_WRITABLE` | No | `0` by default; blocks modes granting write to others |
| `FFM_ALLOW_HTTP_DEV` | No | Must be `0`/unset in production; only for local development |
| `FFM_ALLOW_ROOT_DEV` | No | Must remain `0`/unset in production; root worker prohibited |

See `.env.example`. This is a **reference list**, not an automatically loaded `.env` file: configure actual process environment securely.

## Filesystem behavior and limits

- All backend paths are relative to `FFM_STORAGE_ROOT`; empty path represents the account home, **not Linux `/`**.
- A `..`, absolute path, invalid path component, or symlink traversal is rejected.
- Changing mode on the account root is blocked; **CHMOD applies only to the selected file/folder** (no recursive changes yet).
- Deletion is permanent in this alpha and requires confirmation. Directory removal works only for empty folders.
- Uploads reject an existing destination rather than overwrite it.
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
bash tests/http-smoke.sh
node --check public/assets/app.js
find src public bin tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

A browser test script is supplied as an **optional development check**; it requires Playwright, Chromium, and an environment that permits localhost browser access. Browser UI automation was blocked by the execution environment during this work and should not be represented as passed.

## Project layout

```text
.
├── public/
│   ├── index.php             # Main UI shell and icon sprite
│   ├── api.php               # Authenticated HTTP API entry point
│   ├── router.php            # PHP dev router
│   └── assets/
│       ├── app.css           # Flat responsive design system
│       └── app.js            # Explorer, context menus and dialogs
├── src/
│   ├── Filesystem.php        # Account-scoped file operations
│   └── Http.php              # Authentication, CSRF and API routing
├── bin/
│   └── hash-password.php     # Secure credential hash generation helper
├── tests/
│   ├── filesystem.php
│   ├── http-smoke.sh
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