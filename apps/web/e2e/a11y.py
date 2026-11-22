"""
Accessibility audit (axe-core, WCAG 2.0/2.1/2.2 A and AA) of the main web screens with
demo data. Fails on any violation. Runs in CI after the smoke test.
"""
import os, sys
import json, base64, hmac, hashlib, struct, time
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
# Each authenticator code works once per account (replay protection): start in a fresh window.
time.sleep(31 - time.time() % 30)
with sync_playwright() as p:
    b = p.chromium.launch(executable_path=os.environ.get('CHROME', '/usr/bin/google-chrome'), args=['--host-resolver-rules=MAP *.platform.test 127.0.0.1','--no-sandbox'])
    page = b.new_page(viewport={'width':1280,'height':900})
    page.goto(f'http://tym.platform.test:{PORT}/sign-in'); page.get_by_label('Email').wait_for(); audit(page, 'sign-in')
    page.get_by_label('Email').fill('sekal@demo.test'); page.get_by_label('Password').fill('demo passphrase 2026')
    page.get_by_role('button', name='Sign in', exact=True).click(); page.get_by_label('Code').wait_for(); audit(page,'mfa')
    page.get_by_label('Code').fill(totp()); page.get_by_role('button', name='Verify').click(); page.get_by_text('Good ').first.wait_for(); audit(page,'overview')
    for path, wait in [('/users','Ayanda'),('/brand','Sign-in preview'),('/security','Passkeys'),('/audit','Audit log')]:
        page.click(f'nav a[href="{path}"]'); page.get_by_text(wait).first.wait_for(); audit(page, path)
    b.close()

sys.exit(1 if failed else 0)
