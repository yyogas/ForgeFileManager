'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const base = path.resolve(__dirname, '..');
const file = (rel) => fs.readFileSync(path.join(base,rel),'utf8');
const html = file('public/index.php');
const js = file('public/assets/app.js');
const css = file('public/assets/app.css');
assert(!html.includes('id="sidebar"'), 'Sidebar was not removed');
assert(!html.includes('id="mobile-sidebar"'), 'Mobile sidebar toggle was not removed');
assert(!html.includes('id="demo-mode-flag"'), 'Demo banner still appears');
assert(!html.includes('class="panel-heading"'), 'Redundant file explorer heading still appears');
assert(html.includes('id="pagination-buttons"'));
assert(html.includes('id="page-jump"'));
assert(html.includes('id="jump-page"'));
assert(html.includes('class="status-disk"'));
assert(html.includes('id="disk-numbers"'));
assert(html.includes('demo123'), 'Sample credentials missing from unconfigured sign-in');
assert(js.includes('const PAGE_SIZE = 25;'));
assert(js.includes('currentRows().forEach(f=>state.selected.add(f.path))'), 'Page-only selection missing');
assert(js.includes("const data = await get('list',"), 'Server-backed paginated directory requests missing');
assert(js.includes('state.total = data.total'), 'Server pagination total missing');
assert(js.includes('state.pages = data.pages'), 'Server pagination page count missing');
assert(js.includes('state.selected.clear();'), 'Selection should reset on page change');
assert(css.includes('overflow-y: scroll'), 'Vertical scrolling should be available');
for (const lang of ['en','fr']) {
 const catalog=JSON.parse(file(`public/languages/${lang}.json`));
 for (const key of ['Éléments {first}–{last} sur {total}','Page précédente','Page suivante','Espace disque serveur','Indisponible'])
   assert(Object.hasOwn(catalog,key), `${lang} lacks key ${key}`);
}
console.log('PASS: UI layout structure, 25-item paging and language keys');
