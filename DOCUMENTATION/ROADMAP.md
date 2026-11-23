# Forge File Manager — Roadmap

## P0 — Original alpha foundation (implemented, QA ongoing)

- [x] Responsive flat header/sub-header/sidebar/file list.
- [x] Account username / dropdown / settings.
- [x] File/folder context menus and CHMOD editor UI.
- [x] Native PHP scoped filesystem API.
- [x] Authentication, CSRF and no-default credentials.
- [x] Core filesystem and HTTP smoke tests.
- [ ] Complete browser E2E and accessibility audit.
- [ ] Import and review requested external upstream source.

## P1 — Safe operations

- [ ] Recycle bin and restore with retention limits.
- [x] Bounded directory copy with nested-symlink refusal, staging and preflight; move-into-descendant rejection.
- [x] Collision-free file/folder duplication.
- [x] SHA-256 on-demand file integrity check (100 MiB synchronous limit).
- [x] Per-file upload size policy (server and client).
- [ ] ZIP extraction with bomb/path/symlink protections.
- [ ] Bulk selection actions with bounded execution.
- [ ] Upload quotas, type policies, malware-scan hooks.
- [ ] Multipart chunked/resumable uploads, progress and cancel.

## P2 — Professional editing and audit

- [ ] Monaco/CodeMirror evaluation and integrated syntax highlighter.
- [ ] Multi-tab editor, unsaved changes and diff.
- [ ] Persistent file activity/audit records with redaction.
- [ ] File revisions and atomic conflict resolution.
- [ ] Keyboard and screen-reader acceptance tests.

## P3 — Hosting integration

- [ ] Panel SSO contracts and session handoff.
- [ ] Per-hosting-account OS isolation, reseller scopes and permissions.
- [ ] Real account quota and disk usage integration.
- [ ] API versioning, OpenAPI and contract tests.
- [ ] ForgePanel adapter without generic privileged execution.

## P4 — Extensibility and scale

- [ ] Storage adapters (SFTP, S3-compatible).
- [ ] Background jobs and streaming operations.
- [ ] ACL provider and audited recursive CHMOD.
- [ ] Plugin manifest, signing, scopes and safe lifecycle.
- [ ] Search and indexing engine with tenant-bound results.

Each checkbox is an engineering work item, not a product promise. Publish only tested capabilities in releases.
