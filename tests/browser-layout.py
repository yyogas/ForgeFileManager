"""Offline Chromium smoke: no sidebar, centered toolbar, 25-item paging, search and locale."""
from pathlib import Path
from playwright.sync_api import sync_playwright
import os,re,subprocess,json
r=Path(__file__).resolve().parents[1]
env={**os.environ,'FFM_DEMO_MODE':'1'}
html=subprocess.check_output(['php','-r','include "public/index.php";'],cwd=r,env=env,text=True)
html=re.sub(r'<script src="assets/app.js\?v=[^\"]+" defer></script>','',html)
html=re.sub(r'<link rel="stylesheet" href="assets/app.css\?v=[^\"]+">','',html)
html=html.replace('<head>','<head><base href="https://forge.example.test/ForgeFileManager/public/">')
js=(r/'public/assets/app.js').read_text()
css=(r/'public/assets/app.css').read_text()
en=json.loads((r/'public/languages/en.json').read_text())
fr=json.loads((r/'public/languages/fr.json').read_text())
files=[dict(name=f'file-{i:03d}.txt',path=f'file-{i:03d}.txt',type='file',size=i,modified=1763856000+i,mode='644',readable=True,writable=False,extension='txt') for i in range(1,62)]
mock="""
window.demoLoggedIn = false;
window.demoFiles = FILES;
window.fetch = async (uri,opts={}) => {
 const url = new URL(String(uri), document.baseURI);
 const action = url.searchParams.get('action');
 if (action === 'login') window.demoLoggedIn = true;
 const payload = url.pathname.endsWith('en.json') ? EN : url.pathname.endsWith('fr.json') ? FR :
 action === 'me' ? (window.demoLoggedIn ? {ok:true,username:'demo',csrf:'token',capabilities:{readOnly:true,checksum:true,chmod:false,upload:false,copyDirectories:false,zip:false}} : {ok:false,error:'Authentication required'}) :
 action === 'login' ? {ok:true,username:'demo',csrf:'token'} :
 action === 'list' ? {ok:true,data:(() => {
   window.listRequests = (window.listRequests || []).concat([{page:url.searchParams.get('page'),search:url.searchParams.get('search'),sort:url.searchParams.get('sort')}]);
   const search=(url.searchParams.get('search')||'').toLocaleLowerCase();
   const sort=url.searchParams.get('sort')||'name';
   const hidden=url.searchParams.get('hidden')==='1';
   let rows=window.demoFiles.filter(f=>(hidden || !f.name.startsWith('.')) && f.name.toLocaleLowerCase().includes(search));
   rows.sort((a,b)=>sort==='date' ? b.modified-a.modified : sort==='size' ? b.size-a.size : a.name.localeCompare(b.name,undefined,{numeric:true})*(sort==='name-desc'?-1:1));
   const total=rows.length, pages=Math.max(1,Math.ceil(total/25));
   const page=Math.max(1,Math.min(Number(url.searchParams.get('page')||1),pages));
   return {path:url.searchParams.get('path')||'',items:rows.slice((page-1)*25,page*25),total,pages,page,pageSize:25,hasNext:page<pages,disk:{free:null,total:null,isAccountQuota:false}};
 })()} :
 {ok:false,error:'Unknown mock action'};
 return {ok:payload.ok!==false,status:payload.ok===false?401:200,json:async()=>payload};
};
""".replace('FILES',json.dumps(files)).replace('EN',json.dumps(en,ensure_ascii=False)).replace('FR',json.dumps(fr,ensure_ascii=False))
with sync_playwright() as p:
 browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage'])
 page=browser.new_page(viewport={'width':1440,'height':600})
 errors=[]
 page.on('pageerror',lambda x:errors.append(str(x)))
 page.set_content(html)
 page.add_style_tag(content=css)
 page.evaluate(mock + '\ntrue;')
 page.add_script_tag(content=js)
 page.locator('#login-view:not(.hidden)').wait_for()
 assert 'demo123' in page.locator('.login-credentials').inner_text()
 assert 'Mode démonstration' not in page.locator('body').inner_text()
 page.locator('#login-form button[type=submit]').click()
 page.locator('#app:not(.hidden)').wait_for()
 assert page.locator('.sidebar,#mobile-sidebar,#demo-mode-flag,.panel-heading h1').count()==0
 assert page.locator('#file-rows tr').count()==25
 assert page.locator('#pagination-summary').inner_text()=='Items 1–25 of 61'
 assert page.locator('#disk-numbers').inner_text()=='Unavailable'
 page.screenshot(path='/mnt/data/forge-filemanager-0.2.4-desktop.png',full_page=True)
 assert page.locator('.disk-meta small').inner_text()=='Server volume, not account quota'
 assert page.locator('.file-table').evaluate('(el) => el.closest("#files-container").scrollHeight >= el.closest("#files-container").clientHeight')
 assert page.locator('#files-container').evaluate('(el) => getComputedStyle(el).overflowY')=='scroll'
 toolbar=page.locator('.toolbar-group').bounding_box()
 bar=page.locator('.toolbar').bounding_box()
 assert abs((toolbar['x']+toolbar['width']/2)-(bar['x']+bar['width']/2))<4
 page.get_by_role('button',name='Page 2',exact=True).click()
 assert page.locator('#file-rows tr').count()==25
 assert page.locator('#pagination-summary').inner_text()=='Items 26–50 of 61'
 assert page.evaluate('window.listRequests.at(-1).page')=='2'
 page.get_by_role('button',name='Page 3',exact=True).click()
 assert page.locator('#file-rows tr').count()==11
 assert page.locator('#pagination-summary').inner_text()=='Items 51–61 of 61'
 page.locator('#select-all').check()
 assert page.locator('#selected-count').inner_text().startswith('11 ')
 page.get_by_role('button',name='Page 2',exact=True).click()
 assert '0 ' in page.locator('#selected-count').inner_text()
 page.locator('#file-search').fill('file-003')
 page.wait_for_function("document.querySelector('#pagination-summary').textContent==='Items 1–1 of 1'")
 assert page.evaluate('window.listRequests.at(-1).search')=='file-003'
 assert page.locator('#file-rows tr').count()==1
 assert page.locator('#pagination-summary').inner_text()=='Items 1–1 of 1'
 page.locator('#file-search').fill('')
 page.wait_for_function("document.querySelector('#pagination-summary').textContent==='Items 1–25 of 61'")
 page.locator('#grid-view').click()
 assert page.locator('#file-grid .grid-file').count()==25
 page.locator('#header-language').select_option('fr')
 page.wait_for_function("document.querySelector('#pagination-summary').textContent==='Éléments 1–25 sur 61'")
 assert page.locator('.disk-meta strong').inner_text()=='Espace disque serveur'
 assert page.locator('#disk-numbers').inner_text()=='Indisponible'
 assert page.locator('[data-action="new-folder"]').is_disabled()
 page.set_viewport_size({'width':390,'height':760})
 assert page.locator('#status-disk').count()==0 or page.locator('.status-disk').is_visible()
 assert page.locator('.sidebar').count()==0
 page.screenshot(path='/mnt/data/forge-filemanager-0.2.4-mobile.png',full_page=True)
 # The jump-to-page control appears for larger directories, without loading all rows.
 page.evaluate('''window.demoFiles=Array.from({length:301},(_,i)=>({name:`file-${String(i+1).padStart(4,'0')}.txt`,path:`file-${String(i+1).padStart(4,'0')}.txt`,type:'file',size:i,modified:1763856000+i,mode:'644',readable:true,writable:false,extension:'txt'}));''')
 page.locator('[data-action="refresh"]').click()
 page.wait_for_function("document.querySelector('#pagination-summary').textContent==='Éléments 1–25 sur 301'")
 assert page.locator('#page-jump').is_visible()
 page.locator('#jump-page').fill('10')
 page.locator('#page-jump button[type=submit]').click()
 page.wait_for_function("document.querySelector('#pagination-summary').textContent==='Éléments 226–250 sur 301'")
 assert page.evaluate('window.listRequests.at(-1).page')=='10'
 page.locator('#sort-by').select_option('name-desc')
 page.wait_for_function("document.querySelector('#pagination-summary').textContent==='Éléments 1–25 sur 301'")
 assert page.evaluate('window.listRequests.at(-1).sort')=='name-desc'
 assert page.locator('#file-grid .grid-file-name').first.inner_text()=='file-0301.txt'
 assert not errors,errors
 print('PASS: Chromium server-backed paging, search, sorting, 301-item jump-to-page, selection, grid, footer, i18n, toolbar')
 browser.close()
