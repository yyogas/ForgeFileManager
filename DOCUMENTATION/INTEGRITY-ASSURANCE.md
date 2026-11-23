# Forge File Manager — Filesystem Integrity Assurance (0.2.7-alpha)

**Release gate: BLOCKED — not production-approved.** PHP/HTTP regression checks pass locally on PHP 8.4. Actual ZIP encode/decode regression requires an ext-zip-enabled GitHub Actions runner. The runner jobs on the latest branch are failing before any steps are reported.

| Domain | Current implementation | Verification | Limitation |
| --- | --- | --- | --- |
| Account isolation | Canonical per-account root; reject traversal, symlinks, hard-linked regular files, shared/system-wide roots, UID/owner mismatch; optional FFM_EXPECTED_UID; sessions bound to account/root/password hash/mode/UID | tests/integrity-isolation.php; tests/integrity-http.sh | **Separate non-root Unix UID and PHP-FPM pool per hosting account required**. Standard PHP paths cannot defeat hostile same-UID races |
| File editor | Bounded UTF-8; optimistic SHA-256 revision; advisory lock; verified file handle; private staging, complete and synced writes, inode recheck, preserved permissions | PHP + HTTP regression | No collaborative conflict merge; same-UID external races remain |
| CHMOD | Files/folders only; 3-digit octal, account root blocked, world-writable blocked by default; verify inode and applied mode | PHP + HTTP actual stat checks | No ACL/chown or recursive mode |
| Last modified | Unix epoch + ISO UTC in listing; editor and CHMOD responses; stored file/directory mtime restored during ZIP extraction when sane metadata is provided | PHP + HTTP; ZIP test fixtures pending runner | ZIP timestamp precision may vary; CHMOD changes ctime, not mtime |
| Compression | ZIP regular files/directories, <=100 MiB source content, <=2000 entries and 20 levels; private staging, cleanup and no overwrite | Syntax; ZIP regression script waiting for ext-zip | ZIP runtime unverified without runner |
| Decompression | ZIP-only new destination; traversal/symlink/entry, ratio, CRC and size checks; 0600 files and 0700 directories | ZIP regression script waiting for ext-zip | No antivirus or full race-free filesystem sandbox |
| Preview, download, checksum | Verified regular-file descriptors, inode comparison, bounded preview and SHA-256, authenticated download | HTTP test for bytes/preview/hash | OS isolation is still essential |

## Deployment requirements

1. Dedicated **non-root Unix identity and PHP-FPM pool per hosting account**; never share one worker with combined privileges across customers.
2. `FFM_STORAGE_ROOT` must be an existing directory owned by the PHP worker. Avoid shared roots (`/`, `/home`, `/srv`, etc.) and storage-root symlinks. Pin `FFM_EXPECTED_UID` when feasible.
3. Expose only `public/` as an HTTP document root. Deny `.ffm-*` temporary paths within managed websites; never expose private configuration, internal PHP files, test fixtures or private trash.
4. Do not enable development root/HTTP overrides on public servers. The advertised `demo/demo123` login is not permitted on live hosting data.
5. Set `FFM_TRASH_ROOT` as private (0700), separate, same-owner/same-filesystem storage if recoverable deletion is needed. Without it, deletion is blocked.
6. Install required PHP extensions (fileinfo and zip), validate API and browser behavior under HTTPS, run tests on PHP 8.2, 8.3 and 8.4 and perform an independent security audit before release.

**Remaining risk:** standard PHP filesystem primitives cannot guarantee atomically no-replace directory renames or fully secure openat2-style traversal against adversarial concurrent processes sharing the same UID. This version is not production approved.