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
| Trash / versioning | PLANNED | Not implemented |
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
