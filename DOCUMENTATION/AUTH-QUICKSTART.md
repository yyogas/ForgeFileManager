# Forge File Manager — Access and authentication / Accès et authentification

> Updated: 2026-11-23. Applies to v0.2.7-alpha and later until the authentication contract changes.

## Français — Pourquoi « Forge File Manager is not configured » ?

Ce message est un **HTTP 503** renvoyé avant la connexion : le processus PHP-FPM ne voit pas `FFM_USERNAME`, `FFM_PASSWORD_HASH` ou `FFM_STORAGE_ROOT`, ou le dossier indiqué n'existe pas. Un fichier `.env` n'est **pas** chargé automatiquement. Modifiez le pool PHP-FPM dédié ou injectez les paramètres depuis le gestionnaire de secrets.

**Les identifiants publics `demo / demo123` ne donnent jamais accès aux fichiers d'hébergement.** Le mode virtuel a été retiré à la demande du projet. Les identifiants ne fonctionnent que dans un bac à sable de développement explicitement activé et limité à loopback. Évitez de publier des fichiers de configuration ou le répertoire `src/` sur Internet.

### A. Accès à un environnement HTTPS réel (recommandé pour test.wcp360.com)

1. Sur le serveur SSH, choisissez un dossier de test **privé**, hors de tout DocumentRoot, existant, non-symlink, appartenant au même UID Unix que le processus PHP-FPM dédié. Par exemple `/srv/forge-filemanager-test-data`, créé avec permissions restrictives. Ne choisissez jamais `/`, `/home`, `/srv`, `/tmp` ou un répertoire multi-utilisateur comme racine.
2. Générez un hash pour un mot de passe **privé et fort (12 caractères minimum)** :
   ```bash
   cd /chemin/vers/ForgeFileManager
   php bin/hash-password.php
   ```
   Le programme existant affiche la saisie à l'écran. Préservez la confidentialité de votre terminal. Ne placez jamais le mot de passe en clair dans une commande shell ou dans Git.
3. Dans **le pool PHP-FPM dédié** au site (pas le fichier `.env.example`), configurez les variables :
   ```ini
   env[FFM_USERNAME] = forge_test
   env[FFM_PASSWORD_HASH] = HASH_GENERE_PAR_LA_COMMANDE
   env[FFM_STORAGE_ROOT] = /srv/forge-filemanager-test-data
   ; Optionnel : verrouiller l'UID de ce pool
   ; env[FFM_EXPECTED_UID] = UID_DU_POOL
   ```
   Remplacez les trois valeurs d'exemple. Selon le gestionnaire de PHP-FPM, vérifiez l'échappement des caractères `$` du hash et leur transmission exacte ; utilisez des secrets de déploiement plutôt qu'un fichier web-accessible. PHP-FPM nettoie par défaut les variables héritées (`clear_env=yes`), donc un `export` dans votre terminal SSH ne suffit **pas** à configurer le site web.
4. Testez la configuration et rechargez **la version de PHP-FPM effectivement utilisée** :
   ```bash
   sudo php-fpm8.3 -t
   sudo systemctl reload php8.3-fpm
   ```
   Les commandes ci-dessus sont des exemples pour PHP 8.3. Vérifiez le service installé. Un changement dans un pool partagé peut affecter d'autres sites.
5. Ouvrez `https://test.wcp360.com/ForgeFileManager/public/api.php?action=me` sans session : **HTTP 401 signifie que la configuration a été acceptée** et que la connexion est nécessaire. HTTP 503 signifie un problème de configuration ou d'isolation qui reste à résoudre. Puis connectez-vous avec l'identifiant et le nouveau mot de passe privés.

Le programme refuse expressément la combinaison `demo / demo123` sur un compte réel, même si vous en configurez le hash.

### B. Tester demo / demo123 sans exposer le serveur (localhost uniquement)

Sous un **utilisateur SSH non-root**, depuis le projet :
```bash
mkdir -p "$HOME/ffm-private-sandbox" "$HOME/ffm-private-trash"
chmod 700 "$HOME/ffm-private-sandbox" "$HOME/ffm-private-trash"
cd /chemin/vers/ForgeFileManager
env -u FFM_USERNAME -u FFM_PASSWORD_HASH -u FFM_STORAGE_ROOT -u FFM_TRASH_ROOT \
  FFM_TEST_SANDBOX=1 FFM_ALLOW_HTTP_DEV=1 \
  FFM_TEST_STORAGE_ROOT="$HOME/ffm-private-sandbox" \
  FFM_TEST_TRASH_ROOT="$HOME/ffm-private-trash" \
  php -S 127.0.0.1:8080 -t public public/router.php
```
Dans un autre terminal sur votre PC, créez un tunnel SSH (remplacez l'utilisateur et le serveur) :
```bash
ssh -L 8080:127.0.0.1:8080 utilisateur@serveur-ssh
```
Visitez **http://127.0.0.1:8080** sur votre PC et connectez-vous avec `demo / demo123`. Ce mode utilise **uniquement les dossiers privés de test** et n'est pas destiné à un vhost Internet.

### Diagnostics supplémentaires

- `Invalid credentials` (HTTP 401 après formulaire) : identifiant ou hash non conforme.
- `Forge File Manager is not configured` (HTTP 503) : variables manquantes ou répertoire inexistant.
- `Storage root ownership does not match the PHP worker UID` : mauvais propriétaire Linux ou mauvais pool.
- `Refusing to manage hosting files as root` : le processus web tourne en root, ce qui est interdit.
- Le navigateur ne peut pas exécuter `bin/hash-password.php` : il est volontairement réservé au CLI.
- Ne publiez pas `/ForgeFileManager/bin/`, `/ForgeFileManager/src/`, `.env`, ni `DOCUMENTATION/`. Exposez **uniquement `public/`** via le serveur web.
- Ne mettez jamais `FFM_ALLOW_ROOT_DEV=1`, `FFM_ALLOW_HTTP_DEV=1` ou `FFM_TEST_SANDBOX=1` dans un pool public.

## English — Short version

The HTTP 503 message is expected when the dedicated PHP-FPM process does not receive the required `FFM_USERNAME`, `FFM_PASSWORD_HASH`, and `FFM_STORAGE_ROOT` settings. The `.env.example` file is documentation, not an automatically loaded configuration.

Known `demo / demo123` credentials **cannot** authenticate to real hosting files. Use a private user/password hash and a restricted, account-owned directory on the HTTPS test site. For demo credentials use only the explicit loopback test sandbox documented above. The site deployment, PHP-FPM environment and OS ownership must be configured on the hosting server; a GitHub code update does not change those settings.

See also [DEPLOYMENT.md](DEPLOYMENT.md) and [SECURITY.md](SECURITY.md).
