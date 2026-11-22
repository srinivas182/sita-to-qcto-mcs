"""
Accessibility audit (axe-core, WCAG 2.0/2.1/2.2 A and AA) of the main web screens with
demo data. Fails on any violation. Runs in CI after the smoke test.
"""
import os, sys
import json, base64, hmac, hashlib, struct, time, zlib, tempfile
from playwright.sync_api import sync_playwright
AXE = open(os.path.join(os.path.dirname(__file__), '../node_modules/axe-core/axe.min.js')).read()
PORT = os.environ.get('WEB_PORT', '4173')
failed = []
def totp():
    key = base64.b32decode('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'); h = hmac.new(key, struct.pack('>Q', int(time.time())//30), hashlib.sha1).digest(); o = h[19] & 15
    return '%06d' % ((struct.unpack('>I', h[o:o+4])[0] & 0x7fffffff) % 1000000)
def audit(page, name):
    page.wait_for_timeout(500); page.add_script_tag(content=AXE)
    r = page.evaluate("async () => (await axe.run(document, {runOnly: ['wcag2a','wcag2aa','wcag21aa','wcag22aa']})).violations.map(v => ({id: v.id, impact: v.impact, n: v.nodes.length, help: v.help, target: v.nodes[0].target}))")
    print(name, json.dumps(r) if r else 'no violations')
    if r: failed.append(name)
def png(path, w=96, h=96):
    raw = b''.join(b'\x00' + bytes([67, 56, 202]) * w for _ in range(h))
    chunk = lambda t, d: struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    open(path, 'wb').write(b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b''))
    return path
def sign_in(page, host, email):
    page.goto(f'http://{host}:{PORT}/sign-in'); page.get_by_label('Email').wait_for()
    page.get_by_label('Email').fill(email); page.get_by_label('Password').fill('demo passphrase 2026')
    page.get_by_role('button', name='Sign in', exact=True).click(); page.get_by_label('Code').wait_for()
    page.get_by_label('Code').fill(totp()); page.get_by_role('button', name='Verify').click(); page.get_by_text('Good ').first.wait_for()
# Each authenticator code works once per account (replay protection): start in a fresh window.
time.sleep(31 - time.time() % 30)
with sync_playwright() as p:
    b = p.chromium.launch(executable_path=os.environ.get('CHROME', '/usr/bin/google-chrome'), args=['--host-resolver-rules=MAP *.platform.test 127.0.0.1, MAP platform.test 127.0.0.1','--no-sandbox'])
    page = b.new_page(viewport={'width':1280,'height':900})
    page.goto(f'http://tym.platform.test:{PORT}/sign-in'); page.get_by_label('Email').wait_for(); audit(page, 'sign-in')
    page.get_by_label('Email').fill('sekal@demo.test'); page.get_by_label('Password').fill('demo passphrase 2026')
    page.get_by_role('button', name='Sign in', exact=True).click(); page.get_by_label('Code').wait_for(); audit(page,'mfa')
    page.get_by_label('Code').fill(totp()); page.get_by_role('button', name='Verify').click(); page.get_by_text('Good ').first.wait_for(); audit(page,'overview')
    for path, wait in [('/users','Ayanda'),('/brand','Sign-in preview'),('/security','Passkeys'),('/audit','Audit log')]:
        page.click(f'nav a[href="{path}"]'); page.get_by_text(wait).first.wait_for(); audit(page, path)

    # Logo upload: through the API to local storage, scanned, resized, then saved with the brand.
    page.click('nav a[href="/brand"]'); page.get_by_text('Sign-in preview').wait_for()
    page.locator('input[type=file]').set_input_files(png(os.path.join(tempfile.gettempdir(), 'logo.png')))
    page.get_by_alt_text('Current logo').wait_for(timeout=20000)
    page.get_by_role('button', name='Save brand').click(); page.get_by_text('Brand updated').wait_for(); audit(page, '/brand logo')

    # Billing: plan, estimate, invoices, change-plan and pay dialogs.
    page.click('nav a[href="/billing"]'); page.get_by_text('Growth plan').wait_for(); audit(page, '/billing')
    page.get_by_role('button', name='Change plan').click(); page.get_by_role('dialog').wait_for(); audit(page, '/billing plan dialog')
    page.keyboard.press('Escape')

    # Programmes from the catalogue: the list, the catalogue browser and the add dialog.
    page.click('nav a[href="/programmes"]'); page.get_by_text('General Education and Training Certificate: Construction').first.wait_for(); audit(page, '/programmes')
    page.get_by_role('button', name='Add from catalogue').click(); page.get_by_role('dialog').wait_for(); page.get_by_text('Offered').first.wait_for(); audit(page, '/programmes catalogue')
    page.keyboard.press('Escape')

    # Registrations, the provider's own roles, import and the owner's profile.
    page.click('nav a[href="/people/credentials"]'); page.get_by_text('Sipho Ndlovu').first.wait_for(); audit(page, '/people/credentials')
    page.click('nav a[href="/users"]'); page.get_by_role('button', name='Import').click(); page.get_by_role('dialog').wait_for(); audit(page, '/users import')
    page.keyboard.press('Escape')
    page.get_by_role('link', name='Your own roles').click(); page.get_by_text('Quality officer', exact=True).first.wait_for(); audit(page, '/roles')
    page.get_by_text('Quality officer', exact=True).first.click(); page.get_by_role('dialog').wait_for(); audit(page, '/roles dialog')
    page.keyboard.press('Escape')
    page.click('nav a[href="/profile"]'); page.get_by_text('Equity information (optional)').wait_for(); audit(page, '/profile')

    # Centres with the expiry warning, and the edit dialog.
    page.click('nav a[href="/centres"]'); page.get_by_text('Durban Site', exact=True).first.wait_for(); audit(page, '/centres')
    page.get_by_text('Durban Site', exact=True).first.click(); page.get_by_role('dialog').wait_for(); audit(page, '/centres dialog')
    page.keyboard.press('Escape')

    # The public site: pricing, applying (start, email code) and resuming.
    pub = b.new_page(viewport={'width':1280,'height':900})
    pub.goto(f'http://platform.test:{PORT}/pricing'); pub.get_by_text('Starter').first.wait_for(); audit(pub, '/pricing')
    pub.goto(f'http://platform.test:{PORT}/apply'); pub.get_by_label('Work email').wait_for(); audit(pub, '/apply start')
    pub.get_by_label('First name').fill('Nomsa'); pub.get_by_label('Last name').fill('Khumalo')
    pub.get_by_label('Work email').fill('nomsa@khumalo-skills.demo'); pub.get_by_label('Registered company name').fill('Khumalo Skills (Pty) Ltd')
    pub.get_by_role('button', name='Start application').click(); pub.get_by_text('Check your email').wait_for(); audit(pub, '/apply email code')
    pub.goto(f'http://platform.test:{PORT}/apply/resume'); pub.get_by_label('Reference').wait_for(); audit(pub, '/apply/resume')

    # Super Admin settings (a different account, so the same code window is fine).
    admin = b.new_page(viewport={'width':1280,'height':900})
    sign_in(admin, 'admin.platform.test', 'admin@demo.test')
    admin.click('nav a[href="/applications"]'); admin.get_by_text('Ikhwezi Skills Academy').first.wait_for(); audit(admin, '/applications')
    admin.get_by_text('Ikhwezi Skills Academy').first.click(); admin.get_by_text('Reviewer checklist').wait_for(); audit(admin, '/applications detail')
    admin.click('nav a[href="/catalogue"]'); admin.get_by_text('Occupational Certificate: Bricklayer').first.wait_for(); audit(admin, '/catalogue')
    admin.get_by_text('General Education and Training Certificate: Construction').first.click(); admin.get_by_text('Everything adds up.').wait_for(); audit(admin, '/catalogue entry')
    admin.get_by_role('button', name='Replace modules').click(); admin.get_by_role('dialog').wait_for(); audit(admin, '/catalogue paste dialog')
    admin.keyboard.press('Escape')
    admin.click('nav a[href="/settings"]'); admin.get_by_role('heading', name='Company details').wait_for(); audit(admin, '/settings company')
    admin.get_by_role('tab', name='Payments').click(); admin.get_by_text('Paystack').first.wait_for(); audit(admin, '/settings payments')
    admin.get_by_role('button', name='Set up').first.click(); admin.get_by_role('dialog').wait_for(); audit(admin, '/settings gateway dialog')
    admin.keyboard.press('Escape')
    admin.get_by_role('tab', name='Storage').click(); admin.get_by_text('Azure Blob Storage').wait_for(); audit(admin, '/settings storage')
    admin.get_by_role('tab', name='Billing rules').click(); admin.get_by_role('heading', name='Billing rules').wait_for(); audit(admin, '/settings billing rules')
    admin.click('nav a[href="/billing"]'); admin.get_by_text('Monthly recurring').wait_for(); audit(admin, '/billing admin')
    admin.get_by_role('tab', name='packages').click(); admin.get_by_text('Starter').first.wait_for(); audit(admin, '/billing packages')
    admin.click('nav a[href="/messages"]'); admin.get_by_text('By provider').wait_for(); audit(admin, '/messages')
    admin.click('nav a[href="/settings"]'); admin.get_by_role('tab', name='SMS').click(); admin.get_by_text('Clickatell').first.wait_for(); audit(admin, '/settings sms')
    b.close()

sys.exit(1 if failed else 0)
