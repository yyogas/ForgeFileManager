# Deployment and troubleshooting — v0.2.1-alpha

## Security requirement (not optional)

Only the `public/` directory may be reachable by HTTP. Keep `src/`,
`bin/`, `tests/`, `DOCUMENTATION/`, `.env`, hosting data, and
dependencies outside all document roots. Use a dedicated non-root PHP-FPM
pool per hosting account; a PHP session does not provide filesystem isolation.

**Recommended:** dedicate an HTTPS hostname with `DocumentRoot`
pointing to the absolute `ForgeFileManager/public/` directory.

## Nested URL (staging only)

`https://test.example.com/ForgeFileManager/public/` now loads
its assets and calls `api.php` under that prefix. However,
the URL alone does not prove that files above `public/` are private.

### Diagnostic checks

1. In DevTools → Network, ensure `assets/app.css`, `assets/app.js`
   and `api.php?action=me` load under the **same** `/public/` path.
2. When logged out, `api.php?action=me` must return HTTP 401 JSON.
   HTTP 503 means `FFM_USERNAME`, `FFM_PASSWORD_HASH`, or
   `FFM_STORAGE_ROOT` is not configured for the PHP process.
3. Verify that `/ForgeFileManager/README.md` is denied (403/404),
   **not** HTTP 200. Apply the same deny policy to all non-public
   paths including `.env`. A missing `.env` alone proves nothing.
4. Over HTTPS the `FFMSESSID` cookie must use `Secure`,
   `HttpOnly`, `SameSite=Strict`, and the installed prefix.
5. Clear browser cache after copying the new CSS and JS.

## Runtime requirements

- HTTPS; `FFM_ALLOW_HTTP_DEV=1` is local development only.
- PHP 8.2+, required extensions and account-scoped file permissions.
- Set the required `FFM_*` environment variables in a secure process
  configuration; never commit secrets or expose them via the web.
- Create the password hash via `php bin/hash-password.php`.
- Keep storage outside of the webroot and separate hosting tenants
  at the OS identity/PHP-FPM pool level.

## Automated checks

Run `php tests/filesystem.php`,
`php tests/advanced-operations.php`,
`bash tests/http-smoke.sh`,
`bash tests/nested-installation.sh`,
and `node --check public/assets/app.js`.

Browser E2E and an independent security audit remain outstanding.
