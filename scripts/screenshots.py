"""Screenshots of the plugins on the testbench panel, dark theme, cropped to the content.
usage: python -I scripts/screenshots.py <out dir> <password> [names...] - only these screenshots when names are given"""
import pathlib
import sys

from playwright.sync_api import sync_playwright

BASE = 'http://192.168.2.73'
SERVER = 'ab740b72'
ONLY = sys.argv[3:]
# the chromium that is installed; this playwright version would want a newer build
CHROME = pathlib.Path.home() / 'AppData/Local/ms-playwright/chromium-1234/chrome-win64/chrome.exe'
out = pathlib.Path(sys.argv[1])
out.mkdir(parents=True, exist_ok=True)

# hides what is not the plugin: pelican's own dashboard boxes, the footer, toasts
HIDE = """(texts) => {
    for (const text of texts) {
        for (const heading of document.querySelectorAll('h2, h3, span, div')) {
            if (heading.childElementCount === 0 && heading.textContent.trim() === text) {
                const box = heading.closest('.fi-wi-widget, .fi-section, section');
                if (box) box.style.display = 'none';
            }
        }
    }
    document.querySelectorAll('footer, .fi-footer, .fi-no-notification, .fi-no').forEach(e => e.style.display = 'none');
}"""

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=str(CHROME))
    page = browser.new_page(viewport={'width': 1440, 'height': 900}, color_scheme='dark', device_scale_factor=2)

    page.goto(f'{BASE}/login')
    page.locator('input[type="text"], input[type="email"]').first.fill('admin')
    page.locator('input[type="password"]').fill(sys.argv[2])
    page.locator('button[type="submit"]').click()
    page.wait_for_url(lambda url: '/login' not in url, timeout=20000)

    def shot(name, url, prepare=None, hide=()):
        if ONLY and name not in ONLY:
            return
        page.goto(url)
        page.wait_for_load_state('networkidle')
        if prepare:
            prepare()
            page.wait_for_load_state('networkidle')
        page.wait_for_timeout(1500)
        page.evaluate(HIDE, list(hide))
        page.locator('.fi-main').first.screenshot(path=str(out / f'{name}.png'))
        print('saved', name)

    shot('server-resources', f'{BASE}/admin', hide=['Your Panel is up to date', 'Support Pelican', 'Need Help?'])
    shot('mod-manager-installed', f'{BASE}/server/{SERVER}/mods')

    def browse():
        page.locator('.fi-tabs-item').nth(1).click()
        page.wait_for_timeout(2500)

    shot('mod-manager-browse', f'{BASE}/server/{SERVER}/mods', browse)


    def entries():
        # the configs tab opens on the first access list; typed in, never saved
        page.locator('.fi-tabs-item').nth(2).click()
        page.wait_for_timeout(2500)
        field = page.get_by_placeholder('Add entry')
        for entry in ['Steam_76561197960287930', 'Steam_76561198012345678']:
            field.fill(entry)
            field.press('Enter')

    shot('mod-manager-configs', f'{BASE}/server/{SERVER}/mods', entries)

    browser.close()
