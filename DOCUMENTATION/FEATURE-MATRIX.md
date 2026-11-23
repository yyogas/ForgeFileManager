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
| Grid/list/sort/current-folder filter | IMPLEMENTED | No browser confirmation |
| Create, rename, move and file copy | VERIFIED | PHP tests |
| File upload | IMPLEMENTED | Browser upload not tested |
| Download file | IMPLEMENTED | Attachment headers |
| UTF-8 edit and conflict check | VERIFIED | PHP tests |
| CHMOD matrix | IMPLEMENTED | JS matrix, PHP backend verified |
| World-writable CHMOD policy | VERIFIED | PHP tests |
| ZIP archive generation | PARTIAL | Implemented, ext-zip missing in local runtime |
| ZIP extraction | PLANNED | Disabled until security audit |
| Full directory copy | PLANNED | API returns 422 |
| Recursive delete | BLOCKED | Intentionally disabled to prevent irreversible mass deletion |
| Trash / versioning | PLANNED | Not implemented |
| ACL/chown | PLANNED | Not implemented |
| Syntax highlighting / Monaco | PLANNED | Current editor is plain textarea |
| Hosting panel SSO | PLANNED | No current integration |
| Multiple isolated users under one PHP pool | BLOCKED | Requires OS-level isolation design |
| External source `filemanager-master` | BLOCKED | Requested host inaccessible |
| Browser E2E | BLOCKED | Browser network policy prevents localhost access |