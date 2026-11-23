## 0.2.8-alpha — 2026-11-23

### Added
- Explicit PUBLIC SHOWCASE mode: browser-accessible `demo / demo123` login with real, deliberately synthetic sample files only.
- Dedicated CLI-only fixture installer (`bin/prepare-public-showcase.php`) requiring an empty, private, non-root-owned directory.
- Public-mode fixed file allowlist, sample checksums, secure root/owner checks and no mixed live hosting credentials.
- Public showcase HTTP and PHP regression tests, including forbidden mutations, marker hiding and unexpected-file fail-closed checks.
- Public showcase hosting guide with isolated PHP-FPM profile and HTTPS deployment instructions.

### Security
- Public showcase is strictly read-only on the server; writing actions return HTTP 403 even with a valid session and CSRF token.
- Public showcase cannot share the same PHP-FPM configuration as a live hosting account.
- Previous localhost-only test sandbox remains available independently; old virtual demo remains removed.
- Public deployment requires operator configuration and review; updating GitHub does not deploy to test.wcp360.com.

## 0.2.7-alpha — 2026-11-23

### Security and integrity hardening
- Validate root ownership and optional account UID, reject system-wide storage roots.
- Bind sessions to account root, password hash and effective Unix UID.
- Reject regular hardlinks, including potential links outside the account root.
- Harden UTF-8 file edits with locking, private staging, revision conflicts,
  inode checks, permission preservation and modified timestamp reporting.
- Verify CHMOD actually applied and return last modification time.
- Create ZIPs via constrained private staging, without overwrites.
- Restore validated file/directory timestamps during safe ZIP extraction.
- Add PHP and HTTP regressions for isolation, editor, CHMOD, timestamps and archives.
- Verify file handles and path inode before reading/downloading/previewing/checksumming.
- Require ext-zip within GitHub CI, so ZIP tests cannot silently skip.

### Known limitations
- PHP filesystem primitives are not a complete race-safe isolation boundary.
- No multi-tenant support under a shared PHP/Unix identity.
- Ext-zip is absent in local sandbox; full ZIP tests require CI validation.
- Production deployment still requires dedicated Unix user, webroot hardening and independent review.

## 0.2.6-alpha — 2026-11-23

### Added
- Account-scoped ZIP extraction into a dedicated new folder with strict entry validation, symlink and ZIP-slip rejection, ratio/size/depth/entry caps, CRC verification, private staging and best-effort cleanup.
- Multi-select copy/move (max 25 entries per request) with explicit item-by-item partial results; no destructive bulk actions.
- ZIP extraction action in the sub-header and right-click menu; capability checks and bilingual messaging.
- Security regression suites for archive inspection, bulk transfer and authenticated HTTP flows.
- `DOCUMENTATION/ARCHIVES-AND-BULK.md` documenting operation contracts and limitations.

### Remaining limitations
- ZIP extraction depends on PHP ext-zip and must pass enabled CI; local environment without ext-zip skips extraction tests.
- Directory staging and PHP rename are not fully race-free against hostile same-UID processes.
- No asynchronous queue, cancellation, transactional batch rollback, antivirus or resource telemetry.

## 0.2.5-alpha — 2026-11-23

### Added
- Private, explicitly configured recycle bin with recoverable file and non-empty-directory moves.
- Restore dialog, 25-entry recycle-bin pagination, safe conflict handling and bilingual strings.
- Same-filesystem/owner/private-mode validation for `FFM_TRASH_ROOT`; no public webroot or account overlap.
- Server-side refusal of symlink-bearing directory trees; bounded recursion and count.
- Direct PHP, HTTP, locale and offline browser regression suites.

### Changed
- Disabled/removed the automatic virtual demo and its synthetic sample filesystem.
- Kept visible `demo / demo123` test credentials, usable exclusively in an explicitly configured **localhost** storage sandbox, never with real hosting data.
- Deleted HTTP actions now **require** a valid private recycle bin; no silent permanent-delete fallback.

### Remaining limitations
- Retention, trash quotas, durable audit journal, permanent purge, and dedicated backup/recovery processes remain pending.
- Concurrency with other processes sharing the account's Unix identity is not fully race-free; independent security review needed.
- Localhost browser test with real PHP could not be completed in the execution environment; offline Chromium and HTTP tests provide partial coverage.

## 0.2.4-alpha — 2026-11-23

### Added / changed
- **Real server-side pagination** of hosting directories with 25 entries per response, full-directory search, sort, and hidden-file filtering.
- Stable directory-first natural sorting with explicit `page`, `pages`, `pageSize`, `total` and `hasNext` API fields.
- Jump-to-page input for folders spanning more than seven pages; bounded vertical scrolling remains unchanged.
- Debounced search and stale-response protection, with selection always limited to the current server page.
- Configurable `FFM_LIST_SCAN_LIMIT` (default 50,000; hard maximum 200,000); HTTP 413 on overflow rather than silent truncation.
- Regression suite for 2,055 real files, HTTP parameter validation, and Chromium 301-entry page-jump interaction.

### Limitations
- Sorting still scans the whole directory using bounded PHP memory; it is not backed by a database index or consistent snapshot.
- Concurrent filesystem changes can shift offset pagination between requests. Cursor/continuation protocols and storage-provider adapters are future work.
- Localhost browser E2E is blocked by sandbox policy; offline Chromium fixtures and HTTP smoke tests are available.

# Changelog

## 0.2.3-alpha — 2026-11-23

### Layout and interaction
- Removed sidebar and redundant explorer titles. Navigation remains available via breadcrumbs.
- Centered main action toolbar and kept mobile horizontal scroll.
- Moved server disk usage, unavailable fallback, and server-volume disclaimer to the footer.
- Added vertical file-list scroll, sticky table headers, and client-side 25-item pagination for list/grid with accessible previous/next, numbered pages and range summary.
- Search and sort reset pagination; page changes clear selection; selecting all applies only to the current page.
- Removed visual demonstration banner but preserved the visible `demo / demo123` sample credentials at unconfigured sign-in, with server-side virtual read-only isolation intact.
- Updated English/French catalogs, browser tests and UI documentation.

### Known limitation
- PHP listing still caps directory results at `FFM_MAX_ENTRIES` (default 2,000); true server pagination remains planned.


## 0.2.2-alpha — 2026-11-23

### Added
- Automatic `demo / demo123` read-only virtual sandbox when no live account is configured.
- Explicit `FFM_DEMO_MODE=1` to force sample-only mode; `FFM_DEMO_MODE=0` disables fallback.
- English and French translation catalogs, language selectors on login/header and in settings, persisted browser preference.
- Real storage is never initialized in demo; uploads, downloads, file modifications and CHMOD are denied on the server.
- Browser-offline bilingual UI smoke and HTTP security regression tests.

### Security
- Partially configured live credentials fail closed; weak demo/demo123 credentials are rejected for real hosting accounts.
- `bin/hash-password.php` remains CLI-only; never use its URL as an administration endpoint.
- Standalone production remains not security-audited and must expose only `public/`.

## 0.2.1-alpha — 2026-11-23

### Fixed
- Resolve CSS, JS, API calls, uploads, image previews and downloads relative to the installation prefix instead of `/`.
- Scope the PHP session cookie to its deployed URL prefix.
- Add a visible loading/failure hint and a no-JavaScript explanation.

### QA / Operations
- Add a nested-directory HTTP deployment regression test to GitHub Actions.
- Document safe webroot configuration and troubleshooting for subdirectory installations.
- Browser E2E remains blocked by the execution environment; no live-site visual confirmation.

## 0.2.0-alpha — 2026-11-23

### Added
- Bounded directory copy with 100 MiB content limit, 2,000 entry limit and 20 level depth limit.
- Refusal of nested symlinks, self-copy and copy into descendants; best-effort staging rollback.
- Collision-safe file and folder duplicates with numbered suffixes.
- SHA-256 checksum API and on-demand action from file Properties.
- Configurable per-file upload size policy (`FFM_UPLOAD_MAX_BYTES`) and browser preflight warning.
- Keyboard arrow/Home/End navigation for contextual action menus.
- 21 new filesystem regression assertions and expanded authenticated HTTP smoke tests.

### Outstanding
- Browser E2E and assistive technology validation are pending.
- The original `filemanager-master` upstream content remains inaccessible and has not been imported.
- No ZIP extraction, recycle bin, hosting-panel SSO, per-account quotas or production security sign-off.

## 0.1.0-alpha — 2026-11-23

### Added
- First-party standalone PHP file management foundation (not an import of `filemanager-master`).
- Responsive flat UI: account header, toolbar, sidebar, breadcrumbs, list/grid, settings, theme.
- Context menu for files/folders and blank space; touch-friendly per-item menu.
- CHMOD 3×3 editor with numeric and symbolic modes.
- File operations: list, create, rename, copy file, move, upload, download, UTF-8 edit, non-recursive delete, optional ZIP creation.
- No-default-credential login, sessions, CSRF protection and scoped paths.
- Filesystem and HTTP test suites, GitHub Actions workflow.

### Known limitations
- External source package could not be retrieved; original source migration pending.
- No multi-user hosting panel SSO, ACLs, recycle bin, safe ZIP extraction, recursive delete or per-account quotas.
- Browser automation was blocked by sandbox localhost policy, not verified.
- Security review and production isolation validation still required.

## 0.2.9-alpha — 2026-11-23

### Added
- Opt-in, full-operation public interactive test profile with known `demo / demo123` credentials, strictly isolated from hosting account mode.
- Per-login random, temporary private storage and private recycle bin; example files seeded automatically.
- Scoped create/edit/rename/upload/download/move/copy/CHMOD/ZIP/extract/trash/restore under existing core authorization.
- 30-minute workspace expiry and scheduled cleanup utility; capped concurrent sessions and best-effort resource budgets.
- PHP, HTTP and two-independent-session regression coverage and deployment guide.

### Security
- No default write access for public credentials to real hosting files. Read-only showcase remains unchanged.
- Interactive profile refuses mixed live configuration, non-private directory ownership, dangerous roots or world-writable CHMOD policies.
- Requires separate OS/container isolation, hard quotas and reverse-proxy rate limits before public deployment. Pending security review.
