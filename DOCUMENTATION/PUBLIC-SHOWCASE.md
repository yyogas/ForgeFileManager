# Public showcase — Forge File Manager v0.2.8-alpha

## Purpose / Objectif

Make the **login page reachable over public HTTPS**, allow visitors to sign in with
**demo / demo123**, and demonstrate navigation, text previews, safe downloads,
search, sort and the 25-item-per-page UI. Public visitors **must never** be
able to write, edit, change CHMOD, upload, delete, create/extract ZIP files, or
access real hosting accounts. The mode is **not enabled by default**.

This is **not** the removed virtual demonstration feature. The new explicit
public-showcase profile reads a small, predefined set of **real, synthetic
files** in an account-owned private directory. The backend checks a fixed
file allowlist, SHA-256 contents, link types and permissions **on every request**.
Unexpected files, modified sample content or a wrong directory disable the
profile with HTTP 503 rather than exposing data.

## Architecture

- Public preview account: separate non-root Linux UID, dedicated PHP-FPM
  pool, private sample directory **outside webroot and repository**.
- API credentials: `demo / demo123` **only** when `FFM_PUBLIC_SHOWCASE=1`.
- All mutations blocked server-side with HTTP 403, even when forged by hand.
- Listing / read / download / preview / checksum limited to known sample paths.
- `.forge-public-showcase.json` marker is not available over the API.
- Real hosting credentials and `FFM_STORAGE_ROOT` are **prohibited** in this
  mode. The separate real-account deployment is unchanged.
- HTTPS required. Only CLI server bound to loopback may use development HTTP.
- Independent security review, rate limiting at the reverse proxy and
  production acceptance tests still required before public launch.

## Mise en place sur test.wcp360.com (ou tout site de test HTTPS)

**1. Créer un utilisateur Linux dédié à la présentation** (adapter au système) :

```bash
sudo useradd --system --home-dir /srv/forge-showcase --shell /usr/sbin/nologin forge-showcase
sudo install -d -m 700 -o forge-showcase -g forge-showcase /srv/forge-showcase
sudo install -d -m 700 -o forge-showcase -g forge-showcase /srv/forge-showcase/data
```

Choisir un autre nom s'il existe déjà. **Ne jamais utiliser root** ni un
utilisateur PHP-FPM qui a accès à d'autres comptes d'hébergement.
Le répertoire de démonstration doit être sur un espace privé distinct,
hors de tout DocumentRoot, et **entièrement vide**.

**2. Préparer uniquement des fichiers fictifs** :

```bash
sudo -u forge-showcase php /path/to/ForgeFileManager/bin/prepare-public-showcase.php /srv/forge-showcase/data
```

Le script refuse root, les répertoires non vides, les chemins symlinks et les
permissions non privées. Il crée des fichiers statiques d'exemple.

**3. Créer un pool PHP-FPM dédié**, exécuté sous `forge-showcase` :

```ini
[forge-showcase]
user = forge-showcase
group = forge-showcase
listen = /run/php/forge-showcase.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
security.limit_extensions = .php
env[FFM_PUBLIC_SHOWCASE] = 1
env[FFM_PUBLIC_SHOWCASE_ROOT] = /srv/forge-showcase/data
```

Le chemin du socket et l'utilisateur du serveur web varient selon la
distribution. Intégrer le pool dans la configuration PHP-FPM réellement
utilisée par le vhost. **Ne configurez aucune variable `FFM_USERNAME`,
`FFM_PASSWORD_HASH`, `FFM_STORAGE_ROOT`, `FFM_TRASH_ROOT` ou `FFM_TEST_*`
 dans ce pool.** `FFM_ALLOW_HTTP_DEV` et `FFM_ALLOW_ROOT_DEV` doivent rester
 désactivés sur Internet.

**4. Exposer uniquement `ForgeFileManager/public/` via HTTPS.** Le vhost doit
interdire toutes les autres sources du dépôt. Recommandation : un vhost dédié
au lieu d'un URL contenant `/ForgeFileManager/public/`. Un sous-chemin est
fonctionnel, mais **n'est pas un isolement filesystem**. Un fichier
`/ForgeFileManager/src/Http.php` ou `/ForgeFileManager/.env` ne doit jamais
être accessible via HTTP. Restriction réseau et limitation de débit requises.

**5. Vérifier et recharger PHP-FPM** (adapter le numéro de version) :

```bash
sudo php-fpm8.3 -t
sudo systemctl reload php8.3-fpm
```

**6. Vérifier via navigateur** : ouvrir le site sans session, vérifier que
`api.php?action=me` renvoie `401` (pas `503`), se connecter avec
`demo / demo123`, explorer les exemples, puis vérifier que `create`,
`chmod`, `save`, `upload`, `zip`, `extract` et `delete` renvoient `403` même
avec une session valide et un jeton CSRF. Vérifier que les fichiers hors du
répertoire d'exemples ne sont pas visibles.

## Settings / Variables

| Variable | Public showcase | Live hosting profile |
| --- | --- | --- |
| `FFM_PUBLIC_SHOWCASE` | `1` (opt-in) | unset / `0` |
| `FFM_PUBLIC_SHOWCASE_ROOT` | private synthetic sample path | unset |
| `FFM_USERNAME` | **must be unset** | private username |
| `FFM_PASSWORD_HASH` | **must be unset** | private bcrypt/Argon hash |
| `FFM_STORAGE_ROOT` | **must be unset** | account-owned hosting root |
| `FFM_TRASH_ROOT` | **must be unset** | optional private trash |
| `FFM_TEST_SANDBOX` | unset / `0` | unset / `0` |
| `FFM_DEMO_MODE` | unset / `0` | unset / `0` |
| `FFM_ALLOW_HTTP_DEV` | unset / `0` (HTTPS) | unset / `0` |
| `FFM_ALLOW_ROOT_DEV` | unset / `0` | unset / `0` |

Do not run the public showcase in the same PHP-FPM pool as production hosting
or the admin file manager. Do not point the public showcase at a production
file tree. One domain should expose only one deployment profile.

## Failure modes

- `503`: missing or invalid sample root, wrong owner, unexpected files, mixed
  real-account settings, bad marker, or server configuration failure.
- `401`: no session / wrong login credentials.
- `403`: blocked write, unapproved file path, or forbidden HTTP profile.
- `200` on `api.php?action=me` after login: capabilities include
  `readOnly: true`, `publicShowcase: true`, `upload: false`, `chmod: false`,
  `trash: false`, `zip: false` and `zipExtract: false`.

## English deployment note

Create the dedicated Linux account, initialize sample data through the CLI
helper, configure an isolated non-root PHP-FPM pool with
`FFM_PUBLIC_SHOWCASE=1`, and serve only the `public/` document root over HTTPS.
No real hosting credentials or filesystem path may be present in this pool.
A public URL alone does not authorize direct access to real files.
