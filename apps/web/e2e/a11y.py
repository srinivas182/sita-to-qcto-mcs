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

    # Employers: the list with the approval warning, one employer, and its workplace and mentor dialogs.
    page.click('nav a[href="/employers"]'); page.get_by_text('Mabaso Builders (Pty) Ltd').first.wait_for(); audit(page, '/employers')
    page.get_by_role('link', name='Mabaso Builders (Pty) Ltd').click(); page.get_by_text('Zodwa Khumalo').first.wait_for(); audit(page, '/employers/:id')
    page.get_by_role('button', name='Change Soweto yard').first.click(); page.get_by_role('dialog').wait_for(); page.get_by_label('Learners per mentor').wait_for(); audit(page, '/employers workplace dialog')
    page.keyboard.press('Escape')
    page.get_by_role('button', name='Add mentor').first.click(); page.get_by_role('dialog').wait_for(); audit(page, '/employers mentor dialog')
    page.keyboard.press('Escape')

    # Applications: intakes, the board with its share card, the intake and decline dialogs.
    page.goto(f'http://tym.platform.test:{PORT}/intakes'); page.get_by_role('link', name='Bricklayer, December 2026').first.wait_for(); audit(page, '/intakes')
    page.get_by_role('button', name='Open an intake').click(); page.get_by_role('dialog').wait_for(); audit(page, '/intakes dialog')
    page.keyboard.press('Escape')
    page.get_by_role('link', name='Bricklayer, December 2026').first.click(); page.get_by_text('Thando Mokoena').first.wait_for(); audit(page, '/intakes/:id')
    page.get_by_role('button', name='Decline Kagiso Molefe').first.click(); page.get_by_role('dialog').wait_for(); audit(page, '/intakes decline dialog')
    page.keyboard.press('Escape')
    page.get_by_role('link', name='Thando Mokoena').first.click(); page.get_by_text('Checked automatically when added').wait_for(); page.wait_for_timeout(500); audit(page, '/applications/:id')
    page.get_by_role('button', name='Check this document').first.click(); page.get_by_role('dialog').wait_for(); audit(page, '/applications review dialog')
    page.keyboard.press('Escape')
    page.goto(f'http://tym.platform.test:{PORT}/intakes'); page.get_by_role('button', name='Rules', exact=True).click(); page.get_by_role('dialog').get_by_text('Proof of address').wait_for(); audit(page, '/intakes rules dialog')
    page.keyboard.press('Escape')
    page.get_by_role('button', name='Consent wording').click(); page.get_by_role('textbox', name='Wording').wait_for(); page.wait_for_timeout(300); audit(page, '/intakes consent dialog')
    page.keyboard.press('Escape')
    page.get_by_role('link', name='Bricklayer, December 2026').first.click(); page.get_by_role('link', name='Lerato Ndlovu').first.click()
    page.get_by_text('Waiting for a reply').wait_for(); audit(page, '/applicants/:id offer')
    page.get_by_role('link', name='Bricklayer, December 2026').first.click(); page.get_by_role('link', name='Nomsa Dube').first.click(); page.get_by_role('button', name='Make an offer').wait_for(); audit(page, '/applicants/:id no offer')

    # Cohorts: the list, and a cohort being planned with its readiness check.
    page.goto(f'http://tym.platform.test:{PORT}/cohorts'); page.get_by_text('GETC: Construction, February 2027').first.wait_for(); audit(page, '/cohorts')
    page.get_by_role('link', name='GETC: Construction, February 2027').first.click(); page.get_by_text('Enrolment closed').wait_for(); audit(page, '/cohorts/:id')
    page.goto(f'http://tym.platform.test:{PORT}/cohorts'); page.get_by_role('link', name='Bricklayer, December 2026').first.click(); page.get_by_role('link', name='QCTO enrolment').click()
    page.get_by_text('Bongani Khumalo', exact=True).wait_for(); audit(page, '/cohorts/:id/qcto')
    page.get_by_role('button', name='Fill in details for Bongani Khumalo').click(); page.get_by_label('Stats SA area code').wait_for(); audit(page, '/cohorts qcto fix dialog')
    page.keyboard.press('Escape')
    page.get_by_role('button', name='Mark as sent').click(); page.get_by_label('Date sent').wait_for(); audit(page, '/cohorts qcto sent dialog')
    page.keyboard.press('Escape')
    page.get_by_role('link', name='Bricklayer, December 2026').first.click(); page.get_by_role('link', name='Timetable').click()
    page.get_by_role('button', name='Next week').click(); page.get_by_text('Safety on a building site').first.wait_for(); audit(page, '/cohorts/:id/timetable')
    page.get_by_text('Safety on a building site').first.click(); page.get_by_role('dialog').get_by_label('Starts').wait_for(); audit(page, '/timetable session dialog')
    page.keyboard.press('Escape')
    page.get_by_role('button', name='Rooms').click(); page.get_by_role('button', name='Retire Brick yard').wait_for(); audit(page, '/timetable rooms dialog')
    page.keyboard.press('Escape')
    page.get_by_text('Safety on a building site').first.click(); page.get_by_role('dialog').get_by_role('link', name='Attendance').click()
    page.get_by_text('Class list').wait_for(); page.wait_for_timeout(300); audit(page, '/classes/:id')
    page.get_by_role('button', name='Mark Bongani Khumalo').click(); page.get_by_role('dialog').get_by_label('Reason').wait_for(); audit(page, '/classes mark dialog')
    page.keyboard.press('Escape')
    page.get_by_role('button', name='Show check-in code').click(); page.get_by_role('img', name='Check-in code').wait_for(); audit(page, '/classes code dialog')
    page.keyboard.press('Escape')
    page.goto(f'http://tym.platform.test:{PORT}/cohorts'); page.get_by_role('link', name='Bricklayer, December 2026').first.click(); page.get_by_role('link', name='Attendance').click()
    page.get_by_role('heading', name='Attendance', exact=True).wait_for(); page.get_by_role('button', name='Attendance rules').wait_for(); page.wait_for_timeout(500); audit(page, '/cohorts/:id/attendance')
    page.get_by_role('button', name='Attendance rules').click(); page.get_by_role('dialog').get_by_label('Late after (minutes)').wait_for(); audit(page, '/attendance rules dialog')
    page.keyboard.press('Escape')
    page.get_by_role('link', name='Monthly registers').click(); page.get_by_text('No registers yet').wait_for(); audit(page, '/cohorts/:id/registers')
    page.get_by_role('button', name='Make a register').click(); page.get_by_role('dialog').get_by_label('Month').wait_for(); audit(page, '/registers make dialog')
    page.keyboard.press('Escape')
    page.goto(f'http://tym.platform.test:{PORT}/programmes'); page.get_by_role('link', name='Materials for Occupational Certificate: Bricklayer').click()
    page.get_by_text('Safety on a building site: notes', exact=True).first.wait_for(); audit(page, '/programmes/:id/materials')
    page.get_by_role('button', name='Add material').click(); page.get_by_role('dialog').get_by_label('Title').wait_for(); audit(page, '/materials dialog')
    page.keyboard.press('Escape')
    page.goto(f'http://tym.platform.test:{PORT}/programmes'); page.get_by_role('link', name='Tasks for Occupational Certificate: Bricklayer').click()
    page.get_by_text('Site safety quiz').first.wait_for(); audit(page, '/programmes/:id/tasks')
    page.get_by_role('button', name='Add a task').click(); page.get_by_role('dialog').get_by_label('Question').first.wait_for(); audit(page, '/tasks dialog')
    page.keyboard.press('Escape')
    page.goto(f'http://tym.platform.test:{PORT}/marking'); page.get_by_role('button', name='Mark Bongani Khumalo').wait_for(); audit(page, '/marking')
    page.get_by_role('button', name='Mark Bongani Khumalo').click(); page.get_by_role('dialog').get_by_label('Feedback for the learner').wait_for(); audit(page, '/marking dialog')
    page.keyboard.press('Escape')
    page.goto(f'http://tym.platform.test:{PORT}/cohorts'); page.get_by_role('link', name='Bricklayer, December 2026').first.click(); page.get_by_role('link', name='Task results').click()
    page.get_by_role('table').wait_for(); audit(page, '/cohorts/:id/tasks')
    page.goto(f'http://tym.platform.test:{PORT}/programmes'); page.get_by_role('link', name='Logbook for Occupational Certificate: Bricklayer').click()
    page.get_by_label('Activity 1').first.wait_for(); audit(page, '/programmes/:id/logbook')
    page.goto(f'http://tym.platform.test:{PORT}/programmes'); page.get_by_role('link', name='Assessments for Occupational Certificate: Bricklayer').click()
    page.get_by_text('Assessment and moderation policy').wait_for(); page.get_by_role('button', name='Edit PM-01').click(); page.get_by_label('Title 1').wait_for(); audit(page, '/programmes/:id/assessments')
    page.goto(f'http://tym.platform.test:{PORT}/cohorts'); page.get_by_role('link', name='Bricklayer, December 2026').first.click(); page.get_by_role('link', name='Logbook').click()
    page.get_by_role('table').wait_for(); page.get_by_text('Soweto yard').first.wait_for(); audit(page, '/cohorts/:id/logbook')
    page.get_by_role('button', name='Place a learner').click(); page.get_by_role('dialog').get_by_label('Workplace').wait_for(); audit(page, '/cohorts/:id/logbook place dialog')
    page.keyboard.press('Escape')
    page.get_by_role('button', name='Bongani Khumalo', exact=True).click(); page.get_by_role('dialog').get_by_text('Laid six courses').wait_for(); audit(page, '/cohorts/:id/logbook entries dialog')
    page.keyboard.press('Escape')
    page.goto(f'http://tym.platform.test:{PORT}/cohorts'); page.get_by_role('link', name='Bricklayer, December 2026').first.click(); page.get_by_role('link', name='Statements of Results').click()
    page.get_by_text('ready to issue').wait_for(); audit(page, '/cohorts/:id/readiness')
    page.goto(f'http://tym.platform.test:{PORT}/sor/abcdefghij'); page.get_by_text("We can't find this statement").wait_for(); audit(page, '/sor/:code (not found)')
    page.goto(f'http://tym.platform.test:{PORT}/c/01jdemo0000000000000000000.1.0123456789abcdef0123'); page.get_by_role('button', name='Check in now').wait_for(); audit(page, '/c/:token')
    page.goto(f'http://tym.platform.test:{PORT}/cohorts'); page.get_by_role('button', name='Plan a cohort').click(); page.get_by_role('dialog').wait_for(); audit(page, '/cohorts plan dialog')
    page.keyboard.press('Escape')

    # Agreements: the list, one agreement, the send dialog, the wording and its editor.
    page.click('nav a[href="/agreements"]'); page.get_by_text('Lerato Nkosi').first.wait_for(); audit(page, '/agreements')
    page.get_by_role('button', name='Send agreement').click(); page.get_by_role('dialog').wait_for(); audit(page, '/agreements send dialog')
    page.keyboard.press('Escape')
    page.get_by_role('link', name='Workplace learning agreement — Ayanda Zulu').first.click(); page.get_by_text('Send a new link').first.wait_for(); audit(page, '/agreements/:id')
    page.goto(f'http://tym.platform.test:{PORT}/agreements/templates'); page.get_by_text('How agreements are signed').wait_for(); audit(page, '/agreements/templates')
    page.get_by_role('link', name='Host employer memorandum of understanding').first.click(); page.get_by_text('Who signs').wait_for(); audit(page, '/agreements/templates/:id')

    # Centres with the expiry warning, and the edit dialog.
    page.click('nav a[href="/centres"]'); page.get_by_text('Durban Site', exact=True).first.wait_for(); audit(page, '/centres')
    page.get_by_text('Durban Site', exact=True).first.click(); page.get_by_role('dialog').wait_for(); audit(page, '/centres dialog')
    page.keyboard.press('Escape')

    # The public site: pricing, applying (start, email code) and resuming.
    pub = b.new_page(viewport={'width':1280,'height':900})
    # Applying: the public intake page, then the form after the PIN is sent.
    pub.goto(f'http://tym.platform.test:{PORT}/join/bricklay'); pub.get_by_role('button', name='Send me a PIN').wait_for(); audit(pub, '/join/:slug')
    pub.get_by_label('Your mobile number').fill('0849990000'); pub.get_by_role('button', name='Send me a PIN').click(); pub.get_by_label('PIN', exact=True).wait_for(); audit(pub, '/join/:slug form')

    # Signing and checking agreements: public pages on the provider's address.
    pub.goto(f'http://tym.platform.test:{PORT}/offer/DemoOfferLinkForLeratoNdlovu00000000000000000000'); pub.get_by_role('button', name='Accept the place').wait_for(); audit(pub, '/offer/:token')
    pub.get_by_role('button', name='Decline').click(); pub.get_by_label('Reason (optional)').wait_for(); audit(pub, '/offer/:token decline')
    pub.goto(f'http://tym.platform.test:{PORT}/sign/DemoSigningLinkForAyandaZulu00000000000000000000'); pub.get_by_role('button', name='Send me a PIN').wait_for(); audit(pub, '/sign/:token')
    pub.get_by_role('button', name='Send me a PIN').click(); pub.get_by_label('PIN', exact=True).wait_for(); audit(pub, '/sign/:token pin')
    pub.goto(f'http://tym.platform.test:{PORT}/verify/demosigned'); pub.get_by_text('Check a copy').wait_for(); audit(pub, '/verify/:code')

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
    # A learner (no second factor for learners): only their enrolled course.
    ln = b.new_context(viewport={'width': 390, 'height': 844}).new_page()
    ln.goto(f'http://tym.platform.test:{PORT}/sign-in'); ln.get_by_label('Email').wait_for()
    ln.get_by_label('Email').fill('bongani@demo.test'); ln.get_by_label('Password').fill('demo passphrase 2026')
    ln.get_by_role('button', name='Sign in', exact=True).click(); ln.get_by_text('Good ').first.wait_for()
    ln.goto(f'http://tym.platform.test:{PORT}/my-course'); ln.get_by_text('Safety on a building site: notes').first.wait_for(); audit(ln, '/my-course (learner, phone)')
    ln.get_by_text('Site safety quiz').wait_for(); audit(ln, '/my-course tasks (learner, phone)')
    # A workplace mentor (Host employer, no second factor): weeks to sign, on a phone.
    mn = b.new_context(viewport={'width': 390, 'height': 844}).new_page()
    mn.goto(f'http://tym.platform.test:{PORT}/sign-in'); mn.get_by_label('Email').wait_for()
    mn.get_by_label('Email').fill('bheki@demo.test'); mn.get_by_label('Password').fill('demo passphrase 2026')
    mn.get_by_role('button', name='Sign in', exact=True).click(); mn.get_by_text('Good ').first.wait_for()
    mn.goto(f'http://tym.platform.test:{PORT}/logbooks'); mn.get_by_text('Weeks to sign').wait_for(); mn.get_by_text('Bongani Khumalo').first.wait_for(); audit(mn, '/logbooks (mentor, phone)')
    mn.get_by_role('button', name='Check').first.click(); mn.get_by_role('dialog').get_by_text('Laid six courses').wait_for(); audit(mn, '/logbooks week dialog (mentor, phone)')
    mn.get_by_role('button', name='Send back…').click(); mn.get_by_label('What should the learner fix?').wait_for(); audit(mn, '/logbooks send back (mentor, phone)')
    # An assessor: cohorts, results grid, a learner's results and the decision dialog.
    asr = b.new_context().new_page()
    asr.goto(f'http://tym.platform.test:{PORT}/sign-in'); asr.get_by_label('Email').wait_for()
    asr.get_by_label('Email').fill('sipho@demo.test'); asr.get_by_label('Password').fill('demo passphrase 2026')
    asr.get_by_role('button', name='Sign in', exact=True).click(); asr.get_by_text('Good ').first.wait_for()
    asr.goto(f'http://tym.platform.test:{PORT}/assessing'); asr.get_by_role('link', name='Bricklayer, December 2026').wait_for(); audit(asr, '/assessing (assessor)')
    asr.get_by_role('link', name='Bricklayer, December 2026').click(); asr.get_by_role('table').wait_for(); audit(asr, '/cohorts/:id/results (assessor)')
    asr.get_by_role('link', name='Bongani Khumalo').click(); asr.get_by_text('All decisions').wait_for(); audit(asr, '/cohorts/:id/results/:enrolment (assessor)')
    asr.get_by_role('button', name='Reassess Build a one-brick wall to the line').click(); asr.get_by_role('dialog').get_by_text('Criteria').wait_for(); audit(asr, 'decision dialog (assessor)')
    ln.goto(f'http://tym.platform.test:{PORT}/my-results'); ln.get_by_text('Two practice walls').wait_for(); ln.get_by_text('waiting for a decision').wait_for(); audit(ln, '/my-results (learner, phone)')
    # A moderator: the queue, a sampled decision, an appeal, and the cohort's moderation page.
    md = b.new_context().new_page()
    md.goto(f'http://tym.platform.test:{PORT}/sign-in'); md.get_by_label('Email').wait_for()
    md.get_by_label('Email').fill('zanele@demo.test'); md.get_by_label('Password').fill('demo passphrase 2026')
    md.get_by_role('button', name='Sign in', exact=True).click(); md.get_by_text('Good ').first.wait_for()
    md.goto(f'http://tym.platform.test:{PORT}/moderation'); md.get_by_text('To moderate (2)').wait_for(); audit(md, '/moderation (moderator)')
    md.get_by_role('button', name='Moderate Bongani Khumalo, Materials test').click(); md.get_by_role('dialog').get_by_text('Score 74%').wait_for(); audit(md, 'sample dialog (moderator)')
    md.keyboard.press('Escape')
    md.get_by_role('button', name='Decide appeal from Bongani Khumalo').click(); md.get_by_label('Your reasons (sent to the learner)').wait_for(); audit(md, 'appeal dialog (moderator)')
    md.keyboard.press('Escape')
    md.get_by_role('link', name='Bricklayer, December 2026: moderation and reports').click(); md.get_by_text('Sample meets the policy').first.wait_for(); audit(md, '/cohorts/:id/moderation (moderator)')
    b.close()

sys.exit(1 if failed else 0)
