'use strict';

(() => {
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  // Every endpoint is relative to the current HTML document, not the domain root.
  // Supports /, /index.php and /ForgeFileManager/public/ installations alike.
  const apiBaseUrl = new URL('api.php', document.baseURI);
  const apiUrl = (action, params = {}) => {
    const url = new URL(apiBaseUrl.href);
    url.search = new URLSearchParams({action, ...params}).toString();
    return url.href;
  };
  const icon = (name) => `<svg class="icon" aria-hidden="true"><use href="#i-${name}"></use></svg>`;
  const escapeHtml = (input) => String(input ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const storage = {
    get(name, fallback) { try { return JSON.parse(localStorage.getItem(name)) ?? fallback; } catch { return fallback; } },
    set(name, val) { try { localStorage.setItem(name, JSON.stringify(val)); } catch {} }
  };
  const PAGE_SIZE = 25;
  const saved = storage.get('ffm_preferences', {});
  const state = {
    username: '', csrf: '', path: '', items: [], selected: new Set(), capabilities: {},
    view: saved.view === 'grid' ? 'grid' : 'list',
    showHidden: !!saved.showHidden, theme: saved.theme === 'dark' ? 'dark' : 'light',
    dense: !!saved.dense, language: ['fr','en'].includes(saved.language) ? saved.language : 'en', search: '', sort: ['name','name-desc','date','size'].includes(saved.sort) ? saved.sort : 'name',
    page: 1, pages: 1, total: 0, disk: null, notices: [], modalCloser: null, busy: false
  };
  // Centralized, versioned English/French catalogs under public/languages/.
  // Source strings are French until the selected catalog is loaded.
  let catalog = {};
  const originalText = new WeakMap();
  const originalAttributes = new WeakMap();
  function t(key, vars = {}) {
    const text = String(catalog[key] ?? key);
    return text.replace(/\{([a-z]+)\}/g, (match, name) => Object.hasOwn(vars, name) ? String(vars[name]) : match);
  }
  function applyTranslations(root = document.body) {
    // Only change UI labels, never user file names or source-code text.
    const skip = '.open-file,.grid-file-name,#breadcrumbs,#folder-tree,.profile-name,.editor-textarea,#file-search,.modal-subtitle,#login-username,#login-password';
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) {
      const node = walker.currentNode;
      if (node.parentElement?.closest(skip)) continue;
      const current = node.nodeValue || '';
      const previous = originalText.get(node);
      const original = previous && previous.displayed === current ? previous.original : current;
      const normalized = original.trim();
      const translated = Object.hasOwn(catalog, normalized) ? t(normalized) : normalized;
      const shown = normalized && translated !== normalized ? original.replace(normalized, translated) : original;
      if (current !== shown) node.nodeValue = shown;
      originalText.set(node, {original, displayed:shown});
    }
    for (const element of root.querySelectorAll('[title],[placeholder],[aria-label]')) {
      if (element.closest(skip) && !element.matches('#file-search')) continue;
      const old = originalAttributes.get(element) || {};
      for (const attr of ['title','placeholder','aria-label']) {
        const current = element.getAttribute(attr);
        if (current === null) continue;
        const stored = old[attr];
        const original = stored && stored.displayed === current ? stored.original : current;
        const displayed = t(original);
        if (displayed !== current) element.setAttribute(attr,displayed);
        old[attr] = {original, displayed};
      }
      originalAttributes.set(element, old);
    }
    document.documentElement.lang = state.language;
    for (const selector of ['#login-language','#header-language','#setting-language']) {
      const select = $(selector);
      if(select) select.value = state.language;
    }
  }
  async function changeLanguage(lang) {
    if (!['en','fr'].includes(lang)) return;
    const response = await fetch(new URL(`languages/${lang}.json`, document.baseURI), {cache:'no-cache'});
    if (!response.ok) throw Error(`Language catalog unavailable: ${lang}`);
    const messages = await response.json();
    if (!messages || typeof messages !== 'object' || Array.isArray(messages)) throw Error('Invalid language catalog');
    catalog = messages;
    state.language = lang;
    preferences();
    applyTranslations();
    if (!$('#app').classList.contains('hidden')) { render(); updateDisk(state.disk); }
    applyTranslations();
  }

  function preferences() {
    storage.set('ffm_preferences', {
      view: state.view, showHidden: state.showHidden, theme: state.theme,
      dense: state.dense, sort: state.sort, language: state.language
    });
    document.documentElement.dataset.theme = state.theme;
    document.documentElement.style.setProperty('--row-height', state.dense ? '43px' : '54px');
    $('#show-hidden').checked = state.showHidden;
    $('#sort-by').value = ['name','name-desc','date','size'].includes(state.sort) ? state.sort : 'name';
    $('#list-view').classList.toggle('selected', state.view === 'list');
    $('#grid-view').classList.toggle('selected', state.view === 'grid');
  }
  preferences();

  function toast(message, error = false) {
    const node = document.createElement('div');
    node.className = `toast ${error ? 'error' : ''}`;
    node.textContent = message;
    $('#toast-stack').appendChild(node);
    state.notices.unshift({message, error, at: new Date().toLocaleTimeString(state.language === 'fr' ? 'fr-FR' : 'en-GB')});
    state.notices = state.notices.slice(0, 12);
    $('#notification-dot').classList.remove('hidden');
    window.setTimeout(() => node.remove(), 5800);
  }

  async function api(action, data, method = 'POST') {
    const endpoint = apiUrl(action);
    const options = {credentials: 'same-origin', cache: 'no-store', method};
    if (method === 'POST') {
      options.headers = {'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf};
      options.body = JSON.stringify(data ?? {});
    }
    const response = await fetch(endpoint, options);
    let payload;
    try { payload = await response.json(); } catch { throw Error('Le serveur a renvoyé une réponse invalide.'); }
    if (!response.ok || !payload.ok) {
      if (response.status === 401 && action !== 'login') showLogin();
      throw Error(payload.error || `Erreur HTTP ${response.status}`);
    }
    return payload;
  }

  async function get(action, params = {}) {
    const response = await fetch(apiUrl(action, params), {credentials: 'same-origin', cache: 'no-store'});
    let payload;
    try { payload = await response.json(); } catch { throw Error('Réponse serveur invalide.'); }
    if (!response.ok || !payload.ok) {
      if (response.status === 401) showLogin();
      throw Error(payload.error || `Erreur HTTP ${response.status}`);
    }
    return payload.data ?? payload;
  }

  function showLogin() {
    listingSequence++; window.clearTimeout(searchTimer);
    $('#app').classList.add('hidden');
    $('#login-view').classList.remove('hidden');
    closeMenu(); closeModal();
    applyTranslations();
  }
  function showApp(payload) {
    state.username = payload.username;
    state.csrf = payload.csrf;
    state.capabilities = payload.capabilities ?? {};
    const readOnly = !!state.capabilities.readOnly;
    for (const button of $$('button[data-action]')) {
      if (['new-folder','new-file','upload'].includes(button.dataset.action)) button.disabled = readOnly;
      if (button.dataset.action === 'trash-open') button.disabled = !state.capabilities.trash;
    }
    $('#account-name').textContent = state.username;
    $('#account-name-menu').textContent = state.username;
    const initial = state.username.charAt(0).toLocaleUpperCase() || 'U';
    $('#account-avatar').textContent = initial;
    $('#account-avatar-menu').textContent = initial;
    $('#login-view').classList.add('hidden');
    $('#app').classList.remove('hidden');
    applyTranslations();
  }

  async function init() {
    try {
      const me = await get('me');
      showApp(me);
      await navigate('');

    } catch (error) {
      showLogin();
      if (!/Authentication required|Session expired/.test(error.message)) $('#login-error').textContent = error.message;
    } finally {
      $('#boot-status')?.remove();
    }
  }

  function prettySize(value) {
    if (value === null || value === undefined) return '—';
    if (value === 0) return '0 o';
    const i = Math.min(4, Math.floor(Math.log(value) / Math.log(1024)));
    return `${(value / 1024 ** i).toLocaleString((state.language === 'fr' ? 'fr-FR' : 'en-GB'),{maximumFractionDigits: i ? 1 : 0})} ${(state.language === 'fr' ? ['o','Ko','Mo','Go','To'] : ['B','KB','MB','GB','TB'])[i]}`;
  }
  function formattedDate(timestamp) {
    return timestamp ? new Date(timestamp * 1000).toLocaleString(state.language === 'fr' ? 'fr-FR' : 'en-GB',{dateStyle:'short',timeStyle:'short'}) : '—';
  }
  function typeIcon(file) {
    if (file.type === 'directory') return ['folder', 'directory'];
    if (file.type === 'symlink') return ['lock', ''];
    if (['php','js','ts','tsx','jsx','html','css','json','xml','sh','py','pl','md','yml','yaml','sql','txt'].includes(file.extension)) return ['code','code'];
    if (['jpg','jpeg','png','webp','gif'].includes(file.extension)) return ['image','image'];
    if (['zip','gz','tar','7z','rar','xz'].includes(file.extension)) return ['archive','archive'];
    return ['file',''];
  }
  function typeLabel(file) {
    if (file.type === 'directory') return t('Dossier');
    if (file.type === 'symlink') return t('Lien bloqué');
    return file.extension ? file.extension.toUpperCase() : t('Fichier');
  }
  function parentDir(path) { return path.split('/').slice(0,-1).join('/'); }
  // Only the current page lives in memory. Sorting/filtering is server-side.
  function currentRows() { return state.items; }
  let listingSequence = 0;
  let searchTimer = null;

  async function loadPage(path, page = 1, clearSearch = false) {
    closeMenu();
    const seq = ++listingSequence;
    const search = clearSearch ? '' : state.search;
    $('#files-container').setAttribute('aria-busy', 'true');
    try {
      const data = await get('list', {
        path, page, search, sort: state.sort, hidden: state.showHidden ? '1' : '0'
      });
      if (seq !== listingSequence) return null; // Ignore stale network responses.
      if (!Array.isArray(data.items) || data.items.length > PAGE_SIZE ||
          !Number.isInteger(data.total) || data.total < 0 ||
          !Number.isInteger(data.page) || !Number.isInteger(data.pages) ||
          data.page < 1 || data.page > data.pages || data.pages < 1 ||
          data.pageSize !== PAGE_SIZE || data.pages !== Math.max(1, Math.ceil(data.total/PAGE_SIZE))) {
        throw Error(t('Réponse de pagination invalide.'));
      }
      state.path = data.path;
      state.items = data.items;
      state.total = data.total;
      state.pages = data.pages;
      state.page = data.page;
      if (clearSearch) {
        state.search = '';
        $('#file-search').value = '';
      }
      state.selected.clear();
      renderBreadcrumbs();
      render();
      state.disk = data.disk;
      updateDisk(state.disk);
      $('#status-path').textContent = '/' + state.path;
      $('#files-container').scrollTop = 0;
      return data;
    } catch (error) {
      if (seq === listingSequence) toast(error.message, true);
      return null;
    } finally {
      if (seq === listingSequence) $('#files-container').removeAttribute('aria-busy');
    }
  }

  async function navigate(path) {
    window.clearTimeout(searchTimer);
    return loadPage(path, 1, true);
  }
  async function refresh() { return loadPage(state.path, state.page); }
  function resetPage() {
    ++listingSequence; // Also invalidates any in-flight page response.
    state.page = 1;
    state.selected.clear();
    $('#files-container').scrollTop = 0;
  }
  function reloadFilters(debounce = false) {
    resetPage();
    window.clearTimeout(searchTimer);
    if (debounce) searchTimer = window.setTimeout(() => void loadPage(state.path, 1), 200);
    else void loadPage(state.path, 1);
  }

  function renderBreadcrumbs() {
    const nav = $('#breadcrumbs'); nav.replaceChildren();
    const parts = state.path ? state.path.split('/') : [];
    let path = '';
    const root = document.createElement('button'); root.textContent = 'home'; root.dataset.nav = '';
    nav.appendChild(root);
    for (const part of parts) {
      const arrow = document.createElement('span'); arrow.innerHTML = icon('right'); nav.appendChild(arrow);
      path += (path ? '/' : '') + part;
      const btn = document.createElement('button'); btn.textContent = part; btn.dataset.nav = path; nav.appendChild(btn);
    }
  }
  function updateDisk(disk) {
    if (!disk || disk.total === null || disk.free === null || disk.total <= 0) {
      $('#disk-progress').style.width = '0%';
      $('#disk-numbers').textContent = t('Indisponible');
      return;
    }
    const used = disk.total - disk.free;
    $('#disk-progress').style.width = `${Math.min(100, Math.max(0, used / disk.total * 100)).toFixed(1)}%`;
    $('#disk-numbers').textContent = `${prettySize(used)} / ${prettySize(disk.total)}`;
  }
  function render() {
    const files = currentRows();
    const pages = state.pages;
    state.page = Math.max(1, Math.min(state.page, pages));
    const rows = $('#file-rows');
    rows.innerHTML = files.map(file => {
      const [symbol,type] = typeIcon(file);
      const marked = state.selected.has(file.path);
      return `<tr data-path="${escapeHtml(file.path)}" class="${marked ? 'selected' : ''}" tabindex="0">
        <td class="check-col"><input class="select-file" type="checkbox" data-path="${escapeHtml(file.path)}" aria-label="${escapeHtml(t('Sélectionner {name}',{name:file.name}))}" ${marked ? 'checked' : ''}></td>
        <td><div class="file-name-cell"><span class="file-icon ${type}">${icon(symbol)}</span><button class="open-file" data-path="${escapeHtml(file.path)}" title="${escapeHtml(file.name)}">${escapeHtml(file.name)}</button></div></td>
        <td class="file-type file-muted">${typeLabel(file)}</td>
        <td class="file-size file-muted">${prettySize(file.size)}</td>
        <td class="file-date file-muted">${formattedDate(file.modified)}</td>
        <td class="file-mode"><span class="mode-chip">${escapeHtml(file.mode)}</span></td>
        <td class="actions-col"><button class="row-menu-btn" data-path="${escapeHtml(file.path)}" title="Autres actions" aria-label="${escapeHtml(t('Actions sur {name}',{name:file.name}))}">${icon('more')}</button></td>
      </tr>`;
    }).join('');
    $('#file-grid').innerHTML = files.map(file => {
      const [symbol,type] = typeIcon(file);
      return `<div class="grid-file ${state.selected.has(file.path)?'selected':''}" data-path="${escapeHtml(file.path)}" tabindex="0">
        <button class="row-menu-btn" data-path="${escapeHtml(file.path)}" title="Actions" aria-label="${escapeHtml(t('Actions sur {name}',{name:file.name}))}">${icon('more')}</button>
        <span class="file-icon ${type}">${icon(symbol)}</span><span class="grid-file-name" title="${escapeHtml(file.name)}">${escapeHtml(file.name)}</span>
        <small>${typeLabel(file)} · ${prettySize(file.size)}</small>
      </div>`;
    }).join('');
    $('#file-table').classList.toggle('hidden', state.view !== 'list');
    $('#file-grid').classList.toggle('hidden', state.view !== 'grid');
    $('#empty-state').classList.toggle('hidden', files.length > 0);
    $('#empty-state strong').textContent = state.search ? t('none') : t('Dossier vide');
    $('#status-count').textContent = t('{count} élément(s) · {selected} sélectionné(s)',{count:state.total,selected:state.selected.size});
    $('#select-all').checked = files.length > 0 && files.every(f => state.selected.has(f.path));
    $('#select-all').indeterminate = files.some(f=>state.selected.has(f.path)) && !$('#select-all').checked;
    $('#selection-info').classList.toggle('hidden',state.selected.size === 0);
    $('#selected-count').textContent = `${state.selected.size} ${t('selected')}`;
    syncActionButtons();
    renderPagination(state.total, pages);
    applyTranslations();
  }


  function renderPagination(total, pages) {
    const first = total ? (state.page - 1) * PAGE_SIZE + 1 : 0;
    const last = Math.min(total, state.page * PAGE_SIZE);
    $('#pagination-summary').textContent = t('Éléments {first}–{last} sur {total}', {first,last,total});
    const target = $('#pagination-buttons');
    target.replaceChildren();
    const button = (label, page, disabled = false, current = false) => {
      const element = document.createElement('button');
      element.className = 'page-button' + (current ? ' active' : '');
      element.type = 'button';
      element.textContent = label;
      element.disabled = disabled;
      element.dataset.page = String(page);
      element.setAttribute('aria-label', label === '‹' ? t('Page précédente') : label === '›' ? t('Page suivante') : current ? t('Page {page} active', {page}) : t('Page {page}', {page}));
      if (current) element.setAttribute('aria-current', 'page');
      target.appendChild(element);
    };
    button('‹', state.page - 1, state.page <= 1);
    const shown = new Set([1, pages, ...Array.from({length: 5}, (_, i) => state.page + i - 2).filter(p => p > 0 && p <= pages)]);
    let previous = 0;
    for (const page of [...shown].sort((a,b) => a-b)) {
      if (page > previous + 1) {
        const gap = document.createElement('span');
        gap.className = 'page-gap'; gap.textContent = '…';
        gap.setAttribute('aria-hidden', 'true');
        target.appendChild(gap);
      }
      button(String(page), page, false, page === state.page);
      previous = page;
    }
    button('›', state.page + 1, state.page >= pages);
    $('#page-jump').classList.toggle('hidden', pages <= 7);
    $('#jump-page').max = String(pages);
    $('#jump-page').value = String(state.page);
  }
  function switchPage(page) {
    if (!Number.isInteger(page) || page < 1 || page > state.pages || page === state.page) return;
    void loadPage(state.path, page);
  }
  function oneSelected() {
    if (state.selected.size !== 1) return null;
    return state.items.find(f => f.path === [...state.selected][0]) || null;
  }
  function syncActionButtons() {
    const file = oneSelected();
    const count = state.selected.size;
    for (const btn of $$('.selection-only')) {
      const action = btn.dataset.action;
      let enabled = count === 1 && !!file && file.type !== 'symlink';
      if (action === 'download') enabled &&= file?.type === 'file';
      if (action === 'copy' && file?.type === 'directory') enabled &&= !!state.capabilities.copyDirectories;
      if (action === 'zip') enabled &&= !!state.capabilities.zip;
      if (action === 'chmod' && !state.capabilities.chmod) enabled = false;
      if (action === 'extract') enabled = false;
      if (action === 'delete') enabled &&= !!state.capabilities.trash;
      btn.disabled = !enabled || !!state.capabilities.readOnly;
    }
  }
  function selectPath(path, additive = false) {
    if (!additive) state.selected.clear();
    if (additive && state.selected.has(path)) state.selected.delete(path);
    else state.selected.add(path);
    render();
  }
  function closeMenu() {
    $('#context-menu').classList.add('hidden');
    $('#profile-dropdown').classList.add('hidden');
    $('#profile-btn').setAttribute('aria-expanded','false');
  }
  function openContext(file, x, y) {
    closeMenu();
    const actions = state.capabilities.readOnly ? (file ? [
      ['open','Ouvrir',file.type==='directory'?'folder-open':'eye'],
      ['properties','Propriétés','info']
    ] : [['refresh','Actualiser','refresh']]) : file ? [
      ['open','Ouvrir',file.type==='directory'?'folder-open':'eye'],
      ...(file.type==='file'?[['edit','Modifier le fichier','edit'],['download','Télécharger','download']]:[]),
      ['rename','Renommer','edit'],
      ...((file.type==='file'||state.capabilities.copyDirectories)?[['copy','Copier','copy']]:[]),
      ['move','Déplacer','move'],['chmod','Permissions CHMOD','shield'],
      ...(state.capabilities.zip?[['zip','Compresser en ZIP','archive']]:[]),
      ['properties','Propriétés','info'], ...(state.capabilities.trash ? [['sep'],['delete','Mettre à la corbeille','trash']] : [])
    ] : [['new-folder','Nouveau dossier','folder'],['new-file','Nouveau fichier','plus'],['upload','Importer des fichiers','upload'],['sep'],['refresh','Actualiser','refresh']];
    const menu = $('#context-menu'); menu.replaceChildren();
    if (file?.type === 'symlink') {
      actions.splice(0,actions.length,['properties','Propriétés du lien (bloqué)','info']);
    }
    for (const [action,label,symbol] of actions) {
      if (action === 'sep') { const hr = document.createElement('div'); hr.className='context-separator'; menu.appendChild(hr); continue; }
      const btn = document.createElement('button');
      btn.innerHTML = `${icon(symbol)}<span>${escapeHtml(label)}</span>`;
      btn.dataset.action = action;
      btn.setAttribute('role','menuitem');
      if (file) btn.dataset.path = file.path;
      if (action === 'delete') btn.classList.add('danger');
      menu.appendChild(btn);
    }
    menu.classList.remove('hidden');
    const rect = menu.getBoundingClientRect();
    menu.style.left = `${Math.max(6,Math.min(x,window.innerWidth-rect.width-8))}px`;
    menu.style.top = `${Math.max(6,Math.min(y,window.innerHeight-rect.height-8))}px`;
    menu.querySelector('button')?.focus({preventScroll:true});
    applyTranslations(menu);
  }

  function requestCloseModal() {
    if (typeof state.modalCloser === 'function') { state.modalCloser(); return; }
    closeModal();
  }
  function closeModal() {
    $('#modal-backdrop').classList.add('hidden');
    $('.modal').classList.remove('wide');
    $('#modal-content').replaceChildren();
    $('#modal-actions').replaceChildren();
    state.modalCloser = null;
  }
  function openModal(title, content, actions = [], options = {}) {
    closeMenu(); closeModal();
    $('#modal-title').textContent = title;
    $('#modal-kicker').textContent = options.kicker || 'FORGE FILE MANAGER';
    $('.modal').classList.toggle('wide', !!options.wide);
    if (typeof content === 'string') $('#modal-content').innerHTML = content;
    else $('#modal-content').appendChild(content);
    for (const a of actions) {
      const btn = document.createElement('button');
      btn.textContent = a.text; btn.className = `btn ${a.className ?? ''}`;
      btn.type = 'button';
      btn.addEventListener('click',a.run);
      $('#modal-actions').appendChild(btn);
    }
    $('#modal-backdrop').classList.remove('hidden');
    applyTranslations($('#modal-backdrop'));
    $('#modal-content').querySelector('input,textarea,select')?.focus();
  }
  function promptValue(title, label, value, callback, info = '') {
    const div = document.createElement('div');
    const text = document.createElement('p'); text.textContent = info; if (info) div.appendChild(text);
    const caption = document.createElement('label'); caption.setAttribute('for','prompt-value'); caption.textContent=label; div.appendChild(caption);
    const input = document.createElement('input'); input.id='prompt-value';input.type='text';input.value=value;input.maxLength=255;div.appendChild(input);
    const submit = async () => {
      const newValue = input.value.trim();
      if (!newValue) { toast('Veuillez saisir une valeur.', true); return; }
      try { await callback(newValue); closeModal(); } catch(err) { toast(err.message, true); }
    };
    input.addEventListener('keydown', e => { if(e.key==='Enter') {e.preventDefault(); void submit();} });
    openModal(title,div,[{text:'Annuler',run:closeModal},{text:'Valider',className:'btn-primary',run:submit}]);
  }
  function askConfirm(title, explanation, onConfirm, destructive=false) {
    const div = document.createElement('p'); div.textContent = explanation;
    const submit = async () => {
      try { await onConfirm(); closeModal(); } catch(err) { toast(err.message,true); }
    };
    openModal(title,div,[{text:'Annuler',run:closeModal},{text:'Confirmer',className:destructive?'btn-danger':'btn-primary',run:submit}]);
  }

  function chmodDialog(file) {
    if (!file || file.type==='symlink') return;
    let digits = file.mode || '644';
    const content = `<p class="modal-subtitle">${escapeHtml(file.path)}</p>
      <div class="mode-display"><strong id="mode-octal">${escapeHtml(digits)}</strong><span id="mode-symbolic"></span></div>
      <table class="perm-table"><thead><tr><th>Permission</th><th>Lecture (4)</th><th>Écriture (2)</th><th>Exécution (1)</th></tr></thead><tbody>
      ${['Propriétaire','Groupe','Autres'].map((name,g)=>`<tr><td>${name}</td>${[4,2,1].map((value,i)=>`<td><input type="checkbox" class="permission-check" data-group="${g}" data-value="${value}" aria-label="${name} ${['Lecture','Écriture','Exécution'][i]}"></td>`).join('')}</tr>`).join('')}
      </tbody></table><div id="mode-warning" class="alert-box hidden"></div>
      <p>Permissions appliquées uniquement à cet élément. Les modifications récursives et ACL seront disponibles après leur audit.</p>`;
    const redraw = () => {
      $('#mode-octal').textContent = digits;
      $('#mode-symbolic').textContent = digits.split('').map(c => ['r','w','x'].map((s,i) => (Number.parseInt(c,8) & [4,2,1][i]) ? s:'-').join('')).join('');
      $$('.permission-check').forEach(box=>{box.checked = !!(Number.parseInt(digits[Number(box.dataset.group)],8)&Number(box.dataset.value));});
      const dangerous = (Number.parseInt(digits[2],8)&2)!==0;
      $('#mode-warning').classList.toggle('hidden',!dangerous);
      $('#mode-warning').textContent = 'Attention : écriture autorisée pour tous. La politique serveur bloque ce réglage par défaut.';
    };
    openModal('Modifier les permissions CHMOD',content,[
      {text:'Annuler',run:closeModal},
      {text:'Appliquer les permissions',className:'btn-primary',run:async()=>{
        try { await api('chmod',{path:file.path,mode:digits}); toast(t('changed')); closeModal(); await navigate(state.path); }
        catch(error){toast(error.message,true);}
      }}
    ],{kicker:'SÉCURITÉ · PERMISSIONS'});
    redraw();
    $$('.permission-check').forEach(box=>box.addEventListener('change',()=>{
      const g=Number(box.dataset.group);
      const d=Number(box.dataset.value);
      const old=Number.parseInt(digits[g],8);
      const next=box.checked?(old|d):(old&~d);
      digits = digits.slice(0,g)+next.toString(8)+digits.slice(g+1);
      redraw();
    }));
  }

  async function editFile(file) {
    if (!file || file.type!=='file') return;
    const data = await get('read',{path:file.path});
    const div = document.createElement('div');
    const subtitle = document.createElement('p'); subtitle.className='modal-subtitle'; subtitle.textContent = `${file.path} · UTF-8 · limite ${prettySize(data.maxBytes)}`;
    div.appendChild(subtitle);
    const editor = document.createElement('textarea'); editor.className='editor-textarea'; editor.spellcheck=false; editor.value=data.content;
    editor.setAttribute('aria-label',t('Contenu du fichier'));
    editor.readOnly = !!state.capabilities.readOnly; div.appendChild(editor);
    let hash=data.hash;
    let dirty=false;
    editor.addEventListener('input',()=>dirty=editor.value!==data.content);
    const submit = async () => {
      try { const result = await api('save',{path:file.path,content:editor.value,hash});hash=result.data.hash;data.content=editor.value;dirty=false;toast(t('saved'));await navigate(state.path); }
      catch(error){toast(error.message,true);}
    };
    editor.addEventListener('keydown',event=>{
      if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='s'){event.preventDefault();void submit();}
    });
    const closeEditor = () => { if(dirty && !window.confirm('Les modifications non enregistrées seront perdues. Continuer ?')) return; closeModal(); };
    const editorActions = [{text:t('Fermer'),run:closeEditor}];
    if (!state.capabilities.readOnly) editorActions.push({text:t('Enregistrer'),className:'btn-primary',run:submit});
    openModal(t('Éditeur de code'),div,editorActions,{wide:true,kicker:t('ÉDITEUR DE FICHIERS')});
    state.modalCloser = closeEditor;
  }
  function properties(file) {
    const div = document.createElement('div');
    const table = [['Nom',file.name],['Chemin',file.path],['Type',typeLabel(file)],['Taille',prettySize(file.size)],['Date de modification',formattedDate(file.modified)],['Permissions',file.mode],['Lisible',file.readable?t('Oui'):t('Non')],['Écriture',file.writable?t('Oui'):t('Non')]];
    div.innerHTML = `<table class="perm-table"><tbody>${table.map(([key,val])=>`<tr><td>${escapeHtml(key)}</td><td>${escapeHtml(val)}</td></tr>`).join('')}</tbody></table>`;
    const actions=[{text:'Fermer',run:closeModal}];
    if(file.type==='file' && state.capabilities.checksum) actions.unshift({text:'Calculer SHA-256',className:'btn-primary',run:async()=>{
      try {
        const result=await get('checksum',{path:file.path});
        const row=document.createElement('tr');
        const label=document.createElement('td'); label.textContent='SHA-256';
        const value=document.createElement('td');
        value.textContent=result.checksum;
        value.style.overflowWrap='anywhere';
        row.append(label,value);
        div.querySelector('tbody')?.append(row);
        $('#modal-actions button')?.setAttribute('disabled','true');
        toast('Empreinte SHA-256 calculée');
      } catch(error) { toast(error.message,true); }
    }});
    openModal('Propriétés',div,actions);
  }
  function settingsDialog() {
    const content = `<div class="settings-group">
      <div class="settings-row"><span>Langue</span><select id="setting-language" aria-label="Langue"><option value="en">English</option><option value="fr">Français</option></select></div>
      <div class="settings-row"><span>Thème de l'interface</span><select id="setting-theme"><option value="light">Clair</option><option value="dark">Sombre</option></select></div>
      <div class="settings-row"><span>Affichage compact</span><input type="checkbox" id="setting-dense" aria-label="Activer l'affichage compact"></div>
      <div class="settings-row"><span>Afficher les fichiers cachés</span><input type="checkbox" id="setting-hidden" aria-label="Afficher les fichiers cachés"></div>
    </div><div class="settings-group"><p>Les préférences sont enregistrées dans ce navigateur. La configuration des permissions et quotas reste côté serveur.</p></div>`;
    openModal('Paramètres et préférences',content,[{text:'Fermer',run:closeModal}],{kicker:'PRÉFÉRENCES UTILISATEUR'});
    $('#setting-language').value=state.language;
    $('#setting-language').addEventListener('change',e=>void changeLanguage(e.target.value).catch(err=>toast(err.message,true)));
    $('#setting-theme').value=state.theme;
    $('#setting-dense').checked=state.dense;
    $('#setting-hidden').checked=state.showHidden;
    $('#setting-theme').addEventListener('change',e=>{state.theme=e.target.value;preferences();});
    $('#setting-dense').addEventListener('change',e=>{state.dense=e.target.checked;preferences();});
    $('#setting-hidden').addEventListener('change',e=>{state.showHidden=e.target.checked;preferences();reloadFilters();});
  }
  async function trashDialog(page = 1) {
    if (!state.capabilities.trash) {
      toast(t('La corbeille privée n’est pas configurée.'), true);
      return;
    }
    let listing;
    try { listing = await get('trash-list', {page}); }
    catch (error) { toast(error.message,true); return; }
    const contents = document.createElement('div');
    contents.className = 'recycle-bin';
    const intro = document.createElement('p');
    intro.className = 'modal-subtitle';
    intro.textContent = t('Les fichiers supprimés sont conservés dans un espace privé. La restauration exige que le dossier parent existe et que le nom soit libre.');
    contents.append(intro);
    const table = document.createElement('table');
    table.className = 'perm-table recycle-table';
    const body = document.createElement('tbody');
    const records = Array.isArray(listing.items) ? listing.items : [];
    if (!records.length) {
      const p = document.createElement('p');
      p.textContent = t('La corbeille est vide.');
      contents.append(p);
    }
    for (const item of records) {
      const tr = document.createElement('tr');
      const name = document.createElement('td');
      name.className = 'trash-filename';
      name.textContent = item.original;
      const details = document.createElement('td');
      details.className = 'trash-date';
      details.textContent = formattedDate(item.deletedAt);
      const controls = document.createElement('td');
      const restore = document.createElement('button');
      restore.type = 'button';
      restore.className = 'btn btn-primary';
      restore.textContent = t('Restaurer');
      restore.addEventListener('click', async () => {
        restore.disabled = true;
        try {
          await api('trash-restore', {id:item.id});
          toast(t('Fichier restauré.'));
          await refresh();
          await trashDialog(listing.page);
        } catch (error) { toast(error.message,true); restore.disabled = false; }
      });
      controls.append(restore);
      tr.append(name, details, controls);
      body.append(tr);
    }
    table.append(body);
    if (records.length) contents.append(table);
    if (listing.pages > 1) {
      const nav = document.createElement('div');
      nav.className = 'trash-page-controls';
      for (const [label, target] of [[t('Page précédente'),listing.page-1],[`${listing.page} / ${listing.pages}`,listing.page],[t('Page suivante'),listing.page+1]]) {
        const button=document.createElement('button'); button.type='button'; button.className='btn';
        button.textContent=label;
        button.disabled=target===listing.page || target<1 || target>listing.pages;
        button.addEventListener('click',()=>void trashDialog(target));
        nav.append(button);
      }
      contents.append(nav);
    }
    openModal(t('Corbeille'),contents,[{text:t('Fermer'),run:closeModal}],{wide:true,kicker:t('RESTAURATION')});
    applyTranslations();
  }

  function profileDialog() {
    const content = `<p><strong>Compte connecté :</strong> ${escapeHtml(state.username)}</p>
      <p><strong>Racine autorisée :</strong> / (racine du compte, non racine Linux)</p>
      <p><strong>Version :</strong> 0.2.5-alpha</p>
      <p>Cette version utilise une authentification autonome. Les profils revendeurs, le SSO et les quotas par compte sont prévus pour une version ultérieure.</p>`;
    openModal('Mon compte',content,[{text:'Fermer',run:closeModal}],{kicker:'COMPTE HÉBERGEMENT'});
  }
  function notifyDialog() {
    const content = state.notices.length ? state.notices.map(n=>`<p><strong>${escapeHtml(n.at)} :</strong> ${escapeHtml(n.message)}</p>`).join('') : '<p>Aucune notification.</p>';
    openModal('Notifications',content,[{text:'Fermer',run:closeModal}]);
    $('#notification-dot').classList.add('hidden');
  }
  async function executeAction(action,path) {
    closeMenu();
    const file = path ? state.items.find(f=>f.path===path) : oneSelected();
    try {
      if (state.capabilities.readOnly && !['open','edit','properties','settings','profile','logout','refresh','trash-open'].includes(action)) {
        throw Error(t('Cet accès aux fichiers d’exemple est en lecture seule.'));
      }
      if (['new-file','new-folder'].includes(action)) {
        const kind = action==='new-file' ? 'file' : 'directory';
        promptValue(kind==='file'?'Créer un fichier':'Créer un dossier','Nom','',async name=>{
          await api('create',{path:state.path,name,kind});toast(t('created'));await refresh();
        });return;
      }
      if (action==='upload') { $('#upload-picker').click();return; }
      if (action==='refresh') {await refresh();return;}
      if (action==='trash-open') {await trashDialog();return;}
      if (action==='settings') {settingsDialog();return;}
      if (action==='profile') {profileDialog();return;}
      if (action==='logout') { await api('logout');showLogin();return; }
      if (!file) return;
      if (action==='open') {
        if(file.type==='directory'){await navigate(file.path);return;}
        if(file.type==='file'&&['png','jpg','jpeg','gif','webp'].includes(file.extension)){
          openModal('Aperçu du fichier',`<p class="modal-subtitle">${escapeHtml(file.name)}</p><img style="max-width:100%;max-height:65vh;object-fit:contain" alt="${escapeHtml(t('Aperçu {name}',{name:file.name}))}" src="${escapeHtml(apiUrl('preview', {path:file.path}))}">`,[{text:'Fermer',run:closeModal}],{wide:true});return;
        }
        await editFile(file);return;
      }
      if (action==='edit'){await editFile(file);return;}
      if (action==='properties'){properties(file);return;}
      if (action==='chmod'){chmodDialog(file);return;}
      if (action==='download') {
        if(file.type!=='file')throw Error('Le téléchargement de dossiers sera ajouté avec les archives multi-fichiers.');
        window.location.assign(apiUrl('download', {path:file.path}));return;
      }
      if (action==='rename'){
        promptValue('Renommer','Nouveau nom',file.name,async name=>{
          await api('rename',{path:file.path,name});toast(t('renamed'));await refresh();
        });return;
      }
      if (action==='copy'||action==='move'){
        if(action==='copy'&&file.type==='directory'&&!state.capabilities.copyDirectories)throw Error('La copie de dossiers est indisponible.');
        promptValue(action==='copy'?'Copier dans un dossier':'Déplacer dans un dossier','Dossier de destination relatif à home (laisser vide pour home)',state.path || '',async directory=>{
          await api(action,{path:file.path,directory:directory==='/'? '':directory});
          toast(action==='copy'?t('copied'):t('moved'));await refresh();
        },'Exemples : public_html, backups ou home (pour la racine, saisir /).');return;
      }
      if (action==='zip'){
        if(!state.capabilities.zip)throw Error('Extension ZIP non disponible sur le serveur.');
        askConfirm('Créer une archive ZIP',t('Créer {file}.zip dans le même dossier ? Une limite de 100 Mo s\'applique.',{file:file.name}),async()=>{
          await api('zip',{path:file.path});toast(t('zipped'));await refresh();
        });return;
      }
      if (action==='delete'){
        if (!state.capabilities.trash) throw Error(t('La corbeille privée n’est pas configurée.'));
        askConfirm('Mettre à la corbeille',t('Déplacer « {file} » dans la corbeille privée ? Vous pourrez restaurer ce fichier ou dossier depuis la barre d’actions.',{file:file.name}),async()=>{
          await api('delete',{path:file.path});toast(t('Élément déplacé dans la corbeille.'));await refresh();
        },true);return;
      }
    }catch(error){toast(error.message,true);}
  }

  $('#login-form').addEventListener('submit',async e=>{
    e.preventDefault();
    $('#login-error').textContent='';
    const username=$('#login-username').value;
    const password=$('#login-password').value;
    try { await api('login',{username,password}); const me = await get('me');showApp(me);$('#login-password').value='';await navigate(''); }
    catch(error){$('#login-error').textContent=error.message;}
  });
  document.addEventListener('click',e=>{
    const actionButton=e.target.closest('[data-action]');
    if(actionButton){ if(!actionButton.disabled)void executeAction(actionButton.dataset.action,actionButton.dataset.path);return; }
    const nav=e.target.closest('[data-nav]');
    if(nav){void navigate(nav.dataset.nav);return;}
    const open=e.target.closest('.open-file');
    if(open){void executeAction('open',open.dataset.path);return;}
    const rowMenu=e.target.closest('.row-menu-btn');
    if(rowMenu){
      const file=state.items.find(f=>f.path===rowMenu.dataset.path);
      if(file){
        const rect = rowMenu.getBoundingClientRect();
        state.selected.clear();state.selected.add(file.path);render();
        openContext(file, rect.left, rect.bottom + 3);
      }
      return;
    }
    if(!e.target.closest('.context-menu,.profile-area')) closeMenu();
    const row=e.target.closest('tr[data-path],.grid-file[data-path]');
    if(row&&!e.target.closest('input,button')) selectPath(row.dataset.path,!!(e.ctrlKey||e.metaKey));
  });
  $('#file-rows').addEventListener('change',e=>{
    if(e.target.classList.contains('select-file')){
      if(e.target.checked) state.selected.add(e.target.dataset.path);
      else state.selected.delete(e.target.dataset.path);
      render();
    }
  });
  $('#file-rows').addEventListener('dblclick',e=>{
    const row=e.target.closest('tr[data-path]');
    if(row)void executeAction('open',row.dataset.path);
  });
  $('#file-grid').addEventListener('dblclick',e=>{
    const item=e.target.closest('.grid-file[data-path]');
    if(item)void executeAction('open',item.dataset.path);
  });
  $('#files-container').addEventListener('contextmenu',e=>{
    e.preventDefault();
    const row=e.target.closest('[data-path]');
    const file=row?state.items.find(f=>f.path===row.dataset.path):null;
    if(file && !state.selected.has(file.path)){state.selected.clear();state.selected.add(file.path);render();}
    openContext(file,e.clientX,e.clientY);
  });
  $('#files-container').addEventListener('click',e=>{
    if(e.target.id==='files-container') {state.selected.clear();render();}
  });
  $('#select-all').addEventListener('change',e=>{
    if(e.target.checked) currentRows().forEach(f=>state.selected.add(f.path));
    else state.selected.clear();
    render();
  });
  $('#clear-selection').addEventListener('click',()=>{state.selected.clear();render();});
  $('#file-search').addEventListener('input',e=>{state.search=e.target.value;reloadFilters(true);});
  $('#sort-by').addEventListener('change',e=>{state.sort=e.target.value;preferences();reloadFilters();});
  $('#show-hidden').addEventListener('change',e=>{state.showHidden=e.target.checked;preferences();reloadFilters();});
  $('#list-view').addEventListener('click',()=>{state.view='list';preferences();render();});
  $('#pagination-buttons').addEventListener('click',e=>{
    const btn=e.target.closest('button[data-page]');
    if (btn && !btn.disabled) switchPage(Number(btn.dataset.page));
  });
  $('#grid-view').addEventListener('click',()=>{state.view='grid';preferences();render();});
  $('#page-jump').addEventListener('submit',e=>{
    e.preventDefault();
    const page = Number($('#jump-page').value);
    if (Number.isInteger(page) && page >= 1 && page <= state.pages) switchPage(page);
    else toast(t('Numéro de page invalide.'), true);
  });
  $('#profile-btn').addEventListener('click',()=>{
    const dropdown=$('#profile-dropdown');const willOpen=dropdown.classList.contains('hidden');
    closeMenu();dropdown.classList.toggle('hidden',!willOpen);
    $('#profile-btn').setAttribute('aria-expanded',String(willOpen));
  });
  $('#settings-btn').addEventListener('click',settingsDialog);
  $('#notifications-btn').addEventListener('click',notifyDialog);
  $('#modal-close').addEventListener('click',requestCloseModal);
  $('#modal-backdrop').addEventListener('click',e=>{if(e.target.id==='modal-backdrop') requestCloseModal();});
  $('#upload-picker').addEventListener('change',async e=>{
    if (state.capabilities.readOnly) { e.target.value = ''; return; }
    for (const file of e.target.files) {
      if (state.capabilities.maxUploadBytes && file.size > state.capabilities.maxUploadBytes) {
        toast(t('{name} : fichier supérieur à la limite de {max}',{name:file.name,max:prettySize(state.capabilities.maxUploadBytes)}),true); continue;
      }
      const form = new FormData();form.append('path',state.path);form.append('file',file);
      try {
        const response=await fetch(apiUrl('upload'),{method:'POST',headers:{'X-CSRF-Token':state.csrf},body:form,credentials:'same-origin'});
        const payload=await response.json();
        if(!response.ok||!payload.ok)throw Error(payload.error||'Import impossible');
        toast(`${t('uploaded')} : ${file.name}`);
      } catch(error){toast(`${file.name} : ${error.message}`,true);}
    }
    e.target.value='';await refresh();
  });
  ['dragenter','dragover'].forEach(name=>$('#files-container').addEventListener(name,e=>{e.preventDefault();e.dataTransfer.dropEffect='copy';}));
  $('#files-container').addEventListener('drop',async e=>{
    e.preventDefault();
    if(state.capabilities.readOnly || !e.dataTransfer?.files?.length)return;
    for(const file of e.dataTransfer.files){
      if (state.capabilities.maxUploadBytes && file.size > state.capabilities.maxUploadBytes) {
        toast(t('{name} : fichier supérieur à la limite de {max}',{name:file.name,max:prettySize(state.capabilities.maxUploadBytes)}),true);continue;
      }
      const form=new FormData();form.append('path',state.path);form.append('file',file);
      try{
        const response=await fetch(apiUrl('upload'),{method:'POST',headers:{'X-CSRF-Token':state.csrf},body:form,credentials:'same-origin'});
        const result=await response.json();if(!response.ok||!result.ok)throw Error(result.error);
        toast(`${t('uploaded')} : ${file.name}`);
      }catch(error){toast(`${file.name} : ${error.message}`,true);}
    }
    await refresh();
  });
  document.addEventListener('keydown',e=>{
    if(!$('#context-menu').classList.contains('hidden') && ['ArrowDown','ArrowUp','Home','End'].includes(e.key)) {
      const buttons=$$('#context-menu button:not(:disabled)');
      if(buttons.length) {
        e.preventDefault();
        const current=buttons.indexOf(document.activeElement);
        const target=e.key==='Home'?0:e.key==='End'?buttons.length-1:
          (current+(e.key==='ArrowDown'?1:-1)+buttons.length)%buttons.length;
        buttons[target].focus();
      }
      return;
    }
    if(e.key==='Escape'){closeMenu();requestCloseModal();return;}
    if($('#app').classList.contains('hidden'))return;
    const editing=e.target.matches('input,textarea,select,[contenteditable]');
    if(editing)return;
    if(e.key==='F2'&&oneSelected()){e.preventDefault();void executeAction('rename');}
    if(e.key==='Delete'&&oneSelected()){e.preventDefault();void executeAction('delete');}
    if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='a'){e.preventDefault();currentRows().forEach(f=>state.selected.add(f.path));render();}
    if((e.shiftKey&&e.key==='F10')||e.key==='ContextMenu'){
      e.preventDefault();openContext(oneSelected(),Math.round(window.innerWidth/2),Math.round(window.innerHeight/2));
    }
  });
  for (const selector of ['#login-language','#header-language']) {
    $(selector).addEventListener('change',e=>void changeLanguage(e.target.value).catch(err=>toast(err.message,true)));
  }
  void changeLanguage(state.language).catch(() => { document.documentElement.lang='fr'; }).then(init);
})();
