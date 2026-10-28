import pathlib, sys
from playwright.sync_api import sync_playwright
BASE = 'http://192.168.2.73'
CHROME = pathlib.Path.home() / 'AppData/Local/ms-playwright/chromium-1234/chrome-win64/chrome.exe'
JS = "() => [...document.querySelectorAll('tbody tr')].slice(0, 6).map(r => r.children[0].innerText.split('\\n')[0])"
with sync_playwright() as p:
    page = p.chromium.launch(executable_path=str(CHROME)).new_page(viewport={'width': 1300, 'height': 900})
    page.goto(f'{BASE}/login')
    page.locator('input[type="text"], input[type="email"]').first.fill('admin')
    page.locator('input[type="password"]').fill(sys.argv[1])
    page.locator('button[type="submit"]').click()
    page.wait_for_url(lambda u: '/login' not in u, timeout=20000)
    for server in sys.argv[2:]:
        page.goto(f'{BASE}/server/{server}/mods'); page.wait_for_load_state('networkidle')
        page.locator('.fi-tabs-item').nth(1).click(); page.wait_for_load_state('networkidle'); page.wait_for_timeout(800)
        print(server, page.locator('.fi-tabs-item').all_inner_texts(), page.locator('button:has-text("GameBanana"), button:has-text("Modrinth")').count())
