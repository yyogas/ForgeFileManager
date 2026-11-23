"""Optional offline visual UI smoke test; no HTTP access required."""
from pathlib import Path
from playwright.sync_api import sync_playwright
import os, re, subprocess, json
root=Path(__file__).resolve().parents[1]
env={**os.environ, 'FFM_TEST_SANDBOX':'1'}
html=subprocess.check_output(['php','-r','include "public/index.php";'],cwd=root,env=env,text=True)
html=re.sub(r'<script src="assets/app.js\?v=[^\"]+" defer></script>','',html)
html=re.sub(r'<link rel="stylesheet" href="assets/app.css\?v=[^\"]+">','',html)
html=html.replace('<head>','<head><base href="https://forge.example.test/ForgeFileManager/public/">')
js=(root/'public/assets/app.js').read_text()
en=json.loads((root/'public/languages/en.json').read_text())
fr=json.loads((root/'public/languages/fr.json').read_text())
mock='''
window.demoLoggedIn = false;
window.fetch = async (uri, opts={}) => {
  const u=new URL(uri.toString(),document.baseURI);
  const action=u.searchParams.get('action');
  if (action === 'login') window.demoLoggedIn = true;
  const payload = u.pathname.endsWith('en.json') ? EN : u.pathname.endsWith('fr.json') ? FR :
   action === 'me' ? (window.demoLoggedIn ? {ok:true,username:'demo',csrf:'abc',demo:true,capabilities:{testSandbox:true,readOnly:false,checksum:true,chmod:true,upload:true,copyDirectories:true,zip:false,trash:true}} : {ok:false,error:'Authentication required'}) :
   action === 'login' ? {ok:true,username:'demo',csrf:'abc'} :
   action === 'list' ? {ok:true,data:{path:u.searchParams.get('path')||'',items:[{name:'public_html',path:'public_html',type:'directory',size:null,modified:1763856000,mode:'755',readable:true,writable:false,extension:''}],page:1,pages:1,pageSize:25,total:1,disk:{free:null,total:null,isAccountQuota:false}}} :
   {ok:false,error:'Unknown test action'};
  return {ok:payload.ok!==false,status:payload.ok===false?401:200, json:async()=>payload};
};
'''
mock=mock.replace('EN',json.dumps(en,ensure_ascii=False)).replace('FR',json.dumps(fr,ensure_ascii=False))
with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage'])
    page=browser.new_page()
    page.set_content(html)
    page.evaluate(mock + "\ntrue;")
    page.add_script_tag(content=js)
    page.locator('#login-view:not(.hidden)').wait_for(timeout=5000)
    assert page.locator('#login-view h1').inner_text()=='Secure sign in', page.locator('#login-view h1').inner_text()
    assert page.locator('#login-username').input_value()=='demo'
    assert page.locator('#login-password').input_value()==''
    page.locator('#login-password').fill('demo123')
    page.locator('#login-form button[type=submit]').click()
    page.locator('#app:not(.hidden)').wait_for(timeout=5000)
    assert page.locator('.sidebar').count()==0
    assert page.locator('.panel-heading h1').count()==0
    assert page.locator('.status-disk').is_visible()
    assert page.locator('#account-name').inner_text()=='demo'
    assert page.locator('#demo-mode-flag').count()==0
    assert page.locator('[data-action="new-folder"]').is_enabled()
    page.locator('#header-language').select_option('fr')
    page.wait_for_function("document.querySelector('.disk-meta strong').textContent==='Espace disque serveur'")
    page.locator('#header-language').select_option('en')
    page.wait_for_function("document.querySelector('.disk-meta strong').textContent==='Server disk usage'")
    print('PASS: Offline Chromium: isolated test credentials, no sidebar, footer disk, English/French and writable test toolbar')
    browser.close()
