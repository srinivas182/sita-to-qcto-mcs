"""
Browser smoke test (CI and local): drives the real web app against the real API.

  php artisan db:seed --class=DemoSeeder   # in apps/api
  php artisan serve --port=8000 &           # API
  pnpm --filter @skills/web build && pnpm --filter @skills/web exec vite preview --port 4173 &
  python3 apps/web/e2e/smoke.py             # SHOTS=/tmp/shots to keep screenshots

Uses the system Chrome (no browser download) and maps *.platform.test to localhost.
"""
import os
import base64, hmac, hashlib, struct, time, sys
from playwright.sync_api import sync_playwright, expect

SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'
def totp():
    key = base64.b32decode(SECRET)
    msg = struct.pack('>Q', int(time.time()) // 30)
    h = hmac.new(key, msg, hashlib.sha1).digest()
    o = h[19] & 15
    return '%06d' % ((struct.unpack('>I', h[o:o+4])[0] & 0x7fffffff) % 1000000)

SHOTS = os.environ.get('SHOTS', '/tmp/smoke-shots')
os.makedirs(SHOTS, exist_ok=True)
PORT = os.environ.get('WEB_PORT', '4173')
errors = []
used = {}
def fresh_totp(email):
    # Replay protection: each 30 s code works once per account; wait for the next one.
    while used.get(email) == int(time.time()) // 30:
        time.sleep(1)
    used[email] = int(time.time()) // 30
    return totp()
with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=os.environ.get('CHROME', '/usr/bin/google-chrome'), args=['--host-resolver-rules=MAP *.platform.test 127.0.0.1', '--no-sandbox'])
    def run(host, email, viewport, prefix):
        ctx = browser.new_context(viewport=viewport, device_scale_factor=2 if viewport['width'] < 500 else 1)
        page = ctx.new_page()
        page.on('pageerror', lambda e: errors.append(f'{prefix}: {e}'))
        page.on('console', lambda m: m.type == 'error' and errors.append(f'{prefix} console: {m.text}'))
        base = f'http://{host}:{PORT}'
        t0 = time.time(); page.goto(base + '/'); page.wait_for_url('**/sign-in**'); page.get_by_label('Email').wait_for()
        print(prefix, 'sign-in visible in %.0f ms' % ((time.time()-t0)*1000))
        page.screenshot(path=f'{SHOTS}/{prefix}-1-sign-in.png')
        page.get_by_label('Email').fill(email)
        page.get_by_label('Password').fill('demo passphrase 2026')
        page.get_by_role('button', name='Sign in', exact=True).click()
        page.get_by_label('Code').wait_for()
        page.screenshot(path=f'{SHOTS}/{prefix}-2-mfa.png')
        page.get_by_label('Code').fill(fresh_totp(email))
        page.get_by_role('button', name='Verify').click()
        page.get_by_text('Good ').first.wait_for()
        page.wait_for_timeout(600)
        page.screenshot(path=f'{SHOTS}/{prefix}-3-overview.png', full_page=True)
        return ctx, page, base

    # Provider owner, desktop
    ctx, page, base = run('tym.platform.test', 'sekal@demo.test', {'width': 1440, 'height': 900}, 'owner')
    for path, name, wait in [('/users', '4-users', 'Ayanda'), ('/brand', '5-brand', 'Sign-in preview'), ('/audit', '6-audit', 'auth.signed_in'), ('/security', '7-security', 'Passkeys'), ('/support', '8-support', 'Support access')]:
        t0 = time.time(); page.click(f'nav a[href="{path}"]'); page.get_by_text(wait).first.wait_for()
        print('owner nav', path, '%.0f ms' % ((time.time()-t0)*1000))
        page.wait_for_timeout(300)
        page.screenshot(path=f'{SHOTS}/owner-{name}.png', full_page=True)
    page.keyboard.press('Control+k'); page.get_by_placeholder('Search or jump to…').fill('aud'); page.wait_for_timeout(200)
    page.screenshot(path=f'{SHOTS}/owner-9-command.png')
    page.keyboard.press('Enter'); page.wait_for_url(base + '/audit', wait_until='commit')
    # Add custom domain through the UI
    page.click('nav a[href="/brand"]'); page.get_by_label('Add your own domain').fill('learn.trainingyoungminds.co.za')
    page.get_by_role('button', name='Connect domain').click(); page.get_by_text('Check now').first.wait_for(); page.wait_for_timeout(300)
    page.screenshot(path=f'{SHOTS}/owner-10-domain-records.png', full_page=True)
    ctx.close()

    # Super Admin, desktop
    ctx, page, base = run('admin.platform.test', 'admin@demo.test', {'width': 1440, 'height': 900}, 'admin')
    page.click('nav a[href="/tenants"]'); page.get_by_text('Mzansi Build Institute').first.wait_for(); page.wait_for_timeout(300)
    page.screenshot(path=f'{SHOTS}/admin-4-tenants.png', full_page=True)
    page.get_by_text('Mzansi Build Institute').first.click(); page.get_by_text('Invite the owner').first.wait_for(); page.wait_for_timeout(300)
    page.screenshot(path=f'{SHOTS}/admin-5-tenant.png', full_page=True)
    page.get_by_role('button', name='Approve & go live').click(); page.get_by_text('Provider approved').first.wait_for()
    page.click('nav a[href="/cells"]'); page.get_by_text('South Africa 1').first.wait_for(); page.wait_for_timeout(300)
    page.screenshot(path=f'{SHOTS}/admin-6-cells.png', full_page=True)
    ctx.close()

    # Phone
    ctx = browser.new_context(viewport={'width': 390, 'height': 844}, device_scale_factor=2, is_mobile=True, has_touch=True)
    page = ctx.new_page(); page.goto(f'http://tym.platform.test:{PORT}/sign-in'); page.get_by_label('Email').wait_for()
    page.screenshot(path=f'{SHOTS}/phone-1-sign-in.png')
    page.get_by_text('Learner? Sign in with your phone').click(); page.get_by_label('Mobile, SA ID or passport number').fill('082 123 4567')
    page.get_by_role('button', name='Send PIN').click(); page.get_by_label('6-digit PIN').wait_for()
    page.screenshot(path=f'{SHOTS}/phone-2-pin.png')
    ctx.close()
    ctx, page, base = run('tym.platform.test', 'sekal@demo.test', {'width': 390, 'height': 844}, 'phone-owner')
    page.get_by_label('Open menu').click(); page.wait_for_timeout(300)
    page.screenshot(path=f'{SHOTS}/phone-owner-4-drawer.png')
    browser.close()

# 401 on the first /me (signed out) and 422 for an already-connected domain on reruns are expected.
unexpected = [e for e in errors if '401' not in e and '422' not in e]
print('Unexpected browser errors:', unexpected or 'none')
sys.exit(1 if unexpected else 0)
