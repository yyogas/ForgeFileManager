## 0.2.1-alpha — 2026-11-23

### Fixed
- Resolve CSS, JS, API calls, uploads, image previews and downloads relative to the installation prefix instead of `/`.
- Scope the PHP session cookie to its deployed URL prefix.
- Add visible loading/failure hint and no-JavaScript fallback.

### Quality and operations
- Add a nested-directory HTTP deployment regression test to GitHub Actions.
- Document secure webroot configuration and subdirectory troubleshooting.
- Browser E2E remains blocked by the execution environment; no live-site visual confirmation.

# Changelog

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
