# Public Interactive Playground — v0.2.9-alpha

## Purpose

This opt-in profile allows public visitors to explore **full file-manager operations** using `demo / demo123`, **without being able to manage any real hosting/customer data**. Every login creates a unique random server-side workspace. Unlike the earlier read-only public showcase, visitors can create, edit, upload, download, rename, move, copy, change CHMOD, compress/extract ZIP (when `ext-zip` is available), recycle and restore files **only inside their own temporary workspace**.

This is an untrusted-user service. **It is NOT a production hosting account, a root shell, or an enterprise-grade isolation guarantee.** Do not expose public credentials to any real storage. Separate production admin and customer hosting access into different Unix accounts and ideally different servers/containers.

## Hard deployment requirements

- Dedicated non-root Linux identity and PHP-FPM pool or isolated container for the public playground; **no shared UID, home directory, secret, key, database credentials, service socket or filesystem mount with real hosting customers**. Do not mount `/var/run/docker.sock`, the host filesystem or other sensitive sockets into the demo container.
- PHP 8.2+, `fileinfo`, `posix` and `zip` for compression/decompression.
- Private, empty, **unlinked** workspace base directory owned by the dedicated pool, mode `0700`, **outside** all HTTP DocumentRoots and application source directories, on a dedicated quota-enforced filesystem/volume if possible.
- Serve **only `ForgeFileManager/public/`** over HTTPS. The previous URL `/ForgeFileManager/public/` can expose private files if its parent is inside an unrestricted web document root. Prefer a dedicated subdomain whose document root points directly at `public/`.
- An OS/container **disk quota**, memory, CPU, file descriptors, process/thread and network limits. App-side budgets are best-effort and cannot substitute for kernel-level limits or rate limiting. Prefer no outgoing network from the demo container unless explicitly required.
- Reverse-proxy rate limits on login, upload, mutation and total requests. Protect against abusive unauthenticated clients and excessive sessions. Include host-level logging and monitoring; never log credentials or contents.
- No public access to the management panel or the host's actual account files through these credentials.

## Setup (example paths; adjust to your host)

1. Create a dedicated OS account and its own PHP-FPM pool (or sandboxed container). For example `forge-demo`. **Do not use an existing hosting account, root, or a shared PHP worker**.
2. From SSH as an administrator, provision an empty dedicated directory, with the dedicated worker as owner:

   ```bash
   sudo install -d -m 0711 -o root -g root /srv/forge-public-playground
   sudo install -d -m 0700 -o forge-demo -g forge-demo /srv/forge-public-playground/workspaces
   sudo -u forge-demo php /path/to/ForgeFileManager/bin/prepare-public-playground.php /srv/forge-public-playground/workspaces
   ```

   The parent `/srv/forge-public-playground/` must also be traversable by the dedicated worker but not shared with customer accounts. The installer refuses nonempty target directories; it creates a verified marker and private `sessions`/`trash` directories. Do not manually copy live hosting files here.

3. Set these environment variables in the **public demonstration pool only**:

   ```ini
   env[FFM_PUBLIC_PLAYGROUND] = 1
   env[FFM_PUBLIC_PLAYGROUND_ROOT] = /srv/forge-public-playground/workspaces
   ```

   Ensure `FFM_PUBLIC_SHOWCASE`, `FFM_TEST_SANDBOX`, `FFM_DEMO_MODE`, `FFM_USERNAME`, `FFM_PASSWORD_HASH`, `FFM_STORAGE_ROOT`, `FFM_TRASH_ROOT` and `FFM_ALLOW_WORLD_WRITABLE` are unset (or explicit public flags `0` where applicable), and do not enable development HTTP/root bypasses. The PHP-FPM pool must use the dedicated OS user. Enforce HTTPS at the trusted web server.

4. Validate and reload the **correct** PHP-FPM service on your server (example for PHP 8.3):

   ```bash
   sudo php-fpm8.3 -t
   sudo systemctl reload php8.3-fpm
   ```

5. Run cleanup every five minutes **as the same dedicated Unix user**:

   ```cron
   */5 * * * * FFM_PUBLIC_PLAYGROUND=1 FFM_PUBLIC_PLAYGROUND_ROOT=/srv/forge-public-playground/workspaces /usr/bin/php /path/to/ForgeFileManager/bin/prune-public-playground.php
   ```

   The script deletes only expired random-ID workspaces and their private trash folders. Review the retention policy and verify filesystem/backups are not accidentally preserving public uploads. Restrict logs.

6. Open your demo website and sign in with `demo / demo123`. A new workspace is created **per login**; it cannot see another visitor's workspace. No real hosting file is made accessible.

## Limits and behavior

- Max **30 simultaneously allocated workspaces**, max **30 minutes idle**, and **30-minute login lifetime**. After expiry, users log in again to receive a fresh workspace. A dedicated cleanup job reclaims expired data.
- Best-effort per-workspace allowance, including trash: **10 MiB stored bytes**, **250 files/directories**, **1 MiB maximum upload file**, **512 KiB maximum text editor file**. ZIP is available if `ZipArchive` is enabled. These are app checks; **enforce a hard total disk quota on the dedicated volume/worker**.
- The exposed `Server disk usage` widget does not reveal underlying host capacity in this profile.
- Public upload contents are **not served directly by the web server** and must never execute as PHP/CGI/scripts; the application can only stream authorized files as downloads or safe previews.
- No arbitrary shell execution, privileged CHOWN, root access, filesystem-mount operations, or access to customers' directories.
- File edits/sessions are ephemeral and are **not automatically synchronized to permanent storage**. A user may download files before expiry; do not treat the demo as backup.
- This alpha profile has not undergone independent penetration testing, load testing, nor all deployment checks. Do not enable it on a server with sensitive shared mounts.

## Validation

`php tests/public-playground.php` and `bash tests/public-playground-http.sh` exercise authentication, two independent users, authorized mutations, CSRF, path traversal, uploads, edition, CHMOD, trash and recovery. The ZIP subtest runs only with `ext-zip` available; CI explicitly requires that extension.

## Configuration conflicts

Public playground is a **third, distinct mode**: 

- `FFM_PUBLIC_SHOWCASE=1`: public fixture **read-only**, unchanged.
- `FFM_PUBLIC_PLAYGROUND=1`: per-visitor public **interactive** sandbox.
- No public flag: private hosting account with real, non-public credentials.

Combining profiles or setting a public flag alongside live credentials fails closed with an error. Nothing silently grants public users access to hosting data.
