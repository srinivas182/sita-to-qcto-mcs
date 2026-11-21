# Sprint 0 — Clickable mock-ups

Clickable web and mobile mock-ups for every role in the QCTO Skills Ecosystem (working title), built for review with Training Young Minds (TYM) before Sprint 1 starts. All names, organisations and figures are **sample data**.

## Open them

No build step. Open `design/mockups/index.html` in a browser, or serve the folder:

```bash
cd design/mockups && python3 -m http.server 8080
# then open http://localhost:8080
```

| Path | What it shows |
|---|---|
| `index.html` | Overview with links to both sets |
| `web/index.html` | Web portals: 17 roles, 51 screens. Start on the role picker, use **Switch role** at any time. Each screen has its own link, e.g. `web/index.html#centre-claims` |
| `mobile/index.html` | Mobile apps: 5 apps, 23 screens, shown as phone and tablet frames |

## Design direction

**Web portals — modern, sleek, professional (locked theme: Option C)**
- Light sidebar (`#ffffff`), soft grey-blue canvas (`#f6f7fb`), indigo primary (`#4338ca`), deep indigo for dark surfaces (`#1e1b4b`).
- Inter for all interface text at SaaS-standard sizes (page titles 22px, card titles 14px, body 13.5px); serif only in the brand mark.
- Left sidebar at every width: full (collapsible), icon rail on laptops, slide-in drawer on phones.
- One indigo "hero" figure per dashboard, traffic-light readiness (green / amber / red) on every pack, and the next action always one click away.
- Each TP's own logo, colours and domain appear in the sidebar and on learner sign-in (white-label).

**Mobile apps — three personalities on one design language**
- **Aurora** (learners, graduates, entrepreneurs): light, bright canvas matching the web theme, indigo primary with teal, amber and violet accents (dark mode as a user setting), clean cards, progress rings, streaks, badges and a centre capture button. Built to make daily learning a habit.
- **Site** (site supervisors): high contrast and readable in bright sun, safety-yellow, oversized buttons for gloved hands, offline banner, voice logging.
- **Studio** (facilitators, assessors, assessment centres): calm light canvas with teal accents for fast data entry; landscape tablet for exam-day check-in.

## Benchmark notes (to confirm in the Sprint 1 specification)

| Product type | What leaders do well | Where they fall short | What we do differently |
|---|---|---|---|
| SA SETA/QCTO learner management systems for providers | Learner records, PoE, SETA/QCTO exports | Desktop-first, provider-only, rejections found after submission | Every stakeholder connected, readiness checks before submission, rejection inbox |
| Global corporate LMS platforms | Polished course delivery, reporting | No QCTO workflow, no workplace logbook, data-heavy | Curriculum-mapped modules, offline logbook, low-data media |
| Consumer learning apps | Streaks, bite-size lessons, delight | No accredited outcome | Gamification tied to a real occupational certificate and a job |
| Job platforms | Large job volumes | Unverified CVs | Verified certificates and site hours; explainable fitness score |

## What to review with TYM

1. Is each role's journey complete? Anything missing or in the wrong order?
2. Do the readiness checks and rejection reasons match what CETA and QCTO actually send back?
3. Language and wording learners will understand.
4. Which screens matter most for the pilot qualifications.

Feedback is captured as changes before Sprint 1 (platform core) begins.
