<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/DeploymentMode.php';
$testSandbox = \ForgeFileManager\DeploymentMode::sandboxEnabled();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; connect-src \'self\'; object-src \'none\'; frame-ancestors \'none\'; base-uri \'none\'; form-action \'self\'');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#ffffff">
  <title>Forge File Manager</title>
  <link rel="stylesheet" href="assets/app.css?v=0.2.5">
  <script src="assets/app.js?v=0.2.5" defer></script>
</head>
<body>
<svg class="svg-icons" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
  <symbol id="i-folder" viewBox="0 0 24 24"><path d="M3 7V5a2 2 0 0 1 2-2h5l2 3h7a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></symbol>
  <symbol id="i-folder-open" viewBox="0 0 24 24"><path d="M3 8V5a2 2 0 0 1 2-2h5l2 3h7a2 2 0 0 1 2 2v2M3 10h18l-3 10H6z"/></symbol>
  <symbol id="i-file" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></symbol>
  <symbol id="i-code" viewBox="0 0 24 24"><path d="m8 8-4 4 4 4m8-8 4 4-4 4M14 4l-4 16"/></symbol>
  <symbol id="i-image" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1"/><path d="m21 15-5-5L5 21"/></symbol>
  <symbol id="i-archive" viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M4 9h16m-8-6v12m-3-6h6m-5 8h4"/></symbol>
  <symbol id="i-chevron" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></symbol>
  <symbol id="i-right" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></symbol>
  <symbol id="i-left" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></symbol>
  <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></symbol>
  <symbol id="i-settings" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06-2 2-.06-.06A1.65 1.65 0 0 0 16 18.5l-.15.06-.5 2.44h-3l-.5-2.44-.15-.06a1.65 1.65 0 0 0-1.8.33l-.06.06-2-2 .06-.06a1.65 1.65 0 0 0 .33-1.82l-.06-.15L5.7 15v-3l2.43-.5.06-.15a1.65 1.65 0 0 0-.33-1.8L7.8 9.5l2-2 .06.06a1.65 1.65 0 0 0 1.8.33l.15-.06.5-2.43h3l.5 2.43.15.06a1.65 1.65 0 0 0 1.8-.33l.06-.06 2 2-.06.06a1.65 1.65 0 0 0-.33 1.8l.06.15 2.43.5v3l-2.43.5z" transform="translate(-1.5 -1.5) scale(1.05)"/></symbol>
  <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/></symbol>
  <symbol id="i-bell" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 8-3 8-3 10h18c0-2-3-2-3-10M10 21h4"/></symbol>
  <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
  <symbol id="i-upload" viewBox="0 0 24 24"><path d="M12 17V3m-5 5 5-5 5 5M4 16v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4"/></symbol>
  <symbol id="i-download" viewBox="0 0 24 24"><path d="M12 3v14m-5-5 5 5 5-5M4 19v2h16v-2"/></symbol>
  <symbol id="i-copy" viewBox="0 0 24 24"><rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/></symbol>
  <symbol id="i-move" viewBox="0 0 24 24"><path d="M12 3v18M3 12h18m-12-6 3-3 3 3m-6 12 3 3 3-3M6 9l-3 3 3 3m12-6 3 3-3 3"/></symbol>
  <symbol id="i-edit" viewBox="0 0 24 24"><path d="m4 20 4.5-1 11-11-3.5-3.5-11 11zM14 6l3.5 3.5M4 20h16"/></symbol>
  <symbol id="i-shield" viewBox="0 0 24 24"><path d="m12 2 9 4v6c0 6-4 9-9 10-5-1-9-4-9-10V6z"/><path d="m8 12 2.5 2.5L16 9"/></symbol>
  <symbol id="i-trash" viewBox="0 0 24 24"><path d="M4 7h16M9 7V4h6v3M6 7l1 14h10l1-14M10 11v6m4-6v6"/></symbol>
  <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M21 7v6h-6M3 17v-6h6"/><path d="M5 10a8 8 0 0 1 14-3l2 2M3 15l2 2a8 8 0 0 0 14-3"/></symbol>
  <symbol id="i-more" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></symbol>
  <symbol id="i-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></symbol>
  <symbol id="i-list" viewBox="0 0 24 24"><path d="M9 6h12M9 12h12M9 18h12M4 6h.01M4 12h.01M4 18h.01"/></symbol>
  <symbol id="i-home" viewBox="0 0 24 24"><path d="m3 11 9-8 9 8v10h-7v-7h-4v7H3z"/></symbol>
  <symbol id="i-hard-drive" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 13h18M7 17h.01M11 17h.01"/></symbol>
  <symbol id="i-eye" viewBox="0 0 24 24"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></symbol>
  <symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 11v6m0-10v1"/></symbol>
  <symbol id="i-x" viewBox="0 0 24 24"><path d="M5 5l14 14M19 5 5 19"/></symbol>
  <symbol id="i-check" viewBox="0 0 24 24"><path d="m4 12 5 5L20 6"/></symbol>
  <symbol id="i-log-out" viewBox="0 0 24 24"><path d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4m4-4 4-4-4-4m4 4H9"/></symbol>
  <symbol id="i-moon" viewBox="0 0 24 24"><path d="M20 15a8 8 0 0 1-11-11A9 9 0 1 0 20 15z"/></symbol>
  <symbol id="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/></symbol>
  <symbol id="i-lock" viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="12" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></symbol>
  <symbol id="i-save" viewBox="0 0 24 24"><path d="M4 3h14l3 3v15H3V3zM7 3v7h10V3M7 21v-8h10v8"/></symbol>
  <symbol id="i-alert" viewBox="0 0 24 24"><path d="M12 3 2 21h20zM12 9v5m0 3v1"/></symbol>
</svg>
<div id="boot-status" class="boot-status" role="status">
  <strong>Forge File Manager</strong>
  <p>Chargement de l’interface…</p>
  <small>Si ce message reste affiché, vérifiez le chargement des fichiers CSS et JavaScript depuis ce répertoire.</small>
</div>
<noscript>JavaScript est nécessaire pour accéder à Forge File Manager. Activez-le dans votre navigateur.</noscript>
<div id="login-view" class="login-view hidden">
  <div class="login-box">
    <div class="brand brand-login"><span class="brand-icon"><svg class="icon"><use href="#i-folder-open"/></svg></span><span>Forge <strong>File Manager</strong></span></div>
    <h1>Connexion sécurisée</h1>
    <p>Accédez aux fichiers autorisés de votre compte d'hébergement.</p>
    <label class="language-choice"><span>Langue</span><select id="login-language" aria-label="Langue"><option value="en">English</option><option value="fr">Français</option></select></label>
    
    <div class="login-credentials"><strong>Identifiants de connexion</strong><p><span>Utilisateur :</span> <code>demo</code> <span>Mot de passe :</span> <code>demo123</code></p><small>Identifiants réservés aux essais locaux isolés. Inactifs sur un hébergement réel.</small></div>
    <form id="login-form">
      <label for="login-username">Identifiant</label><input id="login-username" name="username" autocomplete="username" <?php if ($testSandbox): ?>value="demo"<?php endif; ?> required>
      <label for="login-password">Mot de passe</label><input type="password" id="login-password" name="password" autocomplete="current-password"  required>
      <button class="btn btn-primary btn-full" type="submit">Se connecter</button>
      <div id="login-error" class="form-error" role="alert"></div>
    </form>
    <small>Authentification obligatoire pour un compte réel. Aucun mot de passe public ne donne accès à ses fichiers.</small>
  </div>
</div>
<div id="app" class="app hidden">
  <header class="topbar">
    <div class="brand"><span class="brand-icon"><svg class="icon"><use href="#i-folder-open"/></svg></span><span class="brand-name">Forge <strong>File Manager</strong></span><span class="version-tag">0.2.6 ALPHA</span></div>
    <div class="topbar-right">
      <label class="language-choice header-language"><span class="sr-only">Langue</span><select id="header-language" aria-label="Langue"><option value="en">English</option><option value="fr">Français</option></select></label>
      <span class="topbar-label">Gestionnaire de fichiers</span>
      <button class="icon-button" id="notifications-btn" aria-label="Notifications" title="Notifications"><svg class="icon"><use href="#i-bell"/></svg><span id="notification-dot" class="notification-dot hidden"></span></button>
      <button class="icon-button" id="settings-btn" aria-label="Paramètres" title="Paramètres"><svg class="icon"><use href="#i-settings"/></svg></button>
      <div class="profile-area">
        <button class="profile-button" id="profile-btn" aria-haspopup="true" aria-expanded="false"><span class="avatar" id="account-avatar">U</span><span class="profile-name" id="account-name">Utilisateur</span><svg class="icon icon-small"><use href="#i-chevron"/></svg></button>
        <div class="profile-dropdown hidden" id="profile-dropdown" role="menu">
          <div class="profile-dropdown-header"><span class="avatar avatar-lg" id="account-avatar-menu">U</span><div><strong id="account-name-menu">Utilisateur</strong><small>Compte d'hébergement</small></div></div>
          <button data-action="settings" role="menuitem"><svg class="icon"><use href="#i-settings"/></svg> Préférences</button>
          <button data-action="profile" role="menuitem"><svg class="icon"><use href="#i-user"/></svg> Mon compte</button>
          <button data-action="logout" class="danger" role="menuitem"><svg class="icon"><use href="#i-log-out"/></svg> Se déconnecter</button>
        </div>
      </div>
    </div>
  </header>
  <section class="toolbar" role="toolbar" aria-label="Actions sur les fichiers">
    <div class="toolbar-group">
      <button class="tool" data-action="new-folder" title="Créer un dossier"><svg class="icon"><use href="#i-folder"/></svg> <span>Dossier</span></button>
      <button class="tool" data-action="new-file" title="Créer un fichier"><svg class="icon"><use href="#i-plus"/></svg> <span>Fichier</span></button>
      <span class="tool-divider"></span>
      <button class="tool tool-accent" data-action="upload" title="Importer des fichiers"><svg class="icon"><use href="#i-upload"/></svg> <span>Importer</span></button>
      <button class="tool selection-only" data-action="download" title="Télécharger un fichier"><svg class="icon"><use href="#i-download"/></svg> <span>Télécharger</span></button>
      <span class="tool-divider"></span>
      <button class="tool selection-only" data-action="copy" title="Copier"><svg class="icon"><use href="#i-copy"/></svg> <span>Copier</span></button>
      <button class="tool selection-only" data-action="move" title="Déplacer"><svg class="icon"><use href="#i-move"/></svg> <span>Déplacer</span></button>
      <button class="tool selection-only toolbar-extra" data-action="rename" title="Renommer"><svg class="icon"><use href="#i-edit"/></svg> <span>Renommer</span></button>
      <button class="tool selection-only" data-action="chmod" title="Modifier les permissions CHMOD"><svg class="icon"><use href="#i-shield"/></svg> <span>CHMOD</span></button>
      <button class="tool selection-only toolbar-extra" data-action="zip" title="Compresser en ZIP"><svg class="icon"><use href="#i-archive"/></svg> <span>ZIP</span></button>
      <button class="tool selection-only toolbar-extra" data-action="extract" title="Extraire le ZIP dans un nouveau dossier"><svg class="icon"><use href="#i-archive"/></svg> <span>Extraire</span></button>
      <button class="tool selection-only danger toolbar-extra" data-action="delete" title="Déplacer dans la corbeille"><svg class="icon"><use href="#i-trash"/></svg> <span>Supprimer</span></button>
      <span class="tool-divider"></span>
      <button class="tool" data-action="trash-open" title="Ouvrir la corbeille"><svg class="icon"><use href="#i-trash"/></svg><span>Corbeille</span></button>
      <button class="tool" data-action="refresh" title="Actualiser"><svg class="icon"><use href="#i-refresh"/></svg><span class="hide-sm">Actualiser</span></button>
    </div>
  </section>
  <div class="workspace">
    <main class="main-panel">
      <div class="location-bar">
        <nav id="breadcrumbs" class="breadcrumbs" aria-label="Emplacement actuel"></nav>
        <div class="panel-controls"><button class="view-button selected" id="list-view" title="Vue liste" aria-label="Vue liste"><svg class="icon"><use href="#i-list"/></svg></button><button class="view-button" id="grid-view" title="Vue grille" aria-label="Vue grille"><svg class="icon"><use href="#i-grid"/></svg></button></div>
      </div>
      <div class="list-tools">
        <div class="search-field"><svg class="icon"><use href="#i-search"/></svg><input type="search" id="file-search" maxlength="100" placeholder="Rechercher dans ce dossier…" aria-label="Filtrer les fichiers du dossier"></div>
        <label class="hidden-switch" title="Afficher les fichiers cachés"><input type="checkbox" id="show-hidden"><span>Fichiers cachés</span></label>
        <select id="sort-by" aria-label="Trier les fichiers"><option value="name">Nom A-Z</option><option value="name-desc">Nom Z-A</option><option value="date">Plus récents</option><option value="size">Taille</option></select>
      </div>
      <div class="selection-info hidden" id="selection-info"><span id="selected-count">0 sélectionné</span><button class="link-button" id="clear-selection">Annuler la sélection</button></div>
      <div class="files-container" id="files-container">
        <table class="file-table" id="file-table"><thead><tr><th class="check-col"><input id="select-all" type="checkbox" aria-label="Sélectionner tous les fichiers visibles"></th><th>Nom</th><th class="file-type">Type</th><th class="file-size">Taille</th><th class="file-date">Modification</th><th class="file-mode">CHMOD</th><th class="actions-col">Actions</th></tr></thead><tbody id="file-rows"></tbody></table>
        <div class="file-grid hidden" id="file-grid"></div>
        <div id="empty-state" class="empty-state hidden"><svg class="icon"><use href="#i-folder-open"/></svg><strong>Dossier vide</strong><span>Créez un fichier, un dossier ou importez vos documents.</span></div>
      </div>
      <nav id="pagination" class="pagination" aria-label="Pagination des fichiers">
        <span class="pagination-summary" id="pagination-summary" aria-live="polite">0 élément</span>
        <div class="pagination-controls">
          <div id="pagination-buttons" class="pagination-buttons"></div>
          <form id="page-jump" class="page-jump hidden" aria-label="Aller à une page">
            <label for="jump-page">Page</label>
            <input id="jump-page" type="number" min="1" step="1" value="1" aria-label="Numéro de page" required>
            <button class="page-button" type="submit">Aller</button>
          </form>
        </div>
      </nav>
    </main>
  </div>
  <footer class="statusbar">
    <div class="status-files"><span id="status-path">/</span><span id="status-count">0 élément</span></div>
    <div class="status-disk"><svg class="icon" aria-hidden="true"><use href="#i-hard-drive"/></svg><div class="disk-meta"><strong>Espace disque serveur</strong><span id="disk-numbers">Indisponible</span><small>Volume du serveur, pas quota du compte</small></div><div class="storage-meter" aria-hidden="true"><span id="disk-progress"></span></div></div>
    <span class="status-right"><span class="status-dot"></span><span id="status-service">Connecté</span></span>
  </footer>
</div>
<input type="file" id="upload-picker" multiple class="hidden">
<div class="context-menu hidden" id="context-menu" role="menu"></div>
<div class="toast-stack" id="toast-stack" aria-live="polite" aria-atomic="false"></div>
<div class="modal-backdrop hidden" id="modal-backdrop">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="modal-header"><div><span class="modal-kicker" id="modal-kicker">FORGE FILE MANAGER</span><h2 id="modal-title">Fenêtre</h2></div><button class="icon-button" id="modal-close" aria-label="Fermer"><svg class="icon"><use href="#i-x"/></svg></button></div>
    <div class="modal-content" id="modal-content"></div>
    <div class="modal-actions" id="modal-actions"></div>
  </div>
</div>
</body>
</html>
