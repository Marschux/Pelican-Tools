import pathlib, sys
from playwright.sync_api import sync_playwright
CHROME = pathlib.Path.home() / 'AppData/Local/ms-playwright/chromium-1234/chrome-win64/chrome.exe'
with sync_playwright() as p:
    page = p.chromium.launch(executable_path=str(CHROME)).new_page(viewport={'width': 1440, 'height': 1000}, color_scheme='dark')
    page.goto('http://192.168.2.73/login')
    page.locator('input[type="text"], input[type="email"]').first.fill('admin')
    page.locator('input[type="password"]').fill(sys.argv[1])
    page.locator('button[type="submit"]').click()
    page.wait_for_url(lambda u: '/login' not in u, timeout=20000)
    page.goto(f'http://192.168.2.73/server/{sys.argv[2]}/mods'); page.wait_for_load_state('networkidle')
    if len(sys.argv) > 3:
        page.locator('.fi-tabs-item').nth(int(sys.argv[3])).click(); page.wait_for_timeout(3000)
    page.screenshot(path='upd.png', full_page=True)
