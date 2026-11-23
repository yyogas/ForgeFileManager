"""Optional actual-browser test against a temporary PHP hosting account (never production)."""
from pathlib import Path
from playwright.sync_api import sync_playwright
import os, subprocess, tempfile, socket, time
PROJECT=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='ffm-trash-browser-') as work:
    base=Path(work)
    home=base/'home'; home.mkdir(mode=0o700)
    trash=base/'trash'; trash.mkdir(mode=0o700)
    (home/'restore-me.txt').write_text('safe-content',encoding='utf-8')
    hashed=subprocess.check_output(['php','-r','echo password_hash("browser-strong-password", PASSWORD_DEFAULT);'],text=True).strip()
    with socket.socket() as s:
        s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
    env={**os.environ, 'FFM_USERNAME':'browseruser','FFM_PASSWORD_HASH':hashed,
         'FFM_STORAGE_ROOT':str(home),'FFM_TRASH_ROOT':str(trash),
         'FFM_TEST_SANDBOX':'0','FFM_DEMO_MODE':'0','FFM_ALLOW_HTTP_DEV':'1','FFM_ALLOW_ROOT_DEV':'1'}
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'], cwd=PROJECT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        with sync_playwright() as p:
            browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage'])
            page=browser.new_page(viewport={'width':1300,'height':760})
            errors=[]
            page.on('pageerror',lambda e:errors.append(str(e)))
            for _ in range(50):
                try:
                    page.goto(f'http://127.0.0.1:{port}/',timeout=3500)
                    break
                except Exception:time.sleep(.15)
            page.locator('#login-username').fill('browseruser')
            page.locator('#login-password').fill('browser-strong-password')
            page.locator('#login-form button[type="submit"]').click()
            page.locator('#app:not(.hidden)').wait_for()
            assert page.locator('[data-action="trash-open"]').is_enabled()
            assert page.locator('#file-rows tr').count()==1
            page.locator('#file-rows tr[data-path="restore-me.txt"]').click()
            page.locator('.toolbar [data-action="delete"]').click()
            assert 'corbeille' in page.locator('#modal-title').inner_text().lower() or 'recycle' in page.locator('#modal-title').inner_text().lower()
            page.locator('#modal-actions button').last.click()
            page.wait_for_function("document.querySelectorAll('#file-rows tr').length === 0")
            assert not (home/'restore-me.txt').exists()
            page.locator('[data-action="trash-open"]').click()
            page.locator('.recycle-table tr').first.wait_for()
            assert 'restore-me.txt' in page.locator('.recycle-table').inner_text()
            page.locator('.recycle-table button').first.click()
            page.wait_for_function("document.querySelectorAll('#file-rows tr').length === 1")
            assert (home/'restore-me.txt').read_text()=='safe-content'
            assert not errors,errors
            print('PASS: Chromium live PHP login, delete -> private recycle bin -> restore, no JS errors')
            browser.close()
    finally:
        server.terminate();server.wait(timeout=5)
