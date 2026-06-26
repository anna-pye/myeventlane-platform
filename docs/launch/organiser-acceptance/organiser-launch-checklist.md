# Organiser Launch Checklist (Go / No-Go)

Legend: ☐ open · ⛔ P0 blocker · ⚠ P1 pre-launch · ▢ P2/P3 fast-follow · 🔬 verification gate.

## A. Hard gate — P0
- ⛔ **None open.** No organiser money/security/access blocker (verified in
  `docs/launch/customer-verification/`: payment, refund, payout, webhook).

## B. Pre-launch — P1 (fix or risk-accept with owner sign-off)
- ⚠ ☐ **OB-1** Event Insights 500 fixed (`/vendor/events/{id}/insights/*` → 200, no `ArgumentCountError`).
- ⚠ ☐ **OB-2** Attendee messaging route restored (`/vendor/events/{id}/comms` resolves; send works; linked from Studio).
- ⚠ ☐ **OB-3** Pro lock points show an "Upgrade to Pro" CTA (not "invite-only"/"Access denied").

## C. Verification gates — must pass to certify ≥9.5 🔬
- 🔬 ☐ **OV-1** WCAG 2.1 AA on organiser critical path (keyboard, screen reader, contrast, focus, touch targets, motion). *Unable to verify in this environment.*
- 🔬 ☐ **OV-2** On-device mobile completion of every critical organiser task (Studio edit, attendees, check-in, messaging, analytics, payouts). *Unable to verify here.*
- 🔬 ☐ **OV-3** Per-tab organiser empty / loading / error states verified. *Unable to verify here.*
- 🔬 ☐ Check-in **PWA offline + QR** validated on a real device.

## D. Fast-follow — P2 (may launch open)
- ▢ ☐ **OB-4** Consolidate analytics (retire/redirect `/vendor/insights`; fix or remove reporting surface).
- ▢ ☐ **OB-5** Duplicate event.
- ▢ ☐ **OB-6** Dashboard page-level `<h1>` (quick a11y).
- ▢ ☐ **OB-7** Resolve recurring "paid booking availability / headers already sent" error.
- ▢ ☐ **OB-8** Per-tab empty states.

## E. Future — P3
- ▢ ☐ **OB-9** Pro "manage" view for existing members.
- ▢ ☐ **OB-10** Studio shortcuts / dashboard deep-links.

## F. Release hygiene (run at release — not run in this audit)
- ☐ `ddev drush config:status` clean
- ☐ `ddev drush cr`
- ☐ Smoke: onboard → connect Stripe → create event → add ticket → publish → (test) buy → check-in → refund → view payout.
- ☐ dblog clean of 500s on organiser routes (re-check OD-1 / OD-7).

## G. Acceptance sign-off
| Gate | Status |
| --- | --- |
| A — P0 (none) | ✅ |
| B — P1 fixed or risk-accepted | ☐ |
| C — Verification gates passed | ☐ |
| F — Release hygiene | ☐ |

**Current verdict:** **GO WITH CONDITIONS** — no P0 organiser blockers; ship the core organiser
experience once the three P1 fixes (OB-1/2/3) and the verification gates (OV-1/2/3) are closed.
Without the verification gates, certify launch-ready but **not yet** the ≥9.5 "world-class" bar.
