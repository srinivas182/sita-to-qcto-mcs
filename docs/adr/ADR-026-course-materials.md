# ADR-026: Course materials, offline packs and low-data video

**Status:** Accepted · Sprint 5d (decisions 4 and 5 of the Sprint 5 spec)

## Context

The QCTO expects learners to see only the qualification they are enrolled on. Many LMSs open the whole catalogue. Learners on prepaid data can't stream full-quality video, and many classrooms have no signal.

## Decision

1. **Materials** belong to a qualification and, optionally, a curriculum module (knowledge or practical). Kinds: PDF document, video (MP4, MOV, WebM), picture, or an https link. Each is a draft or published. Changing the file or the link increases the **version**.
2. **Storage:** our own file storage per provider (Sprint 2). The file purpose is `learning_material`, up to 500 MB. Managing materials needs a new permission, `materials.manage` (owner, centre manager and facilitator).
3. **The one way in for learners** is their enrolment. Every list, download and content request checks `EnrolmentService::mayLearn` (an active enrolment in a cohort for that qualification) and that the material is published; otherwise it answers 404. The file id alone opens nothing.
   - Cloud stores hand out short-lived signed links.
   - The local store streams through `/v1/me/materials/{id}/content` after the same check.
4. **Low-data video:** a queued job makes a 360p H.264 copy (CRF 30, mono 64 kb/s audio, fast start) and records the duration. Learners get it by default.
   - Without ffmpeg on the worker the material simply has no low-data copy (`low_status: none`).
   - Production workers need ffmpeg.
5. **Offline pack:** `/v1/me/courses/{enrolment}/offline-pack` lists each item's id, version, size and whether the low copy is used. The app downloads only items whose version changed.
6. **Progress:** opening a material is recorded on first download; learners mark it done. The dashboard (5f) shows progress per course.
7. **Not now:** SCORM and H5P packages (Phase 2); shared catalogue materials from the platform (later, read-only to providers).

## Consequences

- Content access is a rule at the API, not a menu setting, so it applies equally to the web, the app and anything else that calls the API.
