/* Sample data for every role's web journey. All names, numbers and organisations are fictitious sample data. */
window.ROLE_GROUPS = ['Platform', 'Training provider', 'Delivery & assessment', 'Learner', 'Workplace & employers', 'Assessment centres & oversight', 'Jobs, enterprise & trust'];

var TYM = { tenantLabel: 'Training provider', tenant: 'Training Young Minds', domain: 'learn.trainingyoungminds.co.za', tenantIcon: 'globe' };
function T(o) { var r = {}; for (var k in TYM) r[k] = TYM[k]; for (var j in o) r[j] = o[j]; return r; }

window.ROLES = [
/* ============================== 1. SUPER ADMIN ============================== */
{
  id: 'admin', name: 'Super Admin', tag: 'TYM × MCS JV platform owner', group: 'Platform', icon: 'shield', context: 'Console',
  tenantLabel: 'Platform', tenant: 'TYM × MCS Joint Venture', domain: 'console.platform.co.za', tenantIcon: 'lock',
  persona: 'Naledi Khumalo', personaTitle: 'Platform operations',
  screens: [
    { id: 'overview', nav: 'Overview', icon: 'home', title: 'Platform overview', sub: 'Every tenant, cell and gateway at a glance.', actions: [{ label: 'Approve sign-ups', to: 'tenants', primary: true, icon: 'check' }],
      blocks: [
        { type: 'kpis', items: [{ label: 'Active training providers', value: '142', delta: '▲ 18 this month', tone: 'up', icon: 'building', dark: true }, { label: 'Active learners', value: '18,460', delta: '▲ 9.4%', tone: 'up', icon: 'users' }, { label: 'Monthly recurring revenue', value: 'R1.24m', delta: '▲ 6.1%', tone: 'up', icon: 'coin' }, { label: 'Uptime (30 days)', value: '99.98%', delta: 'p95 API 142 ms', icon: 'shield' }] },
        { type: 'table', span: 8, title: 'Cells', note: 'Each cell is an isolated stack', cols: ['Cell', 'Region', 'TPs', 'Learners', 'p95', 'Load', 'Status'], rows: [
          ['<b>Cell 1</b>', 'Cape Town', '100', '11,204', '138 ms', bar(72, 'warn'), pill('Healthy', 'ok')],
          ['<b>Cell 2</b>', 'Cape Town', '38', '5,112', '121 ms', bar(41, 'ok'), pill('Healthy', 'ok')],
          ['<b>Dedicated · CETA pilot</b>', 'Johannesburg', '1', '1,860', '96 ms', bar(28, 'ok'), pill('Healthy', 'ok')],
          ['<b>Cell 3</b>', 'Cape Town', '3', '284', '88 ms', bar(6, 'ok'), pill('Filling', 'info')]] },
        { type: 'list', span: 4, title: 'Needs attention', items: [
          { icon: 'building', title: '6 TP sign-ups awaiting verification', sub: 'Accreditation letters uploaded', meta: btn('Review', 'admin-tenants'), tone: 'warn' },
          { icon: 'alert', title: 'New rejection pattern', sub: 'QCTO file: date format on 14 files', meta: btn('Create rule', 'admin-rules'), tone: 'bad' },
          { icon: 'book', title: '4 courses in expert review', sub: 'Bricklayer, Plumber, Plant operator', meta: btn('Open', 'admin-content') }] }
      ] },
    { id: 'tenants', nav: 'Tenants', icon: 'building', title: 'Training providers', sub: 'Approve, place in a cell, assign a package, or suspend.', actions: [{ label: 'Export', icon: 'upload' }, { label: 'Invite a TP', primary: true, icon: 'plus' }],
      blocks: [
        { type: 'table', title: 'All tenants', note: '142 active · 6 pending', cols: ['Training provider', 'Package', 'Centres', 'Active learners', 'Domain', 'Cell', 'Status', ''], rows: [
          [who('Training Young Minds', 'Launch partner'), pill('Enterprise', 'gold'), '4', '612', 'learn.trainingyoungminds.co.za', 'Cell 1', pill('Active', 'ok'), btn('Open')],
          [who('Ubuntu Build Academy', 'Gauteng'), pill('Growth', 'info'), '2', '238', 'ubuntubuild.platform.co.za', 'Cell 1', pill('Active', 'ok'), btn('Open')],
          [who('Coastal Trades Institute', 'KwaZulu-Natal'), pill('Growth', 'info'), '3', '301', 'learn.coastaltrades.co.za', 'Cell 2', pill('Active', 'ok'), btn('Open')],
          [who('Karoo Skills Centre', 'Northern Cape'), pill('Starter', 'muted'), '1', '—', 'karooskills.platform.co.za', '—', pill('Verifying', 'warn'), btn('Approve', null, 'primary')],
          [who('Mpumalanga Plant School', 'Mpumalanga'), pill('Starter', 'muted'), '1', '—', 'mpplant.platform.co.za', '—', pill('Verifying', 'warn'), btn('Approve', null, 'primary')]] }
      ] },
    { id: 'packages', nav: 'Packages', icon: 'layers', title: 'Packages & pricing', sub: 'Build the packages TPs can buy.', actions: [{ label: 'New package', primary: true, icon: 'plus' }],
      blocks: [
        { type: 'callout', tone: 'warn', icon: 'alert', title: 'Example packages for demonstration only', text: 'Prices and limits shown are examples and can be changed. ZAR, excluding VAT.' },
        { type: 'tiles', items: [
          { icon: 'building', title: 'Starter', price: 'R1,500<small> /month</small>', sub: '1 centre · 3 seats · R45 per active learner · 300 SMS · 50 AI credits per learner', meta: '<span>Subdomain</span>' + pill('41 TPs', 'muted') },
          { icon: 'chart', title: 'Growth', price: 'R4,500<small> /month</small>', sub: 'Up to 5 centres · 10 seats · R35 per active learner · 1,500 SMS · own domain', meta: '<span>Most popular</span>' + pill('86 TPs', 'gold'), feature: true },
          { icon: 'shield', title: 'Enterprise', price: 'R12,000<small> /month</small>', sub: 'Unlimited centres · R25 per active learner · white-label app · dedicated database', meta: '<span>Custom</span>' + pill('15 TPs', 'muted') }] },
        { type: 'form', title: 'Edit package · Growth', fields: [
          { label: 'Platform fee (ZAR / month)', value: '4,500' }, { label: 'Price per active learner', value: 'R35' },
          { label: 'Centres included', value: '5 (extra R750 / month)' }, { label: 'Admin seats', value: '10' },
          { label: 'SMS / WhatsApp per month', value: '1,500' }, { label: 'AI credits', value: '80 per learner + 2,000 staff' },
          { label: 'Custom domain', type: 'toggle', value: 'Allowed' }, { label: 'White-label mobile app', type: 'toggle', value: 'Not included', off: true }], submit: 'Save package', secondary: 'Preview' }
      ] },
    { id: 'gateways', nav: 'Gateways & AI', icon: 'route', title: 'Gateways & AI models', sub: 'Providers, failover, cost and margin.', actions: [{ label: 'Add provider', primary: true, icon: 'plus' }],
      blocks: [
        { type: 'table', span: 7, title: 'Messaging & payments', cols: ['Service', 'Primary', 'Backup', 'Cost / unit', 'Margin', 'Status'], rows: [
          ['<b>Email</b>', 'Amazon SES', 'SendGrid', 'R0.002', '—', pill('Healthy', 'ok')],
          ['<b>SMS</b>', 'Clickatell', 'SMSPortal', 'R0.19', '42%', pill('Healthy', 'ok')],
          ['<b>WhatsApp</b>', 'Meta Cloud API', '—', 'R0.31', '35%', pill('Healthy', 'ok')],
          ['<b>Cards</b>', 'Paystack', 'Peach Payments', '2.9%', '—', pill('Healthy', 'ok')],
          ['<b>Instant EFT</b>', 'Ozow', '—', '1.5%', '—', pill('Degraded', 'warn')],
          ['<b>Debit orders</b>', 'Netcash', '—', 'R4.50', '—', pill('Healthy', 'ok')]] },
        { type: 'table', span: 5, title: 'AI models by task', cols: ['Task', 'Provider', 'Credits'], rows: [
          ['Learner AI coach', 'Provider A · large', '1 / reply'], ['Pack checks', 'Provider B · fast', '0.5'], ['Mock interviews', 'Provider A · voice', '4 / min'], ['CV builder', 'Provider B · fast', '2 / CV']] }
      ] },
    { id: 'content', nav: 'Master content', icon: 'book', title: 'Master content studio', sub: 'Author, review and publish curriculum-mapped content.', actions: [{ label: 'New course', primary: true, icon: 'plus' }],
      blocks: [
        { type: 'kanban', title: 'Content pipeline', columns: [
          { name: 'Drafting', cards: [{ title: 'Carpenter · Module 4', sub: 'Roof structures', tag: pill('Knowledge', 'info') }, { title: 'Welding simulator', sub: '3D scenario script', tag: pill('Simulator', 'violet') }] },
          { name: 'Expert review', cards: [{ title: 'Bricklayer · Module 2', sub: 'Reviewer: T. Mokoena', tag: pill('2 comments', 'warn') }, { title: 'Plumber question bank', sub: '240 questions', tag: pill('QAS mapped', 'ok') }] },
          { name: 'Ready', cards: [{ title: 'Plant operator · Pre-start', sub: '3D + VR build 1.2', tag: pill('Simulator', 'violet') }] },
          { name: 'Published', cards: [{ title: 'Bricklayer · Module 1', sub: 'v3 · 312 learners', tag: pill('Live', 'ok') }, { title: 'Workplace safety basics', sub: 'v5 · all trades', tag: pill('Live', 'ok') }] }] }
      ] },
    { id: 'rules', nav: 'Rule library', icon: 'filter', title: 'Compliance rule library', sub: 'The checks that stop rejections before submission.', actions: [{ label: 'New rule', primary: true, icon: 'plus' }],
      blocks: [
        { type: 'callout', tone: 'dark', icon: 'spark', title: 'New rejection pattern detected', text: '14 QCTO files were returned this week for the same date format. Turn it into a rule so every TP is protected.', action: 'Create rule from pattern' },
        { type: 'table', title: 'Active rules', cols: ['Rule', 'Applies to', 'Source', 'Version', 'Caught (30 days)'], rows: [
          ['Certified copy older than 6 months', 'Funding application, claims', 'SETA DG requirement', 'v2', '<b class="mono">318</b>'],
          ['Unsigned learner agreement', 'Enrolment, tranche 1', 'Funding agreement', 'v1', '<b class="mono">204</b>'],
          ['ID number checksum fails', 'All learner data', 'SA ID format', 'v1', '<b class="mono">97</b>'],
          ['Enrolment file later than 21 days', 'QCTO enrolment file', 'QCTO data rules', 'v3', '<b class="mono">41</b>'],
          ['Attendance below stipend days', 'Progress tranches', 'Funding agreement', 'v1', '<b class="mono">63</b>']] }
      ] }
  ]
},
/* ============================== 2. TP OWNER ============================== */
T({
  id: 'owner', name: 'TP owner / head office', tag: 'All centres, growth and billing', group: 'Training provider', icon: 'building', context: 'Head office',
  persona: 'Sekal M.', personaTitle: 'Owner · Training Young Minds',
  screens: [
    { id: 'dashboard', nav: 'Group dashboard', icon: 'home', title: 'Good morning, Sekal', sub: 'Compliance, cash flow and learner progress across all four centres.', actions: [{ label: 'Download report', icon: 'upload' }, { label: 'Add centre', to: 'centres', primary: true, icon: 'plus' }],
      blocks: [
        { type: 'kpis', items: [{ label: 'Active learners', value: '612', delta: '▲ 48 this intake', tone: 'up', icon: 'users' }, { label: 'Tranches ready to claim', value: 'R2.8m', delta: '9 packs green', tone: 'up', icon: 'coin', dark: true }, { label: 'Rand at risk', value: 'R184k', delta: '7 open rejections', tone: 'down', icon: 'alert' }, { label: 'Mock EISA pass rate', value: '81%', delta: '▲ 6 pts vs last cohort', tone: 'up', icon: 'trophy' }] },
        { type: 'table', span: 8, title: 'Centres', cols: ['Centre', 'Accreditation', 'Expiry', 'Learners', 'Pack readiness', 'Rejections'], rows: [
          ['<b>Midrand</b>', 'Bricklayer, Plumber', '2029-03', '248', bar(92, 'ok'), pill('1 open', 'warn')],
          ['<b>Soweto</b>', 'Bricklayer, Carpenter', '2027-11', '176', bar(78, 'warn'), pill('4 open', 'bad')],
          ['<b>Polokwane</b>', 'Plant operator', '2028-06', '121', bar(95, 'ok'), pill('None', 'ok')],
          ['<b>Rustenburg</b>', 'Plant operator, Plumber', '2027-04', '67', bar(64, 'warn'), pill('2 open', 'warn')]] },
        { type: 'bars', span: 4, title: 'Mock EISA pass rate by centre', unit: '%', max: 100, data: [['Midrand', 86, 'ok'], ['Polokwane', 84, 'ok'], ['Soweto', 77, 'warn'], ['Rustenburg', 71, 'warn']] },
        { type: 'callout', tone: 'warn', icon: 'clock', title: 'Rustenburg accreditation expires in 5 months', text: 'The renewal checklist is 60% complete. Site-visit pack and facilitator CVs still needed.', action: 'Open renewal checklist' }
      ] },
    { id: 'centres', nav: 'Centres', icon: 'building', title: 'Centres', sub: 'Each centre carries its own QCTO accreditation number and scope.', actions: [{ label: 'Add centre', primary: true, icon: 'plus' }],
      blocks: [
        { type: 'tiles', items: [
          { icon: 'building', title: 'Midrand', badge: pill('Accredited', 'ok'), sub: 'Accreditation no. SDP-0001 (sample) · Bricklayer, Plumber', meta: '<span>Manager: Thandi Ndlovu</span><span>248 learners</span>', to: 'centre-command' },
          { icon: 'building', title: 'Soweto', badge: pill('Accredited', 'ok'), sub: 'Accreditation no. SDP-0002 (sample) · Bricklayer, Carpenter', meta: '<span>Manager: Bongani Zulu</span><span>176 learners</span>' },
          { icon: 'building', title: 'Polokwane', badge: pill('Accredited', 'ok'), sub: 'Accreditation no. SDP-0003 (sample) · Plant operator', meta: '<span>Manager: Mpho Sebola</span><span>121 learners</span>' },
          { icon: 'building', title: 'Rustenburg', badge: pill('Renew soon', 'warn'), sub: 'Accreditation no. SDP-0004 (sample) · Plant operator, Plumber', meta: '<span>Manager: Kagiso Molefe</span><span>67 learners</span>' }] }
      ] },
    { id: 'brand', nav: 'Brand & domain', icon: 'globe', title: 'Brand & domain', sub: 'Your learners see your name, colours and web address.', actions: [{ label: 'Preview as learner', icon: 'eye' }],
      blocks: [
        { type: 'form', span: 7, title: 'Branding', fields: [
          { label: 'Logo', type: 'drop', value: 'tym-logo.svg uploaded', full: true },
          { label: 'Primary colour', value: '#0E3B5C  ■' }, { label: 'Accent colour', value: '#E2A43B  ■' },
          { label: 'Custom domain', value: 'learn.trainingyoungminds.co.za', ok: true, icon: 'globe', hint: 'DNS record verified · SSL certificate active, renews automatically', full: true },
          { label: 'Email sender', value: 'learning@trainingyoungminds.co.za', ok: true, icon: 'mail', hint: 'SPF and DKIM verified', full: true },
          { label: 'Mobile app', type: 'seg', options: ['Shared app (TP code)', 'White-label app'], on: 0, full: true }], submit: 'Save branding' },
        { type: 'html', span: 5, title: 'Learner sign-in preview', html: '<div style="border-radius:16px;overflow:hidden;border:1px solid var(--line)"><div style="background:#0E3B5C;color:#fff;padding:22px"><div style="font-family:var(--serif);font-size:20px">Training Young Minds</div><div style="opacity:.7;font-size:12.5px">learn.trainingyoungminds.co.za</div></div><div style="padding:20px;display:grid;gap:10px"><div class="input">' + icon('user') + '<span class="muted">SA ID or passport number</span></div><div class="input">' + icon('lock') + '<span class="muted">One-time PIN</span></div><a class="btn" style="background:#E2A43B;border-color:#E2A43B;justify-content:center;font-weight:600">Sign in</a></div></div>' }
      ] },
    { id: 'billing', nav: 'Subscription & usage', icon: 'coin', title: 'Subscription & usage', sub: 'Enterprise package (example pricing).', actions: [{ label: 'Top up credits', gold: true, icon: 'plus' }],
      blocks: [
        { type: 'kpis', items: [{ label: 'Active learners this month', value: '612', icon: 'users' }, { label: 'SMS / WhatsApp used', value: '4,120', delta: 'of custom allowance', icon: 'chat' }, { label: 'AI credits used', value: '38,900', delta: '62% of pool', icon: 'spark' }, { label: 'Next invoice', value: 'R27,300', delta: 'Debit order · 1 Dec', icon: 'coin', dark: true }] },
        { type: 'table', title: 'Invoices', cols: ['Invoice', 'Period', 'Platform fee', 'Learners', 'Add-ons', 'Total', 'Status'], rows: [
          ['INV-1043', 'Oct 2027', 'R12,000', 'R15,050', 'R0', '<b>R27,050</b>', pill('Paid', 'ok')],
          ['INV-0987', 'Sep 2027', 'R12,000', 'R14,300', 'R1,200 AI top-up', '<b>R27,500</b>', pill('Paid', 'ok')]] }
      ] },
    { id: 'users', nav: 'Users & roles', icon: 'users', title: 'Users & roles', sub: 'Invite staff; credentials are checked before they can assess or moderate.', actions: [{ label: 'Invite user', primary: true, icon: 'plus' }],
      blocks: [
        { type: 'table', title: 'Staff', cols: ['Person', 'Role', 'Centre', 'Credentials', 'Last active'], rows: [
          [who('Thandi Ndlovu', 'thandi@tym.co.za'), 'Centre manager', 'Midrand', pill('n/a', 'muted'), 'Today'],
          [who('Pieter van Wyk', 'pieter@tym.co.za'), 'Assessor', 'All centres', pill('Registered · valid to 2029', 'ok'), 'Today'],
          [who('Ayanda Mthembu', 'ayanda@tym.co.za'), 'Facilitator', 'Soweto', pill('Verified', 'ok'), 'Yesterday'],
          [who('Johan Smit', 'johan@tym.co.za'), 'Moderator', 'All centres', pill('Expires in 30 days', 'warn'), '3 days ago']] }
      ] }
  ]
}),
/* ============================== 3. CENTRE MANAGER ============================== */
T({
  id: 'centre', name: 'Centre manager & admin', tag: 'Enrol, submit, fix, claim', group: 'Training provider', icon: 'grid', context: 'Midrand centre',
  persona: 'Thandi Ndlovu', personaTitle: 'Centre manager · Midrand',
  screens: [
    { id: 'command', nav: 'Command centre', icon: 'home', title: 'Midrand command centre', sub: '248 learners across 6 cohorts. Here is what needs you today.', actions: [{ label: 'Open rejection inbox', to: 'rejections', icon: 'inbox' }, { label: 'Build claim pack', to: 'claims', primary: true, icon: 'coin' }],
      blocks: [
        { type: 'steps', title: 'Learners by stage', steps: [{ name: 'Enrolled', sub: '248', state: 'done' }, { name: 'Knowledge & practical', sub: '131', state: 'done' }, { name: 'Workplace', sub: '84', state: 'active' }, { name: 'Assessment', sub: '22' }, { name: 'SoR issued', sub: '11' }, { name: 'EISA', sub: '0' }] },
        { type: 'kpis', items: [{ label: 'Packs ready (green)', value: '5', delta: 'R1.1m claimable', tone: 'up', icon: 'check' }, { label: 'Open rejections', value: '1', delta: 'R26k at risk · SLA 2 days', tone: 'down', icon: 'alert' }, { label: 'Learners at risk', value: '9', delta: 'Attendance or mock scores', tone: 'down', icon: 'users' }, { label: 'EISA closing date', value: '18 days', delta: 'Bricklayer · 34 eligible', icon: 'calendar', dark: true }] },
        { type: 'list', span: 6, title: 'Today', items: [
          { icon: 'file', title: 'Enrolment file due to QCTO', sub: 'Cohort BL-2027-03 · day 17 of 21', meta: btn('Check & export', 'centre-claims'), tone: 'warn' },
          { icon: 'users', title: '6 applications to review', sub: 'Plumber intake · documents uploaded', meta: btn('Review', 'centre-enrol') },
          { icon: 'pin', title: '12 logbook entries waiting for supervisor', sub: 'Site: Waterfall Estate', meta: btn('Nudge') }] },
        { type: 'bars', span: 6, title: 'Pack readiness by cohort', unit: '%', max: 100, data: [['BL-2027-01', 100, 'ok'], ['BL-2027-03', 94, 'ok'], ['PL-2027-02', 88, 'ok'], ['PL-2027-04', 71, 'warn'], ['BL-2027-05', 58, 'bad']] }
      ] },
    { id: 'cohorts', nav: 'Cohorts', icon: 'users', title: 'Cohorts', sub: 'Built from the QCTO curriculum: knowledge, practical and workplace modules.', actions: [{ label: 'New cohort', primary: true, icon: 'plus' }],
      blocks: [
        { type: 'table', title: 'Active cohorts', cols: ['Cohort', 'Qualification', 'Track', 'Intake', 'Learners', 'Facilitator', 'Progress'], rows: [
          ['<b>BL-2027-01</b>', 'Bricklayer (occupational)', pill('Occupational', 'info'), 'Jan 2027', '42', 'Ayanda Mthembu', bar(86, 'ok')],
          ['<b>BL-2027-03</b>', 'Bricklayer (occupational)', pill('Occupational', 'info'), 'Mar 2027', '45', 'Ayanda Mthembu', bar(61)],
          ['<b>PL-2027-02</b>', 'Plumber (occupational)', pill('Occupational', 'info'), 'Feb 2027', '38', 'Sizwe Dube', bar(55)],
          ['<b>BC-LEG-2026</b>', 'Building & civil (legacy)', pill('Teach-out · ends Dec 2027', 'warn'), 'Jun 2026', '29', 'Lerato Dlamini', bar(91, 'ok')]] }
      ] },
    { id: 'enrol', nav: 'Enrolment', icon: 'user', title: 'Enrolment', sub: 'Every pack is checked before it can be enrolled. Fix once, use everywhere.', actions: [{ label: 'Invite applicants', icon: 'send' }, { label: 'Enrol ready learners', primary: true, icon: 'check' }],
      blocks: [
        { type: 'table', title: 'Applicants · Plumber intake', cols: ['Applicant', 'ID check', 'Certified copies', 'Agreement', 'Consent', 'Readiness', ''], rows: [
          [who('Sipho Nkosi', 'Applied 2 days ago'), pill('Valid', 'ok'), pill('Valid to May', 'ok'), pill('Signed', 'ok'), pill('Given', 'ok'), light('g'), btn('Enrol', null, 'primary')],
          [who('Lerato Mokoena', 'Applied 3 days ago'), pill('Valid', 'ok'), pill('Expires in 9 days', 'warn'), pill('Signed', 'ok'), pill('Given', 'ok'), light('a'), btn('Request new copy')],
          [who('Kabelo Maseko', 'Applied today'), pill('Checksum fails', 'bad'), pill('Missing', 'bad'), pill('Not sent', 'muted'), pill('Pending', 'muted'), light('r'), btn('Ask to fix')],
          [who('Nomsa Khoza', 'Applied 1 day ago'), pill('Valid', 'ok'), pill('Valid to Aug', 'ok'), pill('Awaiting signature', 'warn'), pill('Given', 'ok'), light('a'), btn('Resend')]] }
      ] },
    { id: 'claims', nav: 'Submissions & claims', icon: 'coin', title: 'Submissions & claims', sub: 'Every pack is checked against CETA, QCTO and assessment-centre rules before it leaves.', actions: [{ label: 'New pack', icon: 'plus' }],
      blocks: [
        { type: 'table', title: 'Packs', cols: ['Pack', 'Type', 'Learners', 'Readiness', 'Value', 'Due', ''], rows: [
          ['<b>Tranche 2 · BL-2027-01</b>', 'CETA claim', '42', light('g'), '<b>R386,400</b>', '30 Nov', btn('Submit', null, 'primary')],
          ['<b>Enrolment file · BL-2027-03</b>', 'QCTO file', '45', light('g'), '—', '4 days', btn('Export file', null, 'primary')],
          ['<b>Tranche 1 · PL-2027-04</b>', 'CETA claim', '36', light('a'), '<b>R298,000</b>', '12 Dec', btn('See 3 issues', 'centre-rejections')],
          ['<b>EISA registration · Bricklayer</b>', 'Assessment centre', '34', light('r'), '—', '18 days', btn('Fix 2 blockers', 'centre-eisa')]] },
        { type: 'callout', tone: 'ok', icon: 'shield', title: 'All 42 learners in Tranche 2 pass every check', text: 'Attendance matches stipends paid, logbooks signed, certified copies valid. Ready to submit.', action: 'Submit to CETA' }
      ] },
    { id: 'rejections', nav: 'Rejection inbox', icon: 'inbox', title: 'Rejection inbox', sub: 'Each rejection is pinned to the learner, document and rule — and routed to whoever can fix it.', actions: [{ label: 'Log a rejection', icon: 'plus' }],
      blocks: [
        { type: 'kpis', items: [{ label: 'Open', value: '3', icon: 'inbox' }, { label: 'Rand at risk', value: 'R26k', tone: 'down', icon: 'coin', dark: true }, { label: 'Avg. time to fix', value: '1.6 days', delta: 'was 19 days before platform', tone: 'up', icon: 'clock' }] },
        { type: 'table', title: 'Open rejections', cols: ['Reason', 'Learner', 'Document', 'Fixer', 'SLA', 'Status', ''], rows: [
          ['Certified ID copy older than 6 months', who('Lerato Mokoena'), 'ID copy', 'Learner (WhatsApp sent)', pill('2 days', 'warn'), pill('Waiting on learner', 'info'), btn('View')],
          ['Logbook week 6 not signed', who('Thabo Mabena'), 'Logbook', 'Site supervisor', pill('1 day', 'bad'), pill('Escalated', 'bad'), btn('Escalate')],
          ['Bank confirmation letter unclear', who('Zanele Cele'), 'Stipend proof', 'Centre admin', pill('3 days', 'ok'), pill('Fixed · re-checking', 'ok'), btn('Resubmit', null, 'primary')]] },
        { type: 'callout', tone: 'dark', icon: 'spark', title: 'AI assistant drafted the fix for 2 rejections', text: 'Review the messages to the learner and supervisor before they are sent.', action: 'Review drafts' }
      ] },
    { id: 'eisa', nav: 'SoR & EISA', icon: 'medal', title: 'Statement of Results & EISA', sub: 'Learners are registered only when every module is complete — before the closing date.', actions: [{ label: 'Issue SoRs', primary: true, icon: 'file' }],
      blocks: [
        { type: 'callout', tone: 'warn', icon: 'calendar', title: 'Bricklayer EISA registration closes in 18 days', text: '34 learners eligible, 2 blocked by missing workplace sign-offs. No late registrations are accepted.', action: 'Fix blockers' },
        { type: 'table', title: 'Eligibility · Bricklayer', cols: ['Learner', 'Knowledge', 'Practical', 'Workplace', 'Readiness score', 'SoR', ''], rows: [
          [who('Thabo Mabena'), pill('Complete', 'ok'), pill('Complete', 'ok'), pill('1 module open', 'bad'), ring(74), pill('Blocked', 'bad'), btn('Chase supervisor')],
          [who('Ayanda Zwane'), pill('Complete', 'ok'), pill('Complete', 'ok'), pill('Complete', 'ok'), ring(88, 'ok'), pill('Issued', 'ok'), btn('Register', null, 'primary')],
          [who('Musa Ngcobo'), pill('Complete', 'ok'), pill('Complete', 'ok'), pill('Complete', 'ok'), ring(92, 'ok'), pill('Issued', 'ok'), btn('Register', null, 'primary')]] }
      ] }
  ]
}),
/* ============================== 4. FACILITATOR ============================== */
T({
  id: 'facilitator', name: 'Facilitator', tag: 'Deliver sessions, track progress', group: 'Delivery & assessment', icon: 'book', context: 'Facilitator', persona: 'Ayanda Mthembu', personaTitle: 'Facilitator · Soweto & Midrand',
  screens: [
    { id: 'today', nav: 'Today', icon: 'home', title: 'Today, 3 sessions', sub: 'Attendance is captured by QR and locked when the session ends.', actions: [{ label: 'Start session', to: 'attendance', primary: true, icon: 'play' }],
      blocks: [
        { type: 'timeline', span: 5, title: 'Schedule', items: [{ time: '08:00', title: 'BL-2027-03 · Setting out & levelling', sub: 'Workshop B · 45 learners', now: true }, { time: '11:30', title: 'BL-2027-01 · Mock EISA review', sub: 'Room 2 · 42 learners' }, { time: '14:00', title: 'PL-2027-02 · Drainage practical', sub: 'Yard · 38 learners' }] },
        { type: 'list', span: 7, title: 'Learners who need you', items: [
          { icon: 'alert', tone: 'bad', title: 'Kabelo Maseko · 3 absences this week', sub: 'Stipend at risk', meta: btn('Message') },
          { icon: 'chart', tone: 'warn', title: 'Nomsa Khoza · mock score 48%', sub: 'Weak on Module 2: mortar mixes', meta: btn('Assign practice') },
          { icon: 'cube', tone: 'info', title: '6 learners have not tried the 3D simulator', sub: 'Plant pre-start checks', meta: btn('Nudge all') }] }
      ] },
    { id: 'attendance', nav: 'Attendance', icon: 'qr', title: 'Session · Setting out & levelling', sub: 'Learners scan the session QR with the app. Late arrivals are marked automatically.', actions: [{ label: 'End & lock session', primary: true, icon: 'lock' }],
      blocks: [
        { type: 'html', span: 4, title: 'Session QR', html: '<div style="display:grid;place-items:center;padding:18px;background:var(--ink);border-radius:14px;color:#fff"><div style="width:180px;height:180px;background:repeating-conic-gradient(#fff 0 25%, #0d1526 0 50%) 0 0/24px 24px;border:10px solid #fff;border-radius:8px"></div><div style="margin-top:12px;font-size:12.5px;opacity:.7">Refreshes every 30 seconds · GPS check on</div></div>' },
        { type: 'table', span: 8, title: '41 of 45 present', cols: ['Learner', 'Scanned', 'Location', 'Status'], rows: [
          [who('Ayanda Zwane'), '07:54', pill('On site', 'ok'), pill('Present', 'ok')], [who('Musa Ngcobo'), '07:58', pill('On site', 'ok'), pill('Present', 'ok')],
          [who('Sipho Nkosi'), '08:12', pill('On site', 'ok'), pill('Late', 'warn')], [who('Kabelo Maseko'), '—', '—', pill('Absent', 'bad')]] }
      ] },
    { id: 'tasks', nav: 'Formative tasks', icon: 'file', title: 'Formative tasks', sub: 'Set, mark and track practice work per module.', actions: [{ label: 'New task', primary: true, icon: 'plus' }],
      blocks: [{ type: 'table', title: 'Open tasks', cols: ['Task', 'Module', 'Cohort', 'Submitted', 'Marked', 'Due'], rows: [
        ['<b>Lay a 1m English bond wall</b>', 'Practical 2', 'BL-2027-03', '38 / 45', bar(70), 'Fri'], ['<b>Mortar mix quiz</b>', 'Knowledge 2', 'BL-2027-03', '45 / 45', bar(100, 'ok'), 'Done'], ['<b>Fall and gradient calc</b>', 'Knowledge 3', 'PL-2027-02', '20 / 38', bar(30, 'warn'), 'Mon']] }] },
    { id: 'helper', nav: 'AI lesson helper', icon: 'spark', title: 'AI lesson helper', sub: 'Lesson plans and quizzes built from the curriculum you teach.', actions: [],
      blocks: [{ type: 'chat', title: 'Bricklayer · Module 3', messages: [['me', 'Create a 45-minute lesson on setting out a building with profiles, and 5 quiz questions.'], ['ai', 'Here is a plan mapped to Knowledge Module 3:<br>1. 10 min — why setting out matters (site example)<br>2. 15 min — profiles and lines demo<br>3. 15 min — pairs practise squaring with 3-4-5<br>4. 5 min — quiz<br><br>5 questions are ready in your task list. Want a version in isiZulu for the hand-out?']], placeholder: 'Ask for a lesson plan, quiz or explanation…' }] }
  ]
}),
/* ============================== 5. ASSESSOR ============================== */
T({
  id: 'assessor', name: 'Assessor', tag: 'Assess portfolios of evidence', group: 'Delivery & assessment', icon: 'check', context: 'Assessor', persona: 'Pieter van Wyk', personaTitle: 'Registered assessor',
  screens: [
    { id: 'queue', nav: 'Assessment queue', icon: 'inbox', title: 'Assessment queue', sub: '18 portfolios waiting. The AI pre-check flags gaps; you make every decision.', actions: [{ label: 'Start next', to: 'poe', primary: true, icon: 'play' }],
      blocks: [{ type: 'table', title: 'Waiting for you', cols: ['Learner', 'Module', 'Cohort', 'AI pre-check', 'Waiting', ''], rows: [
        [who('Ayanda Zwane'), 'Workplace 4 · Walls & openings', 'BL-2027-01', pill('Complete', 'ok'), '1 day', btn('Assess', 'assessor-poe', 'primary')],
        [who('Thabo Mabena'), 'Workplace 4 · Walls & openings', 'BL-2027-01', pill('2 gaps found', 'warn'), '2 days', btn('Assess', 'assessor-poe')],
        [who('Zanele Cele'), 'Practical 3 · Drainage', 'PL-2027-02', pill('Complete', 'ok'), '3 days', btn('Assess', 'assessor-poe')]] }] },
    { id: 'poe', nav: 'Portfolio review', icon: 'file', title: 'Ayanda Zwane · Walls & openings', sub: 'Workplace module evidence, signed off on site by the supervisor.', actions: [{ label: 'Record decision', to: 'decision', primary: true, icon: 'check' }],
      blocks: [
        { type: 'evidence', span: 7, title: 'Evidence (12 items)', items: [{ title: 'Window opening', sub: 'Waterfall Estate · 14 Oct' }, { title: 'Lintel placement', sub: 'GPS verified · 15 Oct' }, { title: 'Supervisor sign-off', sub: 'J. Botha · e-signed', icon: 'check' }, { title: '3D simulator result', sub: 'Score 91%', icon: 'cube' }] },
        { type: 'form', span: 5, title: 'Criteria checklist', fields: [
          { label: 'Sets out openings to drawing', type: 'toggle', value: 'Met', full: true }, { label: 'Installs lintels to specification', type: 'toggle', value: 'Met', full: true },
          { label: 'Works safely at height', type: 'toggle', value: 'Met', full: true }, { label: 'Cleans and maintains tools', type: 'toggle', value: 'Not yet seen', off: true, full: true }] }
      ] },
    { id: 'decision', nav: 'Decision', icon: 'check', title: 'Record decision', sub: 'Signed decisions go to moderation. Learners see clear feedback.', actions: [],
      blocks: [{ type: 'form', title: 'Decision · Workplace 4', fields: [
        { label: 'Outcome', type: 'seg', options: ['Competent', 'Not yet competent'], on: 0, full: true },
        { label: 'Feedback to learner', type: 'area', value: 'Strong work on openings and lintels. Next time, show tool cleaning at the end of the shift.', full: true },
        { label: 'Assessor', value: 'Pieter van Wyk · registration valid to 2029', ok: true, icon: 'shield', full: true }], submit: 'Sign and send to moderation', secondary: 'Save draft' }] }
  ]
}),
/* ============================== 6. MODERATOR ============================== */
T({
  id: 'moderator', name: 'Moderator', tag: 'Check decisions are fair and valid', group: 'Delivery & assessment', icon: 'eye', context: 'Moderator', persona: 'Johan Smit', personaTitle: 'Internal moderator',
  screens: [
    { id: 'sample', nav: 'Sample', icon: 'filter', title: 'Moderation sample', sub: 'Suggested sample: 25% per assessor, weighted to new assessors and borderline scores.', actions: [{ label: 'Start moderation', to: 'review', primary: true, icon: 'play' }],
      blocks: [{ type: 'table', title: 'BL-2027-01 · Workplace 4', cols: ['Learner', 'Assessor', 'Decision', 'Why sampled', ''], rows: [
        [who('Ayanda Zwane'), 'P. van Wyk', pill('Competent', 'ok'), 'Random', btn('Review', 'moderator-review')], [who('Thabo Mabena'), 'P. van Wyk', pill('Competent', 'ok'), 'AI flagged 2 gaps', btn('Review', 'moderator-review')], [who('Musa Ngcobo'), 'L. Dlamini', pill('Not yet competent', 'warn'), 'New assessor', btn('Review', 'moderator-review')]] }] },
    { id: 'review', nav: 'Review', icon: 'eye', title: 'Thabo Mabena · Walls & openings', sub: 'Compare the evidence with the assessor decision.', actions: [{ label: 'Endorse', primary: true, icon: 'check' }, { label: 'Return to assessor', icon: 'back' }],
      blocks: [
        { type: 'evidence', span: 7, title: 'Evidence', items: [{ title: 'Opening, east wall', sub: '16 Oct' }, { title: 'Lintel photo', sub: 'Blurry · re-take?' }, { title: 'Supervisor sign-off', sub: 'Week 6 missing', icon: 'alert' }] },
        { type: 'list', span: 5, title: 'Assessor decision', items: [{ icon: 'check', tone: 'ok', title: 'Competent', sub: 'Pieter van Wyk · 17 Oct' }, { icon: 'alert', tone: 'warn', title: 'AI: supervisor sign-off for week 6 missing', sub: 'Evidence may be incomplete' }] }
      ] },
    { id: 'report', nav: 'Reports', icon: 'chart', title: 'Moderation report', sub: 'Shared with the Quality Partner on request.', actions: [{ label: 'Export PDF', icon: 'upload' }],
      blocks: [{ type: 'kpis', items: [{ label: 'Decisions moderated', value: '64', icon: 'check' }, { label: 'Endorsed', value: '92%', tone: 'up', icon: 'shield' }, { label: 'Returned', value: '5', icon: 'back' }] }, { type: 'bars', title: 'Agreement rate by assessor', unit: '%', max: 100, data: [['P. van Wyk', 96, 'ok'], ['S. Dube', 90, 'ok'], ['L. Dlamini', 78, 'warn']] }] }
  ]
}),
/* ============================== 7. LEARNER (web) ============================== */
T({
  id: 'learner', name: 'Learner / graduate', tag: 'Learn, practise, get certified, get hired', group: 'Learner', icon: 'user', context: 'My learning', persona: 'Ayanda Zwane', personaTitle: 'Bricklayer · BL-2027-01',
  screens: [
    { id: 'home', nav: 'My dashboard', icon: 'home', title: 'Sawubona, Ayanda', sub: 'You are 86% through Bricklayer. Keep your 12-day streak going.', actions: [{ label: 'Continue learning', to: 'course', primary: true, icon: 'play' }],
      blocks: [
        { type: 'kpis', items: [{ label: 'Course progress', value: '86%', icon: 'chart', dark: true }, { label: 'Learning streak', value: '12 days', delta: '🔥 personal best', icon: 'flame' }, { label: 'Exam readiness', value: '88', delta: 'Ready for EISA', tone: 'up', icon: 'trophy' }, { label: 'Points', value: '4,820', delta: 'Rank 3 in cohort', icon: 'star' }] },
        { type: 'steps', title: 'My journey', steps: [{ name: 'Enrolled', sub: 'Jan 2027', state: 'done' }, { name: 'Knowledge', sub: '6 of 6', state: 'done' }, { name: 'Practical', sub: '5 of 5', state: 'done' }, { name: 'Workplace', sub: '4 of 4', state: 'done' }, { name: 'SoR', sub: 'Issued', state: 'done' }, { name: 'EISA', sub: '12 Feb', state: 'active' }, { name: 'Certificate', sub: '' }] },
        { type: 'list', span: 6, title: 'Up next', items: [{ icon: 'trophy', title: 'Timed mock EISA #3', sub: '90 minutes · last score 84%', meta: btn('Start') }, { icon: 'cube', title: '3D simulator: Corner & reveal', sub: 'Beat your best of 91%', meta: btn('Launch') }] },
        { type: 'chat', span: 6, title: 'AI coach · Bricklayer only', messages: [['me', 'Explain stretcher vs header bond simply'], ['ai', 'Stretcher bond shows only the long side of each brick. Header bond shows the short ends. Want a 2-minute video and 3 practice questions?']] }
      ] },
    { id: 'course', nav: 'My course', icon: 'book', title: 'Occupational Certificate: Bricklayer', sub: 'Only your enrolled course is shown. Download any lesson to learn offline.', actions: [],
      blocks: [{ type: 'tiles', items: [
        { icon: 'book', title: 'Knowledge 1–6', sub: 'Building science, drawings, mortar, safety', meta: '<span>Complete</span>' + pill('100%', 'ok') },
        { icon: 'hard', title: 'Practical 1–5', sub: 'Walls, corners, openings, arches', meta: '<span>Complete</span>' + pill('100%', 'ok') },
        { icon: 'pin', title: 'Workplace 1–4', sub: 'Logged on site with your supervisor', meta: '<span>Signed off</span>' + pill('100%', 'ok') },
        { icon: 'vr', title: '3D & VR simulators', sub: '4 scenarios · best 91%', meta: '<span>Practice anytime</span>' + pill('Open', 'violet'), feature: true }] }] },
    { id: 'results', nav: 'Results & certificate', icon: 'medal', title: 'Results & certificate', sub: 'Everything is verified and shareable with employers.', actions: [{ label: 'Share with employer', primary: true, icon: 'link' }],
      blocks: [
        { type: 'callout', tone: 'ok', icon: 'medal', title: 'Statement of Results issued', text: 'All modules complete. You are registered for the EISA on 12 February at Polokwane Trade Test Centre.' },
        { type: 'table', title: 'My records', cols: ['Record', 'Issued by', 'Date', 'Verification'], rows: [['Statement of Results', 'Training Young Minds', '14 Dec 2027', pill('QR verified', 'ok')], ['EISA registration', 'Assessment centre', '18 Dec 2027', pill('Confirmed', 'ok')], ['Occupational certificate', 'QCTO', 'After EISA', pill('Pending', 'muted')]] }
      ] },
    { id: 'careers', nav: 'Careers', icon: 'briefcase', title: 'Jobs for you', sub: 'Matched on your verified skills, site hours and location — never on who you are.', actions: [{ label: 'Build my CV with AI', primary: true, icon: 'spark' }],
      blocks: [{ type: 'tiles', items: [
        { icon: 'briefcase', title: 'Junior bricklayer', sub: 'Mabena Construction · Midrand · R9,500 / month', badge: ring(94, 'ok'), meta: '<span>Has qualification · 420 site hours · 12 km</span>' },
        { icon: 'briefcase', title: 'Apprentice bricklayer', sub: 'Highveld Homes · Centurion', badge: ring(87, 'ok'), meta: '<span>Strong simulator scores</span>' },
        { icon: 'rocket', title: 'Start your own business', sub: 'Build a pitch and meet sponsors', badge: pill('New', 'gold'), meta: '<span>Pitch builder</span>', feature: true }] }] }
  ]
}),
/* ============================== 8. HOST EMPLOYER ============================== */
{
  id: 'employer', name: 'Host employer', tag: 'Host learners, grants, B-BBEE', group: 'Workplace & employers', icon: 'hard', context: 'Employer portal',
  tenantLabel: 'Employer', tenant: 'Mabena Construction (Pty) Ltd', domain: '3 sites · Gauteng', tenantIcon: 'pin', persona: 'Lindiwe Mabena', personaTitle: 'HR & Skills Development Facilitator',
  screens: [
    { id: 'dashboard', nav: 'Dashboard', icon: 'home', title: 'Your hosted learners', sub: '64 learners from 2 training providers across 3 sites.', actions: [{ label: 'Download evidence pack', primary: true, icon: 'upload' }],
      blocks: [
        { type: 'kpis', items: [{ label: 'Hosted learners', value: '64', icon: 'users' }, { label: 'Logbooks up to date', value: '91%', tone: 'up', icon: 'check' }, { label: 'Grant received this year', value: 'R1.9m', icon: 'coin', dark: true }, { label: 'Skills spend (B-BBEE)', value: 'R2.6m', delta: 'Evidence complete', icon: 'shield' }] },
        { type: 'table', title: 'By site', cols: ['Site', 'Supervisor', 'Learners', 'Logbook sign-offs', 'Attendance'], rows: [['<b>Waterfall Estate</b>', 'J. Botha', '28', bar(94, 'ok'), '96%'], ['<b>Kyalami Ridge</b>', 'S. Pillay', '21', bar(88, 'ok'), '93%'], ['<b>Steyn City Phase 4</b>', 'M. Radebe', '15', bar(69, 'warn'), '89%']] }
      ] },
    { id: 'mou', nav: 'Workplace agreements', icon: 'file', title: 'Workplace agreements', sub: 'Sign once per qualification; supervisors are assigned before learners arrive.', actions: [{ label: 'New agreement', primary: true, icon: 'plus' }],
      blocks: [{ type: 'table', title: 'Agreements', cols: ['Training provider', 'Qualification', 'Learners', 'Signed', 'Status'], rows: [['Training Young Minds', 'Bricklayer', '42', '12 Jan 2027', pill('Active', 'ok')], ['Training Young Minds', 'Plumber', '14', '—', pill('Awaiting your signature', 'warn')], ['Coastal Trades Institute', 'Plant operator', '8', '3 Mar 2027', pill('Active', 'ok')]] }] },
    { id: 'hire', nav: 'Hire from cohort', icon: 'briefcase', title: 'Hire the learners you trained', sub: 'Verified certificates and the site hours they logged with you.', actions: [{ label: 'Post a job', primary: true, icon: 'plus' }],
      blocks: [{ type: 'table', title: 'Graduating soon', cols: ['Learner', 'Qualification', 'Hours with you', 'Readiness', ''], rows: [[who('Ayanda Zwane'), 'Bricklayer', '420', ring(88, 'ok'), btn('Invite to interview', null, 'primary')], [who('Musa Ngcobo'), 'Bricklayer', '396', ring(92, 'ok'), btn('Invite to interview', null, 'primary')]] }] }
  ]
},
/* ============================== 9. SITE SUPERVISOR (web) ============================== */
{
  id: 'supervisor', name: 'Site supervisor', tag: 'Sign off workplace logbooks', group: 'Workplace & employers', icon: 'pin', context: 'Site supervisor',
  tenantLabel: 'Site', tenant: 'Waterfall Estate', domain: 'Mabena Construction', tenantIcon: 'pin', persona: 'Johan Botha', personaTitle: 'Site foreman · mostly uses the mobile app',
  screens: [
    { id: 'approvals', nav: 'Approvals', icon: 'check', title: '12 entries to approve', sub: 'Most supervisors approve on the mobile app, even offline. This is the desk view.', actions: [{ label: 'Approve all verified', primary: true, icon: 'check' }],
      blocks: [{ type: 'table', title: 'Waiting', cols: ['Learner', 'Task', 'Evidence', 'Hours', 'GPS', ''], rows: [[who('Thabo Mabena'), 'Lintel placement', '3 photos', '4.0', pill('On site', 'ok'), btn('Approve', null, 'primary')], [who('Ayanda Zwane'), 'Window opening', '2 photos', '3.5', pill('On site', 'ok'), btn('Approve', null, 'primary')], [who('Kabelo Maseko'), 'Scaffold set-up', '1 photo', '2.0', pill('Off site', 'bad'), btn('Return')]] }] }
  ]
},
/* ============================== 10. ASSESSMENT CENTRE ============================== */
{
  id: 'acentre', name: 'Assessment / trade test centre', tag: 'Run EISAs, submit results', group: 'Assessment centres & oversight', icon: 'medal', context: 'Assessment centre',
  tenantLabel: 'Assessment centre', tenant: 'Polokwane Trade Test Centre', domain: 'QCTO-accredited (sample)', tenantIcon: 'shield', persona: 'Mpho Sebola', personaTitle: 'Centre manager',
  screens: [
    { id: 'schedule', nav: 'Schedule', icon: 'calendar', title: 'EISA schedule', sub: 'Sessions, capacity and candidate packs.', actions: [{ label: 'Open slots', primary: true, icon: 'plus' }],
      blocks: [{ type: 'kpis', items: [{ label: 'Sessions this month', value: '14', icon: 'calendar' }, { label: 'Seats filled', value: '86%', icon: 'users' }, { label: 'Packs incomplete', value: '3', tone: 'down', icon: 'alert' }] },
        { type: 'table', title: 'Upcoming', cols: ['Date', 'Qualification', 'Candidates', 'Packs complete', 'Venue', ''], rows: [['12 Feb', 'Bricklayer', '34 / 40', bar(94, 'ok'), 'Workshop A', btn('Roster', 'acentre-checkin')], ['14 Feb', 'Plant operator', '18 / 20', bar(83, 'warn'), 'Yard', btn('Roster', 'acentre-checkin')]] }] },
    { id: 'checkin', nav: 'Check-in', icon: 'qr', title: 'Check-in · Bricklayer EISA', sub: 'Candidates are verified by ID and SoR QR code on a tablet.', actions: [{ label: 'Close check-in', primary: true, icon: 'lock' }],
      blocks: [{ type: 'table', title: '31 of 34 checked in', cols: ['Candidate', 'ID', 'SoR QR', 'Training provider', 'Status'], rows: [[who('Ayanda Zwane'), pill('Match', 'ok'), pill('Valid', 'ok'), 'Training Young Minds', pill('Checked in', 'ok')], [who('Musa Ngcobo'), pill('Match', 'ok'), pill('Valid', 'ok'), 'Training Young Minds', pill('Checked in', 'ok')], [who('Peter Mashaba'), pill('Mismatch', 'bad'), pill('Valid', 'ok'), 'Ubuntu Build Academy', pill('Held · TP notified', 'bad')]] }] },
    { id: 'results', nav: 'Results', icon: 'chart', title: 'Results submission', sub: 'Capture marks, add marker reports, submit for approval and track it.', actions: [{ label: 'Submit for approval', primary: true, icon: 'send' }],
      blocks: [{ type: 'steps', title: 'Bricklayer · 12 Feb', steps: [{ name: 'Marked', sub: '34 / 34', state: 'done' }, { name: 'Marker reports', sub: '2 of 2', state: 'done' }, { name: 'Submitted', sub: 'Today', state: 'active' }, { name: 'Quality Partner approval' }, { name: 'QCTO certificate' }] }] }
  ]
},
/* ============================== 11. CETA ============================== */
{
  id: 'ceta', name: 'CETA · Quality Partner & funder', tag: 'Verify, approve tranches, assure quality', group: 'Assessment centres & oversight', icon: 'coin', context: 'Funder view',
  tenantLabel: 'Oversight', tenant: 'CETA (sample view)', domain: 'Read-only + approvals', tenantIcon: 'lock', persona: 'Grants officer', personaTitle: 'Sample persona',
  screens: [
    { id: 'portfolio', nav: 'Portfolio', icon: 'home', title: 'Funded projects', sub: 'Every learner, tranche and piece of evidence — already pre-checked.', actions: [],
      blocks: [{ type: 'kpis', items: [{ label: 'Funded learners', value: '3,412', icon: 'users' }, { label: 'Tranches awaiting review', value: '27', icon: 'inbox', dark: true }, { label: 'First-time pass of checks', value: '96%', tone: 'up', icon: 'shield' }, { label: 'Duplicate learners blocked', value: '18', icon: 'alert' }] },
        { type: 'table', title: 'Awaiting review', cols: ['Project', 'Training provider', 'Tranche', 'Learners', 'Value', 'Pre-check', ''], rows: [['Bricklayer learnership', 'Training Young Minds', '2', '42', 'R386,400', light('g'), btn('Review', 'ceta-review', 'primary')], ['Plant operator', 'Coastal Trades Institute', '1', '20', 'R164,000', light('g'), btn('Review', 'ceta-review')]] }] },
    { id: 'review', nav: 'Tranche review', icon: 'check', title: 'Tranche 2 · Bricklayer learnership', sub: 'Training Young Minds · 42 learners · R386,400', actions: [{ label: 'Approve', primary: true, icon: 'check' }, { label: 'Return with reason', icon: 'back' }],
      blocks: [{ type: 'list', span: 6, title: 'Evidence checks', items: [{ icon: 'check', tone: 'ok', title: 'Attendance matches stipends paid', sub: '42 / 42' }, { icon: 'check', tone: 'ok', title: 'Workplace logbooks signed', sub: '42 / 42' }, { icon: 'check', tone: 'ok', title: 'Certified documents within 6 months', sub: '42 / 42' }, { icon: 'check', tone: 'ok', title: 'No duplicate learners across providers', sub: 'ID cross-check' }] },
        { type: 'form', span: 6, title: 'Decision', fields: [{ label: 'Outcome', type: 'seg', options: ['Approve', 'Return'], on: 0, full: true }, { label: 'Comment', type: 'area', value: 'Evidence complete. Approved for payment.', full: true }], submit: 'Record decision' }] }
  ]
},
/* ============================== 12. QCTO ============================== */
{
  id: 'qcto', name: 'QCTO / DHET oversight', tag: 'National view, data quality', group: 'Assessment centres & oversight', icon: 'globe', context: 'Regulator view',
  tenantLabel: 'Oversight', tenant: 'QCTO (sample view)', domain: 'Read-only', tenantIcon: 'lock', persona: 'Data analyst', personaTitle: 'Sample persona',
  screens: [
    { id: 'national', nav: 'National view', icon: 'globe', title: 'National occupational training view', sub: 'Live, read-only data from providers on the platform. Supports QCTO systems; does not replace them.', actions: [{ label: 'Export', icon: 'upload' }],
      blocks: [{ type: 'kpis', items: [{ label: 'Learners enrolled', value: '18,460', icon: 'users' }, { label: 'Certified this year', value: '4,102', icon: 'medal', dark: true }, { label: 'Employed within 6 months', value: '63%', icon: 'briefcase' }, { label: 'Files passing validation', value: '98.7%', tone: 'up', icon: 'check' }] },
        { type: 'bars', span: 6, title: 'Enrolments by province', data: [['Gauteng', 6420], ['KwaZulu-Natal', 3180], ['Western Cape', 2510], ['Limpopo', 1960], ['Eastern Cape', 1740], ['North West', 1120]] },
        { type: 'bars', span: 6, title: 'Completion rate by qualification', unit: '%', max: 100, data: [['Bricklayer', 78, 'ok'], ['Plumber', 74, 'ok'], ['Plant operator', 81, 'ok'], ['Carpenter', 66, 'warn']] }] },
    { id: 'quality', nav: 'Data quality', icon: 'filter', title: 'Data quality by provider', sub: 'Common errors are fixed before files reach QCTO.', actions: [],
      blocks: [{ type: 'table', title: 'Providers', cols: ['Training provider', 'Files sent', 'Passed first time', 'Top issue caught before upload'], rows: [['Training Young Minds', '38', bar(100, 'ok'), '—'], ['Coastal Trades Institute', '27', bar(96, 'ok'), 'Date format'], ['Ubuntu Build Academy', '19', bar(89, 'warn'), 'Late enrolment file']] }] }
  ]
},
/* ============================== 13. HIRING COMPANY ============================== */
{
  id: 'hiring', name: 'Hiring company', tag: 'Post jobs, find verified talent', group: 'Jobs, enterprise & trust', icon: 'briefcase', context: 'Talent',
  tenantLabel: 'Company', tenant: 'Highveld Homes', domain: 'Growth talent package (example)', tenantIcon: 'building', persona: 'Karen Pillay', personaTitle: 'Talent acquisition',
  screens: [
    { id: 'search', nav: 'Talent search', icon: 'search', title: 'Verified talent', sub: 'Every certificate and site hour is verified. The fitness score explains itself.', actions: [{ label: 'Post a job', to: 'post', primary: true, icon: 'plus' }],
      blocks: [{ type: 'table', title: 'Apprentice bricklayer · Centurion', cols: ['Candidate', 'Qualification', 'Site hours', 'Fitness', 'Why', ''], rows: [[who('Musa Ngcobo', 'Midrand'), 'Bricklayer · SoR', '396', ring(93, 'ok'), 'Qualification ✓ · simulator 94% · 18 km', btn('Shortlist', null, 'primary')], [who('Ayanda Zwane', 'Midrand'), 'Bricklayer · SoR', '420', ring(91, 'ok'), 'Qualification ✓ · 420 hours · 22 km', btn('Shortlist', null, 'primary')], [who('Nomsa Khoza', 'Soweto'), 'Bricklayer · in training', '210', ring(68), 'EISA in 2 months', btn('Watch')]] },
        { type: 'callout', tone: 'gold', icon: 'shield', title: 'Fair by design', text: 'Scores use job-relevant skills only. Race, gender, age and disability are never used to rank candidates.' }] },
    { id: 'post', nav: 'Post a job', icon: 'plus', title: 'Post a job', sub: 'Matched candidates appear as soon as you publish.', actions: [],
      blocks: [{ type: 'form', title: 'Job details', fields: [{ label: 'Job title', value: 'Apprentice bricklayer' }, { label: 'Location', value: 'Centurion, Gauteng', icon: 'pin' }, { label: 'Required qualification', value: 'Bricklayer (occupational)', select: true }, { label: 'Minimum site hours', value: '200' }, { label: 'Pay', value: 'R8,500 – R10,000 / month' }, { label: 'Start date', value: '1 March 2028', icon: 'calendar' }], submit: 'Publish job', to: 'hiring-pipeline' }] },
    { id: 'pipeline', nav: 'Pipeline', icon: 'layers', title: 'Hiring pipeline', sub: 'Apprentice bricklayer · 12 candidates', actions: [],
      blocks: [{ type: 'kanban', columns: [{ name: 'Shortlisted', cards: [{ title: 'Musa Ngcobo', sub: 'Fitness 93', tag: pill('Verified', 'ok') }, { title: 'Ayanda Zwane', sub: 'Fitness 91', tag: pill('Verified', 'ok') }] }, { name: 'AI interview done', cards: [{ title: 'Sipho Nkosi', sub: 'Summary ready', tag: pill('Strong', 'info') }] }, { name: 'Interview', cards: [{ title: 'Lerato Mokoena', sub: 'Thu 10:00', tag: pill('Booked', 'gold') }] }, { name: 'Hired', cards: [{ title: 'Kagiso Molefe', sub: 'Starts 1 Mar', tag: pill('Hired', 'ok') }] }] }] }
  ]
},
/* ============================== 14. ENTREPRENEUR ============================== */
{
  id: 'founder', name: 'Entrepreneur', tag: 'Turn skills into a business', group: 'Jobs, enterprise & trust', icon: 'rocket', context: 'Start a business',
  tenantLabel: 'Graduate', tenant: 'Sipho Nkosi', domain: 'Certified plumber', tenantIcon: 'medal', persona: 'Sipho Nkosi', personaTitle: 'Founder · Nkosi Plumbing',
  screens: [
    { id: 'pitch', nav: 'Pitch builder', icon: 'rocket', title: 'Nkosi Plumbing', sub: 'The AI helps you shape your idea into a one-page pitch sponsors can say yes to.', actions: [{ label: 'Publish pitch', primary: true, icon: 'send' }],
      blocks: [{ type: 'steps', title: 'Your pitch', steps: [{ name: 'Idea', state: 'done' }, { name: 'Customers', state: 'done' }, { name: 'Costs', state: 'active' }, { name: 'Ask', sub: '' }, { name: 'Video' }] },
        { type: 'form', span: 7, title: 'Start-up costs', fields: [{ label: 'Tools and equipment', value: 'R38,000' }, { label: 'Bakkie (second-hand)', value: 'R95,000' }, { label: 'Insurance & registration', value: 'R6,500' }, { label: 'Funding ask', value: 'R120,000', ok: true }] },
        { type: 'list', span: 5, title: 'Proof of skills (attached automatically)', items: [{ icon: 'medal', tone: 'ok', title: 'Occupational certificate: Plumber', sub: 'QCTO · verified' }, { icon: 'pin', tone: 'ok', title: '640 workplace hours', sub: 'Signed by supervisors' }] }] },
    { id: 'matches', nav: 'Matches', icon: 'users', title: 'Interested sponsors & mentors', sub: 'Introductions only. The platform never holds investment money.', actions: [],
      blocks: [{ type: 'tiles', items: [{ icon: 'coin', title: 'Gauteng ESD Fund (sample)', sub: 'Backs trades start-ups up to R150k', meta: '<span>Wants to meet</span>' + pill('New', 'gold'), feature: true }, { icon: 'user', title: 'Mentor: retired plumbing contractor', sub: '30 years in Soweto', meta: '<span>Available Thursdays</span>' }] }] }
  ]
},
/* ============================== 15. INVESTOR ============================== */
{
  id: 'investor', name: 'Investor / sponsor', tag: 'Back talent and small businesses', group: 'Jobs, enterprise & trust', icon: 'coin', context: 'Sponsor',
  tenantLabel: 'Sponsor', tenant: 'Gauteng ESD Fund (sample)', domain: 'Corporate ESD / SED', tenantIcon: 'building', persona: 'Ravi Govender', personaTitle: 'ESD programme lead',
  screens: [
    { id: 'discover', nav: 'Discover', icon: 'search', title: 'Vetted pitches & cohorts', sub: 'Every founder\'s skills are verified on the platform.', actions: [],
      blocks: [{ type: 'tiles', items: [{ icon: 'rocket', title: 'Nkosi Plumbing', sub: 'Certified plumber · Soweto · asks R120k', meta: '<span>Vetted</span>' + pill('Plumbing', 'info') }, { icon: 'rocket', title: 'Zwane Brickworks', sub: 'Certified bricklayer · Midrand · asks R80k', meta: '<span>Vetted</span>' + pill('Building', 'info') }, { icon: 'users', title: 'Sponsor a cohort', sub: 'Fund 20 plant-operator bursaries in Limpopo', meta: '<span>B-BBEE SED</span>' + pill('Impact', 'gold'), feature: true }] }] },
    { id: 'impact', nav: 'Impact', icon: 'chart', title: 'Your impact', sub: 'Evidence for your B-BBEE ESD and SED reporting.', actions: [{ label: 'Download report', primary: true, icon: 'upload' }],
      blocks: [{ type: 'kpis', items: [{ label: 'Businesses backed', value: '12', icon: 'rocket' }, { label: 'Jobs created', value: '41', icon: 'briefcase', dark: true }, { label: 'Learners sponsored', value: '60', icon: 'users' }] }] }
  ]
},
/* ============================== 16. PUBLIC VERIFIER ============================== */
{
  id: 'verify', name: 'Public verifier', tag: 'Check a certificate in seconds', group: 'Jobs, enterprise & trust', icon: 'qr', context: 'Verification',
  tenantLabel: 'Public page', tenant: 'Certificate verification', domain: 'verify.platform.co.za', tenantIcon: 'globe', persona: 'Anyone', personaTitle: 'No login needed',
  screens: [
    { id: 'check', nav: 'Verify', icon: 'qr', title: 'Verify a certificate', sub: 'Scan the QR code or type the number. Only minimal details are shown.', actions: [],
      blocks: [{ type: 'form', span: 6, title: 'Look up', fields: [{ label: 'Certificate or SoR number', value: 'SOR-TYM-2027-004812', full: true, icon: 'qr' }], submit: 'Verify' },
        { type: 'callout', span: 6, tone: 'ok', icon: 'shield', title: 'Genuine · Statement of Results', text: 'A. Zwane · Bricklayer · issued 14 Dec 2027 by Training Young Minds.' }] }
  ]
},
/* ============================== 17. JV COMPLIANCE DESK ============================== */
{
  id: 'desk', name: 'JV compliance desk', tag: 'Managed service for TPs', group: 'Platform', icon: 'inbox', context: 'Managed service',
  tenantLabel: 'Platform', tenant: 'TYM × MCS compliance desk', domain: '23 TPs subscribed', tenantIcon: 'shield', persona: 'Priya Naidoo', personaTitle: 'Compliance specialist',
  screens: [
    { id: 'queue', nav: 'Work queue', icon: 'inbox', title: 'Work queue', sub: 'Packs to check and claims to submit for TPs on the managed service.', actions: [{ label: 'Take next', primary: true, icon: 'play' }],
      blocks: [{ type: 'kpis', items: [{ label: 'Packs in queue', value: '31', icon: 'inbox' }, { label: 'Submitted green first time', value: '97%', tone: 'up', icon: 'check', dark: true }, { label: 'Avg. turnaround', value: '1.2 days', icon: 'clock' }] },
        { type: 'table', title: 'Due soon', cols: ['Training provider', 'Pack', 'Readiness', 'Due', 'Owner', ''], rows: [['Karoo Skills Centre', 'Tranche 1 · 18 learners', light('a'), 'Tomorrow', 'Priya', btn('Open', null, 'primary')], ['Ubuntu Build Academy', 'QCTO enrolment file', light('r'), '2 days', 'Unassigned', btn('Take')], ['Coastal Trades Institute', 'EISA registration', light('g'), '5 days', 'Priya', btn('Submit')]] }] }
  ]
}
];
