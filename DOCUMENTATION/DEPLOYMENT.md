# Deployment and troubleshooting — v0.2.3-alpha

## Security requirement (not optional)

Only the `public/` directory may be reachable by HTTP. Keep `src/`, `bin/`, `tests/`,
`DOCUMENTATION/`, `.env`, private hosting data, and any vendor dependencies outside
all document roots. Host this alpha version on a dedicated test account; do not
run it as `root` or in a shared PHP pool that can read other hosting accounts.

**Recommended**: a dedicated HTTPS hostname whose `DocumentRoot` is the absolute
path to `ForgeFileManager/public/`, with an account-scoped PHP-FPM pool.

## Nested test URL (for staging only)

A URL like `https://test.example.com/ForgeFileManager/public/` works with the
new relative asset and API routes. However, this URL is **not proof** that
`/ForgeFileManager/src/`, `/ForgeFileManager/.env`, and other private paths
are blocked by the web server. The simplest secure solution is to change
the vhost to serve only `public/` under a dedicated hostname, or use a tested
alias/deny configuration with no parent directory exposure.

### Fast checks

1. Load the page and open DevTools → Network. Ensure `assets/app.css`,
   `assets/app.js` and `api.php?action=me` resolve under the same `/public/` prefix.
2. When signed out, `api.php?action=me` must return HTTP 401 JSON.
   HTTP 503 indicates missing `FFM_USERNAME`, `FFM_PASSWORD_HASH`,
   or `FFM_STORAGE_ROOT` in the PHP-FPM process environment.
3. Check that `https://<host>/ForgeFileManager/README.md` is not publicly
   accessible (403/404 rather than 200). The same restriction must apply to
   `.env` and all non-public files. A missing `.env` alone is not proof.
4. Inspect the cookie: `FFMSESSID` should be `Secure` over HTTPS, `HttpOnly`,
   `SameSite=Strict`, and scoped to the installed `/public/` path.
5. Clear the browser cache for `app.js` and `app.css` after deployment.

## Minimum runtime configuration

- HTTPS enabled. `FFM_ALLOW_HTTP_DEV=1` is **local development only**.
- PHP 8.2+, extensions and permissions per repository README.
- `FFM_USERNAME`, `FFM_PASSWORD_HASH` and `FFM_STORAGE_ROOT` supplied
  securely to the application process by PHP-FPM/environment configuration.
- Password hash generated using `php bin/hash-password.php`; never commit a
  password or actual hash in Git or expose `.env` over the web.
- Filesystem root owned by the intended hosting account and outside webroot.
- Server logs checked for PHP errors; avoid displaying stack traces to users.

## Why using a `/public/` URL can be dangerous

Pointing a general-purpose web root at a directory containing the *entire*
Git repository allows requests to reach internal files directly unless a
correct web-server deny policy is in place. This is not fixed by relative
asset paths. Hosting accounts must be isolated at the OS/PHP-FPM layer.

## Automated checks

- `php tests/filesystem.php`
- `php tests/advanced-operations.php`
- `bash tests/http-smoke.sh`
- `bash tests/nested-installation.sh`
- `node --check public/assets/app.js`

Browser-based UI and accessibility tests are still outstanding.

## Why `bin/hash-password.php` does not open as a web page

The file intentionally exits unless executed by PHP CLI. Run `php bin/hash-password.php`
through SSH. Do not grant HTTP access to `bin/`, `src/`, secrets, or storage.
The former auto-starting virtual demo has been removed. The login UI still shows
`demo / demo123` as a test hint; those known credentials work **only** in an
explicitly configured local, private test sandbox. Public installations require
real credentials created by the CLI helper.

## Private recycle-bin deployment

- Provision a separate, owner-only `FFM_TRASH_ROOT` on the same filesystem as
  `FFM_STORAGE_ROOT`, outside both the account root and all web/application paths.
- Configure it in the dedicated non-root PHP-FPM pool, then check that
  `GET api.php?action=me` advertises `capabilities.trash=true` after login.
- If unset or invalid, deletion through HTTP is blocked rather than permanent.
- Recovery is alpha-grade; retention, audited purge, snapshots and verified
  disaster recovery remain future work. Do not point trash at a shared directory.

## Language preference

English (`en`) is the default for new browsers. Français (`fr`) is selectable on
both sign-in and the application header, and from Settings. Catalogs are
`public/languages/en.json` and `public/languages/fr.json`. The selected
language is stored in browser localStorage. The current release does not
translate every backend-generated error message.
