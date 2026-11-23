# Feature Matrix

Legend: `VERIFIED` (tested), `IMPLEMENTED` (code exists; not independently browser-verified), `PARTIAL`, `PLANNED`, `BLOCKED`.

| Feature | Status | Notes |
| --- | --- | --- |
| Configurable hosting account root | VERIFIED | PHP tests and HTTP smoke |
| Session authentication | VERIFIED | No password defaults |
| CSRF | VERIFIED | Mutating request without CSRF blocked |
| Header username | IMPLEMENTED | Server session data |
| Settings dropdown | IMPLEMENTED | Theme, hidden files, compact density |
| Primary toolbar | IMPLEMENTED | Selection-state gated |
| Right-click context menu | IMPLEMENTED | File/folder/blank context |
| Mobile per-row action button | IMPLEMENTED | Responsive CSS |
| Folder listing, navigation, path restrictions | VERIFIED | PHP tests / HTTP smoke |
| SHA-256 checksum | VERIFIED (HTTP) | File properties action, 100 MiB limit, regression tests |
| Duplicate file/folder names | VERIFIED | ` (copy)` and numbered collision-free variants |
| Context menu keyboard arrows | IMPLEMENTED | Browser/AT audit pending |
| Grid/list/sort/current-folder filter | IMPLEMENTED | No browser confirmation |
| Create, rename, move and file copy | VERIFIED | PHP tests |
| File upload | VERIFIED (HTTP) | Per-file limit enforced server-side; browser flow not tested |
| Download file | IMPLEMENTED | Attachment headers |
| UTF-8 edit and conflict check | VERIFIED | PHP tests |
| CHMOD matrix | IMPLEMENTED | JS matrix, PHP backend verified |
| World-writable CHMOD policy | VERIFIED | PHP tests |
| ZIP archive generation | PARTIAL | Implemented, ext-zip missing in local runtime |
| ZIP extraction | PLANNED | Disabled until security audit |
| Full directory copy | VERIFIED | Preflight limits and nested-symlink rejection, 21 advanced regression tests + HTTP smoke |
| Recursive delete | BLOCKED | Intentionally disabled to prevent irreversible mass deletion |
| Recoverable private trash / restore | VERIFIED locally | 14 direct PHP assertions, HTTP test, offline UI rendering; private configuration required |
| Automatic virtual demo | REMOVED | No public demo fallback; the old demo filesystem is deleted |
| Versioning / trash retention / purge | PLANNED | Not implemented |
| ACL/chown | PLANNED | Not implemented |
| Syntax highlighting / Monaco | PLANNED | Current editor is plain textarea |
| Hosting panel SSO | PLANNED | No current integration |
| Multiple isolated users under one PHP pool | BLOCKED | Requires OS-level isolation design |
| External source `filemanager-master` | BLOCKED | Requested host inaccessible |
| Browser E2E | BLOCKED | Browser network policy prevents localhost access |

## v0.2.2-alpha: language and demo additions

| Feature | Status | Evidence |
| --- | --- | --- |
| Default demo credentials `demo / demo123` with no live configuration | VERIFIED | HTTP smoke and offline UI test |
| Virtual demo filesystem only, no live filesystem access | VERIFIED | HTTP smoke against deliberately sensitive test directory |
| Demo writes and downloads prohibited server-side | VERIFIED | HTTP regression tests |
| Real-mode `demo / demo123` rejected | VERIFIED | Dedicated authentication test |
| English and French catalog delivery | VERIFIED | HTTP tests and JSON validation |
| English default UI and language switch | VERIFIED locally | Offline Chromium UI exercise |
| Full localization of PHP error messages | PLANNED | Server errors currently returned in English |

## v0.2.3-alpha: layout and paging

| Feature | Status | Evidence / limitation |
| --- | --- | --- |
| Sidebar and redundant explorer titles removed | VERIFIED locally | HTML checks and offline Chromium |
| Header user, centered action sub-header | VERIFIED locally | Offline Chromium geometry check |
| Footer server disk usage and unavailable fallback | VERIFIED locally | Offline Chromium English/French checks |
| Vertical file-list scroll | VERIFIED locally | Browser computed CSS and scroll region |
| 25 visible items per page | VERIFIED locally | Browser fixture with 61 entries (25 / 25 / 11) |
| Pagination in list and grid modes | VERIFIED locally | Browser fixture |
| Reset page on search / sorting and directory navigation | IMPLEMENTED | Browser search verified; automated smoke for sorting planned |
| Selection limited to currently visible page | VERIFIED locally | Browser fixture; selection cleared on page navigation |
| Public sample username/password still displayed, no demo banner | VERIFIED locally | Browser UI checks |
| Public sample access restricted to virtual read-only files | VERIFIED | Existing HTTP security checks |
| Server-side pagination beyond `FFM_MAX_ENTRIES` | VERIFIED locally | 2,055 actual entries; HTTP pagination and Chromium fixture |

## v0.2.4-alpha — server pagination

| Feature | Status | Evidence |
| --- | --- | --- |
| 25 entries per server response | VERIFIED locally | `tests/server-pagination.php` and HTTP smoke |
| Full-directory search/sort/hidden filter | VERIFIED locally | 2,055-file PHP fixture + HTTP query tests |
| Current-page selection only | VERIFIED locally | Chromium offline fixture |
| Debounced search, stale-request guard | IMPLEMENTED | JavaScript inspected; browser race test planned |
| Jump to arbitrary page | VERIFIED locally | Chromium 301-file fixture; page 10 |
| Directory scan limit / explicit HTTP 413 | VERIFIED locally | `tests/server-pagination.php` |
| Cursor/snapshot pagination / indexed search | PLANNED | Offset results may shift under concurrent mutations |

## v0.2.5-alpha — recovery and demo retirement

| Feature | Status | Evidence / limitation |
| --- | --- | --- |
| Automatic read-only virtual demonstration | REMOVED | No public demo or synthetic filesystem; source deleted |
| Visible `demo / demo123` information | IMPLEMENTED | Shown on sign-in screen with local-test-only warning |
| Explicit localhost test sandbox | VERIFIED locally | Environment, mode and real hosting isolation tests |
| Public known password on live account | BLOCKED | Production hash rejected and live account required |
| Private trash initialization | VERIFIED locally | 0700 mode, owner/mount, non-overlap checks |
| Recoverable file/directory deletion | VERIFIED locally | File/recursive directory moves, symlink denial |
| Restore and conflict avoidance | VERIFIED locally | Tests for exact bytes and existing targets |
| Trash modal + pagination | IMPLEMENTED | DOM/browser offline tests; real PHP browser E2E not verified |
| Retention / purge / audit logging | PLANNED | Requires contracts and tests |
