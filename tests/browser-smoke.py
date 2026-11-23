#!/usr/bin/env python3
"""Optional local browser integration smoke test; needs Playwright + Chromium."""
import os
import socket
import subprocess
import tempfile
import time
from pathlib import Path

from playwright.sync_api import sync_playwright

PROJECT = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='ffm-browser-') as tmp:
    root = Path(tmp) / 'hosting'
    (root/'public_html').mkdir(parents=True)
    (root/'public_html'/'index.php').write_text('<?php echo "Hello Forge";')
    (root/'public_html'/'style.css').write_text('body { color: #222; }')
    (root/'logs').mkdir()
    (root/'.hidden').write_text('internal')
    with socket.socket() as s:
        s.bind(('127.0.0.1',0))
        port=s.getsockname()[1]
    hashpw=subprocess.check_output(['php','-r','echo password_hash("fixture-secret-pass",PASSWORD_DEFAULT);'],text=True).strip()
    env={**os.environ,'FFM_USERNAME':'host_account', 'FFM_PASSWORD_HASH':hashpw,
         'FFM_STORAGE_ROOT':str(root),'FFM_ALLOW_HTTP_DEV':'1','FFM_ALLOW_ROOT_DEV':'1'}
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=PROJECT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        with sync_playwright() as p:
            browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
            page=browser.new_page(viewport={'width':1440,'height':900},device_scale_factor=1)
            page.set_default_timeout(4000)
            print('browser ready',flush=True)
            console_errors=[]
            page.on('pageerror', lambda e: console_errors.append(str(e)))
            for _ in range(35):
                try:
                    page.goto(f'http://127.0.0.1:{port}/',timeout=4000)
                    break
                except Exception:
                    time.sleep(.1)
            print('page loaded',page.url, page.title(),page.locator('body').inner_text()[:400],flush=True)
            page.locator('#login-username').fill('host_account')
            page.locator('#login-password').fill('fixture-secret-pass')
            page.locator('#login-form button').click()
            print('login clicked',flush=True)
            page.locator('#account-name').get_by_text('host_account').wait_for()
            assert page.locator('#file-rows tr').count() == 3
            print('PASS: login, account header, initial file list')
            page.screenshot(path=str(PROJECT/'preview.png'),full_page=True)
            # Sidebar navigation and context menu
            page.locator('[data-nav="public_html"]').first.click()
            page.get_by_role('button',name='index.php').first.click(button='right')
            assert 'Permissions CHMOD' in page.locator('#context-menu').inner_text()
            page.locator('#context-menu button[data-action="chmod"]').click()
            assert page.locator('#mode-octal').inner_text() in ['644','640','664']
            print('PASS: right-click menu and permission dialog')
            page.locator('.permission-check[data-group="0"][data-value="2"]').uncheck()
            assert page.locator('#mode-octal').inner_text() == '444'
            page.locator('.modal-actions .btn-primary').click()
            page.locator('#modal-backdrop').wait_for(state='hidden')
            assert oct((root/'public_html'/'index.php').stat().st_mode & 0o777) == '0o444'
            print('PASS: CHMOD 444 persisted to filesystem')
            page.locator('#settings-btn').click()
            page.locator('#setting-theme').select_option('dark')
            assert page.locator('html').get_attribute('data-theme')=='dark'
            print('PASS: functional preferences')
            page.screenshot(path=str(PROJECT/'preview-dark.png'),full_page=True)
            page.locator('#modal-close').click()
            page.locator('#profile-btn').click()
            assert page.locator('#account-name-menu').inner_text()=='host_account'
            print('PASS: account dropdown')
            assert not console_errors, console_errors
            print('PASS: browser reports no JavaScript exceptions')
            browser.close()
    finally:
        server.terminate()
        server.wait(timeout=5)