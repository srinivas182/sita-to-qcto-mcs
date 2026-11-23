# ADR-024: Attendance (rotating QR, centre distance, offline check-in)

**Status:** Accepted · Sprint 5b (decision 1 of the Sprint 5 spec)

## Context

SETAs pay learner stipends only for months that the attendance register confirms. Paper registers get lost, and a fixed QR code or link can be forwarded to someone at home. Biometric devices have been questioned. Many learners have phones but little data, and classrooms often have no signal.

## Decision

1. **The check-in code** is `<session>.<30-second window>.<HMAC>`. The key is derived per provider from the app key. The facilitator's screen refreshes it each window, and it encodes `https://<provider>/c/<code>`. The app reads the code from the URL; a learner without the app opens the same link in the browser. A code is accepted for 60 seconds.
2. **Check-in window:** from 30 minutes before the class until it ends. A learner is late after the provider's limit (10 minutes by default). A second scan at least 30 minutes later checks the learner out; leaving more than 15 minutes early is recorded.
3. **Distance, not location:**
   - The phone sends its coordinates once. The server keeps only the distance from the centre and a verdict, allowing the phone's stated accuracy (up to 100 m).
   - The default radius is 200 m.
   - Online, a check-in from too far away is refused.
   - A centre with no location set is not checked.
4. **Offline check-ins:**
   - The phone keeps the code and the time it was scanned, and sends both later (72 hours by default).
   - The scan time must match the code's window, so a code can't be "scanned" later.
   - Offline check-ins are never refused for distance. A far, missing or simulated location goes to the facilitator to review instead.
5. **Facilitators:**
   - The class's facilitator (or a cohort manager) shows the code, sees the class list, and marks anyone (no phone, excused, left), always with a reason.
   - They accept or turn down check-ins that need review, and learners' absence notes. Accepting a note means "excused".
   - Every marking is audited, with the status before.
6. **Report:** per learner over a period, counting classes that have started since enrolment. Excused absences are left out of the percentage. Below the provider's threshold (80% by default) is flagged. 5c uses the same numbers for registers and alerts.

## Consequences

- Buddy check-ins need the friend to be at the centre within the same minute.
- The register can be trusted for stipend claims.
- We hold no location history.
- Rejected: biometrics (cost, privacy, trust); a fixed per-session code (shareable); Bluetooth beacons (hardware at every centre).
