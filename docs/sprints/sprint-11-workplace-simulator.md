# Sprint 11: Workplace simulator engine (and the simulator roadmap)

**Dates:** 12 to 23 April 2027 (new sprint, inserted after Sprint 10; every later sprint moves two weeks and go-live moves to 7 April 2028). **Phase 1: SaaS compliance core.**

**Why it changed:** the original plan had a separate 3D squad hand-building five simulators. None was started, because simulator 1 waited for a specification from TYM's experts. Five bespoke simulators would also never cover every course. Agreed on 24 Nov 2026: **one simulator engine for the whole platform.** Scenarios are data drafted from each course's curriculum, and every course gets 3D simulators at four levels.

**Demo script (what TYM sees at the end):**

1. A Bricklayer learner opens **Simulator** in the app or on the web and sees four levels for each practical module: Guided, Independent, Under pressure and Crisis.
2. **Level 1, site safety walk-through.** The learner walks a 3D construction site, spots the hazards and chooses the right PPE. Hints are on, and every choice is explained.
3. **Level 2, setting out and the first course.** The learner reads a drawing, chooses tools and materials, sets out a wall, and lays and checks a course. There are no hints, mistakes are allowed, and the scenario shows their consequences.
4. **Level 3, a deadline.** Wrong bricks are delivered and rain is coming, and the supervisor says to skip a step. The learner has to decide what to do under the timer.
5. **Level 4, a crisis.** A co-worker is injured, or a section is unsafe. The learner follows the right emergency steps in order.
6. **Debrief after each scenario:**
   - score against the module's outcomes;
   - what went right and wrong, and why;
   - a harder variation of the steps they got wrong;
   - the next level unlocks at the pass mark.
7. **On a low-cost phone** the same scenario runs in light mode (simpler scene, fixed views), and works offline once downloaded.
8. **Results show up for the facilitator and assessor** per learner and module, feed the learner's readiness score and points (Sprint 10), and can be attached as practice evidence. Simulator results never replace the assessor's decision.

## 1. Where the market stands (Nov 2026)

| | What leaders do | Weakness | Our answer |
|---|---|---|---|
| Interplay Learning (US) | Video courses and 3D simulations for HVAC, plumbing, electrical and solar, from foundational to advanced; VR optional ([Interplay](https://www.interplaylearning.com/?p=6504), [G2](https://www.g2.com/products/interplay-learning/discuss)) | US trades only; its simulations do not play on phones, only desktop, tablet or VR ([Interplay](https://www.interplaylearning.com/?p=6127)) | **Runs on phones, offline**, mapped to QCTO module outcomes |
| Transfr (US) | 330+ VR simulations in 8 sectors, mapped to US standards, with badges and coaching ([Transfr](https://transfrinc.com/), [Transfr Train](https://transfrinc.com/products/skills-training)) | Needs a managed VR headset; US standards | **VR is optional** (WebXR at centres); the same scenario runs on web and phone |
| SA providers | Workshop practice and paper scenarios; no curriculum-mapped simulators we could find | No practice between workshop sessions; no data for assessors | **Practice any time from the phone**, scored against the module outcomes, visible to facilitators and assessors |

## 2. How it works

- **One engine.** The 3D player runs in the browser (Three.js, the leading open-source WebGL library). The app runs the same player offline from a downloaded pack, and VR headsets run it through WebXR.
  - We chose this over Unity because it is one codebase in our repo, tested in our CI and updated with the platform, with no separate app per course.
  - Unity's advantage is richer physics and graphics. We'll revisit it only if a hand-skill module needs physics that the web can't do.
- **Scenarios are data, not code.** Each one is a versioned definition (JSON) made of steps:
  - Briefing.
  - Look around the scene and find the hazards.
  - Choose PPE, tools or materials from an inventory.
  - Put steps in order.
  - Measure or calculate, with a tolerance.
  - Place and align objects (snap to line, level and plumb).
  - Timed events and interruptions.
  - Talking to a character (supervisor, client, inspector), with branching choices.
  - A consequence, shown in the scene.
  - Debrief.
- **Four levels per practical or workplace module:**
  - **Guided:** hints, every choice explained.
  - **Independent:** no hints; mistakes play out.
  - **Under pressure:** timer, interruptions, wrong deliveries, a supervisor pushing shortcuts.
  - **Crisis:** emergencies and unsafe conditions.

  Each level has 2–4 scenarios plus variations. A pass (default 70%) unlocks the next level.
- **Scoring** maps every step to the module's outcomes (from the curriculum document) and weights safety-critical steps; a critical safety error fails the scenario at any level. Each attempt is recorded with its decisions, time taken and device, and is hashed like logbook entries. Attempts are idempotent offline.
- **Scene kits:** reusable 3D environments with a library of tools, materials, PPE, hazards and people. Kit 1 is the construction site (Bricklayer, then other construction trades); more kits follow (see §6). Assets are open-licence (CC0) 3D models at first; a 3D artist can upgrade them later without touching scenarios.
- **Light mode** for low-cost phones: fewer polygons, fixed camera points and tap-to-interact, with the same scenario and scoring.
- **Packs** are downloaded per module for offline use, with size budgets: a full pack up to 25 MB, a light pack up to 6 MB.
- **Content process:**
  - From the curriculum documents (the prospect will share them for all CETA courses), we draft scenario outlines per module and level.
  - TYM's subject experts review and approve.
  - Only approved versions reach learners.
  - Technical values (mortar mixes, tolerances, dimensions) come from the curriculum or the experts, never invented, and are marked "expert to confirm" until approved.

## 3. Scope of this sprint

### 3.1 Backend (`apps/api`, new module `Simulator`)
- `sim_packs`: qualification, module, version, status (draft, review, approved, retired), source document, approved by.
- `sim_scenarios`: pack, level, title, outcomes, scene kit, definition, variants.
- `sim_attempts`: enrolment, scenario, level, score, per-outcome results, decisions log, time, device, light mode, `client_id`, hash.
- Validation of scenario definitions against a schema; level unlocking.
- Results per learner and module for facilitators and assessors.
- Hooks into readiness (Sprint 10), points and logbook practice evidence.
- Seeded Bricklayer pack: 12 scenarios across 4 levels, drafted from the Occupational Certificate: Bricklayer (SAQA 93627) module list and marked for expert review.

### 3.2 Simulator player (new package `packages/simulator`)
- Three.js player with every step type in §2 and the construction site kit.
- Light mode, debrief screen, and keyboard and screen-reader paths for non-3D steps.

### 3.3 Web
- Learner Simulator page (levels, scenarios, attempts).
- Facilitator and assessor results views.
- Pack review screen for experts (preview, comment, approve).

### 3.4 Mobile
- Simulator tab: download packs, play offline (player in a WebView from local files), sync attempts through the outbox.

## 4. Performance, security and compliance

- 30 frames per second in full mode on a mid-range Android phone (4 GB RAM), and in light mode on an entry phone (2–3 GB RAM). A pack opens in under 3 seconds once downloaded.
- Attempt sync uses the existing outbox (idempotent, hashed).
- No camera or microphone use, and no personal data inside packs.
- Simulator scores are **practice and formative evidence only**. Summative decisions stay with registered assessors (ADR-029).

## 5. Definition of done

- The demo script runs end to end on the web and on an Android phone, in full and light modes, offline.
- Tests:
  - definition schema;
  - scoring and critical-safety fails;
  - level unlocking;
  - offline sync and idempotence;
  - access (learners see only their enrolled course);
  - a player smoke test in a headless browser.
- axe clean for the non-3D screens; CI green; merged; sprint report; sprint sheet updated.

## 6. Simulator roadmap (after this sprint)

| Sprint (new numbering) | Simulator work |
|---|---|
| 13 Content studio | **Scenario studio:** draft from curriculum documents, edit steps without code, expert review and approval, versions; house-interior scene kit (plumbing, electrical); adaptive variations |
| 14 Practice tests and mock EISA | Bricklaying hand-skill module (laying to line, level and plumb); simulator scenarios inside mock EISA; simulator results in readiness score v2 |
| 15 AI coach | AI role-play characters (supervisor, client, inspector) for open conversation steps; workshop scene kit |
| 16 Freelancer and marketplace | Welding and fabrication scene kit; CETA scenario packs |
| 17 Funding finder | VR (WebXR) mode for centres with headsets; remaining CETA scenario packs |
| 33–35 Testing and UAT | Simulators on web, phone and VR in the full regression; expert sign-off of every live pack |

Scenario packs for each further CETA course are written as data in the studio from the curriculum documents and approved by experts. That takes days per course, not a new build.

## 7. Needed

- **The CETA curriculum documents for all courses** (the prospect will share them), with Bricklayer first. For each course:
  - the QCTO occupational curriculum (knowledge, practical and work experience modules);
  - the EISA or assessment specification;
  - the provider's learner guides, if they allow their use (written OK).
- **5–10 real workplace incidents or common mistakes per trade**, from TYM's facilitators, for levels 3 and 4.
- **TYM subject experts:** about 2 hours per course to review and approve the drafted scenarios.

## 8. Decisions (for approval)

1. **One web-based engine (Three.js, WebXR for VR)** for every course, instead of a hand-built Unity simulator per course.
2. **Four levels for every practical and workplace module:** Guided, Independent, Under pressure, Crisis.
3. **Scenarios are drafted from the curriculum and approved by TYM's experts** before learners see them. Technical values come only from the curriculum or the experts.
4. **Simulator results are formative evidence** and feed readiness and points, never the summative decision.
5. **The new sprint goes after Sprint 10**, so the Phase 1 demo includes the simulator. Go-live moves two weeks, to 7 April 2028.
