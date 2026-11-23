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
window.fetch = async (uri,opts={}) => {
 const url = new URL(String(uri), document.baseURI);
 const action = url.searchParams.get('action');
 if (action === 'login') window.demoLoggedIn = true;
 const payload = url.pathname.endsWith('en.json') ? EN : url.pathname.endsWith('fr.json') ? FR :
 action === 'me' ? (window.demoLoggedIn ? {ok:true,username:'demo',csrf:'token',capabilities:{readOnly:true,checksum:true,chmod:false,upload:false,copyDirectories:false,zip:false}} : {ok:false,error:'Authentication required'}) :
 action === 'login' ? {ok:true,username:'demo',csrf:'token'} :
 action === 'list' ? {ok:true,data:{path:url.searchParams.get('path')||'',items:FILES,truncated:false,maxEntries:2000,disk:{free:null,total:null,isAccountQuota:false}}} :
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
 page.screenshot(path='/mnt/data/forge-filemanager-0.2.3-desktop.png',full_page=True)
 assert page.locator('.disk-meta small').inner_text()=='Server volume, not account quota'
 assert page.locator('.file-table').evaluate('(el) => el.closest("#files-container").scrollHeight >= el.closest("#files-container").clientHeight')
 assert page.locator('#files-container').evaluate('(el) => getComputedStyle(el).overflowY')=='scroll'
 toolbar=page.locator('.toolbar-group').bounding_box()
 bar=page.locator('.toolbar').bounding_box()
 assert abs((toolbar['x']+toolbar['width']/2)-(bar['x']+bar['width']/2))<4
 page.get_by_role('button',name='Page 2',exact=True).click()
 assert page.locator('#file-rows tr').count()==25
 assert page.locator('#pagination-summary').inner_text()=='Items 26–50 of 61'
 page.get_by_role('button',name='Page 3',exact=True).click()
 assert page.locator('#file-rows tr').count()==11
 assert page.locator('#pagination-summary').inner_text()=='Items 51–61 of 61'
 page.locator('#select-all').check()
 assert page.locator('#selected-count').inner_text().startswith('11 ')
 page.get_by_role('button',name='Page 2',exact=True).click()
 assert '0 ' in page.locator('#selected-count').inner_text()
 page.locator('#file-search').fill('file-003')
 assert page.locator('#file-rows tr').count()==1
 assert page.locator('#pagination-summary').inner_text()=='Items 1–1 of 1'
 page.locator('#file-search').fill('')
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
 page.screenshot(path='/mnt/data/forge-filemanager-0.2.3-mobile.png',full_page=True)
 assert not errors,errors
 print('PASS: Chromium desktop 25/page, 61 items, paging, visible-page selection, filtering, grid, footer, i18n, safe sample credentials, centered toolbar')
 browser.close()
