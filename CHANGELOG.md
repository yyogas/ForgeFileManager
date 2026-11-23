# Changelog

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