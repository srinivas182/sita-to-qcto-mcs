"""
Phone-sized walkthrough of the app in Chrome (design review; not a substitute for
device testing). Needs the API with DemoSeeder data and OTP_LOG_CODES=true:

  API_BASE_URL=http://127.0.0.1:8090/v1 pnpm --filter @skills/mobile exec expo export --platform web --output-dir /tmp/expo-web
  python3 apps/mobile/e2e/serve.py &        # serves /tmp/expo-web, proxies /v1 to :8000
  python3 apps/mobile/e2e/preview.py        # screenshots in /tmp/mshots
"""
import re, time, subprocess
from playwright.sync_api import sync_playwright

import os
LOG = os.path.join(os.path.dirname(__file__), '../../api/storage/logs/laravel.log')
os.makedirs('/tmp/mshots', exist_ok=True)
def last_pin():
    text = open(LOG).read()
    pins = re.findall(r'(\d{6}) is your Training Young Minds sign-in PIN', text)
    return pins[-1]

errors = []
with sync_playwright() as p:
    b = p.chromium.launch(executable_path='/usr/bin/google-chrome', args=['--no-sandbox'])
    ctx = b.new_context(viewport={'width': 390, 'height': 844}, device_scale_factor=2, is_mobile=True, has_touch=True)
    page = ctx.new_page()
    page.on('pageerror', lambda e: errors.append(str(e)))
    def shot(name): page.wait_for_timeout(400); page.screenshot(path=f'/tmp/mshots/{name}.png')
    page.goto('http://127.0.0.1:8090/')
    page.get_by_text('Your training, in your pocket').wait_for(); shot('1-welcome')
    page.get_by_role('button', name='Get started').click()
    page.get_by_label('Provider code').fill('tym'); shot('2-provider-code')
    page.get_by_role('button', name='Continue').click()
    page.get_by_label('Mobile, SA ID or passport').fill('082 123 4567'); shot('3-sign-in')
    page.get_by_role('button', name='Send PIN').click()
    page.get_by_label('Enter the 6-digit PIN').wait_for(); shot('4-pin')
    page.get_by_label('Enter the 6-digit PIN').fill(last_pin())
    page.get_by_text('Choose a 6-digit PIN').wait_for(); shot('5-set-pin')
    for d in '482915': page.get_by_role('button', name=d, exact=True).click()
    page.get_by_text('Enter it again').wait_for()
    for d in '482915': page.get_by_role('button', name=d, exact=True).click()
    page.get_by_text('Hi Ayanda').wait_for(); shot('6-home')
    page.get_by_role('tab', name='Learn').click(); page.get_by_text('Your courses').first.wait_for(); shot('7-learn')
    page.get_by_role('tab', name='Settings').click(); page.get_by_text('Signed-in phones').wait_for(); shot('8-settings')
    page.get_by_role('radio', name='Dark').click(); page.wait_for_timeout(300); shot('9-settings-dark')
    page.get_by_role('tab', name='Home').click(); page.get_by_text('Hi Ayanda').wait_for(); shot('10-home-dark')
    # Offline: banner appears, home still renders from the encrypted cache.
    ctx.set_offline(True); page.evaluate("window.dispatchEvent(new Event('offline'))"); page.wait_for_timeout(1200); shot('11-offline')
    ctx.set_offline(False); page.evaluate("window.dispatchEvent(new Event('online'))"); page.wait_for_timeout(800)
    # Relaunch → app PIN unlock
    page.reload(); page.get_by_text('Welcome back').wait_for(); shot('12-unlock')
    for d in '482915': page.get_by_role('button', name=d, exact=True).click()
    page.get_by_text('Hi Ayanda').wait_for()
    # Remote wipe from the web console: revoke this device, next refresh signs out.
    b.close()
print('errors:', errors or 'none')
