# MyEventLane — Organiser Experience Acceptance Programme

**Type:** Acceptance programme (task-based, not page-based). **Date:** 2026-06-26
**Environment:** DDEV live — Drupal 11 / PHP 8.3, bootstrap Successful; authenticated runtime
evidence captured via one-time-login sessions for a **Pro** organiser (uid 92) and a **non-Pro**
organiser (uid 2).
**Benchmark:** Eventbrite · Humanitix · Meetup.
**Code changes this programme:** none (all findings documented; see §Implementation policy).

Evidence rule: every finding is anchored to a route, controller, service, config, or an
authenticated HTTP probe. Where evidence is absent: **Repository evidence not found.** Where the
running environment could not confirm: **Unable to verify in the current environment.**

---

## 1. Method & evidence basis

- Authenticated organiser sessions established with `drush uli` → cookie jar → `curl` of real
  organiser routes (HTTP status, size, `<title>`, headings).
- Pro vs non-Pro compared to test entitlement gating and upgrade prompts.
- Code/route/service inspection for controllers, access checks, workflows.
- Reused already-verified facts from `docs/launch/customer-verification/` (refund safety, Stripe
  webhook, payout reconciliation) — not re-audited.

**What could not be fully verified here:** on-device mobile rendering, screen-reader output, and
colour-contrast measurement (no axe-core/device lab). These are marked **Unable to verify** and
carried as launch-checklist items.

---

## 2. Canonical organiser surface — Event Studio (verified)

Event Studio **is** the canonical organiser experience. Evidence (authenticated probes):

| Probe | Result |
| --- | --- |
| `/vendor/studio` | 302 → `/vendor/events/create` |
| `/create-event` | 302 → `/vendor/events/{id}/edit` (draft created) → Studio |
| `/vendor/events/{id}/overview` | 302 → `/vendor/events/{id}/studio` |
| `/vendor/events/{id}/tickets` | 302 → `/vendor/events/{id}/studio/tickets` |
| `/vendor/events/{id}/attendees` | 302 → `/vendor/events/{id}/studio/attendees` |
| `/vendor/events/{id}/orders` | 302 → `/vendor/events/{id}/studio/orders` |
| `/vendor/events/{id}/settings` | 302 → `/vendor/events/{id}/studio/settings` |
| `/vendor/events/{id}/rsvps` | 302 → `/vendor/events/{id}/studio/attendees` |

The legacy console routes redirect **into** the `/studio/*` namespace — a clean consolidation, not
competing workflows. Console pages (`/vendor/events`, `/vendor/payouts`, `/vendor/attendees`,
`/vendor/analytics`) all render under a consistent **h1 "Event Studio"** shell.

**Conclusion:** No duplicate authoring workflows for the happy path. The earlier repository-audit
concern about "three parallel authoring surfaces" is **resolved** — wizard/console routes funnel
into Studio.

---

## 3. Organiser task review (evidence-based)

Each task: purpose · start → end route · CTA · success/failure · empty/error/loading · trust ·
a11y · mobile · est. time · evidence · friction. Scores in `organiser-task-scorecard.md`.

### Onboarding & account
- **Become a vendor / complete onboarding** — `/vendor/onboard` → `profile → account → stripe →
  branding → first-event → boost → complete` (dedicated controller per step). Start `/vendor/onboard`
  → 302 `/vendor/onboard/profile`. **Resume works**: the index routes to the next incomplete step.
  Trust: payments + branding + first-event built into the flow. Friction: **low**. Est. 8–12 min.
- **Connect Stripe** — `/vendor/onboard/stripe`, `/vendor/stripe/connect` + callback
  (`StripeConnectController`, verified in prior programme). Friction: low. Est. 3–5 min.

### Event authoring
- **Create first event** — `/create-event` → draft → Studio edit. Single front door. Friction: low.
- **Edit / publish / unpublish event** — Studio tabs; single `editorial` content-moderation
  workflow (no conflicting moderation models); `studio/publish` + `submit-review`. Friction: low.
- **Duplicate event** — **Repository evidence not found** for an organiser-facing duplicate/clone
  route (only Commerce admin config-entity duplicate forms exist). Gap vs Eventbrite/Humanitix.

### Tickets & RSVP
- **Create ticket type** — `/vendor/events/{id}/studio/tickets` + add-ticket modal
  (`EventTicketsController`). Renders 200. Friction: low–moderate.
- **RSVP event management** — `/event/{id}/rsvp` (public) + vendor RSVP mgmt under Studio attendees;
  access via `vendor_event_access`. Verified previously.

### Operations / event-day
- **View / export attendees** — `studio/attendees` (200) + CSV export
  (`/vendor/event/{id}/attendees/export`, `/dashboard/attendees/export`). Friction: low.
- **Check in attendees** — `/vendor/events/{id}/check-in` (200, "Event Studio" shell); **PWA offline**
  (`manifest.json`, `sw.js`), **QR scan** (`/scan`), search, toggle. Launch-grade on-site ops.
- **Issue / manage refunds** — `/vendor/events/{id}/refund-requests` (200) + approve/reject; buyer
  request flow + guards verified in prior programme. Friction: low.
- **Message attendees** — `/vendor/events/{id}/comms` → **HTTP 404** ("This event wandered off") for a
  **published, vendor-owned** event (1755). Verified defect — orphaned/broken route. (`/vendor/dashboard/messaging/brand` renders 200.) **Friction: blocking for this task.**

### Promotion
- **Boost event** — `/vendor/events/{id}/boost/wizard` → 302 step-1; 5-step wizard ending in payment.
  Friction: low–moderate.

### Analytics & finance
- **See sold / earned / what-needs-attention / what-next** — the **free** `/vendor/dashboard`
  surfaces *Revenue*, *Tickets sold*, *Attendees*, *Needs attention*, *Current focus*, *next step*
  (confirmed in non-Pro HTML). Core analytics questions are answerable without Pro. ✅
- **Deep analytics** — `/vendor/analytics` is **Pro-gated** (`_myeventlane_pro_access: 'TRUE'`):
  Pro 200, non-Pro 403. Acceptable as a Pro upsell *given* the free dashboard covers basics.
- **Event Insights** — `/vendor/events/{id}/insights/*` → **HTTP 500** (`ArgumentCountError` in
  `EventInsightsController::__construct`). Verified defect — Event Insights section is broken.
- **Restricted insights** — `/vendor/insights` → **403 "invite-only" even for Pro**
  (`myeventlane_reporting.vendor_insights`). Restricted/duplicate analytics surface causing confusion
  alongside the working `/vendor/analytics`.
- **Payouts** — `/vendor/payouts` (200); revenue now refund-netted (P1 fix from prior programme).
- **Finance / BAS** — `/vendor/finance/bas` (+ CSV/PDF). Present.

### Pro
- **Discover / upgrade** — `/vendor/pro` (200, "Run events like a professional business.") shown to
  all; `/vendor/pro/subscribe`, `/success`. Entitlement gating verified (`allowedIf(hasVendor && hasPro)`).
- **Locked-feature messaging** — non-Pro hitting `/vendor/pro/manage` sees **"This area is
  invite-only"**, and `/vendor/analytics`/`/vendor/settings/branding` show generic **"Access denied"**
  — **no upgrade CTA**. The denial copy does not sell Pro. UX gap.

### Settings / support / help
- **Manage profile & settings** — `/vendor/settings` (200, "Organiser settings"). Venues, branding,
  comms sub-sections present.
- **Help / KB / support** — `/vendor/help` → 301 `/help` (Help Centre); `/vendor/support` (escalations
  portal); notification bell/inbox (`myeventlane_notifications`).

---

## 4. UX review (against MEL Style Guide + benchmarks)

| Aspect | Finding | Evidence |
| --- | --- | --- |
| Navigation / IA | Strong — Studio consolidation; legacy routes redirect in | §2 |
| Consistency | Strong — unified "Event Studio" shell across console pages | h1 probes |
| Dashboard IA | Strong — Payment setup → Workspace overview → Needs attention → Current focus → Upcoming events | non-Pro dashboard headings |
| Confidence building | Good — dashboard answers "what needs attention / next step" | dashboard HTML |
| Microcopy (denials) | **Weak** — "invite-only"/"Access denied" instead of upgrade prompts | Pro/analytics 403s |
| Recovery from mistakes | Good for happy path; **broken** for Insights (500) and Comms (404) | probes |
| Empty states | Governed elsewhere; organiser-tab empty states **Unable to verify** per-tab here | — |
| Trust signals | Strong — Stripe, payouts, refund workflow, BAS | routes/prior verify |

---

## 5. Event Studio review

- **Canonical:** Yes (verified §2).
- **Duplicate workflows:** None on the happy path. **Restricted/duplicate analytics:**
  `myeventlane_reporting` (`/vendor/insights` 403; `/insights/*` 500) overlaps the working
  `myeventlane_analytics` (`/vendor/analytics`). Recommend retiring or fixing the reporting surface.
- **Dead/broken routes:** `/vendor/events/{id}/comms` (404 on valid event); `/vendor/events/{id}/insights/*` (500).
- **Missing shortcuts / functionality:** no event duplicate; denial pages lack upgrade CTA.
- **Opportunity to simplify:** consolidate analytics to one surface; remove the orphaned comms route
  or restore it under Studio.

---

## 6. Vendor dashboard review

Cards confirmed in HTML answer real organiser questions and drive next actions:

| Section | Answers a question? | Drives next action? |
| --- | --- | --- |
| Payment setup | "Am I ready to get paid?" ✅ | Connect Stripe ✅ |
| Workspace overview (Revenue / Tickets sold / Attendees) | "How am I doing?" ✅ | — |
| Needs attention | "What's wrong / pending?" ✅ | ✅ |
| Current focus | "What should I do now?" ✅ | ✅ |
| Upcoming events | "What's next on my calendar?" ✅ | Open event ✅ |

No purely decorative cards identified in the captured markup. Dashboard is a genuine command centre.
Minor: a clear page-level **h1** was not detected on the dashboard (heading list begins at section
h2s) — see a11y.

---

## 7. Pro experience review

- Value proposition page present and benchmarked ("Run events like a professional business").
- Entitlement gating is correct and secure (`allowedIf(hasVendor && hasPro)`).
- **Gaps:** (a) locked-feature denials use generic/"invite-only" copy with **no upgrade CTA**, so the
  moment of highest upgrade intent is wasted; (b) `/vendor/pro` shows the same marketing page to
  existing Pro members rather than a "manage" view. Organisers can *find* Pro but the platform does
  not actively convert at the lock points.

---

## 8. Analytics review — can organisers answer the four questions?

| Question | Answerable? | Where |
| --- | --- | --- |
| How many tickets have I sold? | ✅ Yes (free) | `/vendor/dashboard` (Tickets sold) |
| How much have I earned? | ✅ Yes (free) | `/vendor/dashboard` (Revenue) + `/vendor/payouts` |
| What needs attention today? | ✅ Yes (free) | `/vendor/dashboard` (Needs attention) |
| What should I do next? | ✅ Yes (free) | `/vendor/dashboard` (Current focus / next step) |

Deep/event-level analytics: `/vendor/analytics` (Pro) works; **Event Insights (`/insights/*`) is
broken (500)** — but the four core questions are answerable from the free dashboard.

---

## 9. Event-day operations review

Could an organiser confidently run a real event on MEL? **Largely yes.**
- Attendee list, search, check-in (200), **offline PWA**, **QR scan**, capacity/waitlist, refunds — all present.
- **Gap:** day-of attendee **messaging** route 404s (comms). Walk-ins/manual check-in toggle present.
- On-device verification of check-in PWA: **Unable to verify in the current environment** (needs device).

---

## 10. Accessibility & mobile (organiser workflows)

- **Primitives present**: consistent shell, semantic headings on dashboard sections, skip link on
  public surfaces. **Dashboard page-level `h1` not detected** (heading order starts at h2) — a11y gap
  to confirm.
- **Full WCAG 2.1 AA** (keyboard, screen reader, contrast on pastel tokens, touch targets, motion):
  **Unable to verify in the current environment** — requires axe-core + manual + device pass.
- **Mobile:** organiser console renders responsively; check-in is PWA-capable. Per-task on-device
  completion (Studio editing, attendees, messaging, analytics on a phone): **Unable to verify** here.

---

## 11. Verified defects (organiser)

| # | Defect | Evidence | Priority |
| --- | --- | --- | --- |
| OD-1 | Event Insights 500 | `EventInsightsController::__construct` ArgumentCountError; `/vendor/events/{id}/insights/sales` HTTP 500 | P1 |
| OD-2 | Attendee messaging route 404 | `/vendor/events/{id}/comms` 404 on published owned event 1755 | P1 |
| OD-3 | Pro/locked denials lack upgrade CTA | non-Pro 403 "invite-only"/"Access denied" on `/vendor/pro/manage`, `/vendor/analytics`, `/vendor/settings/branding` | P1 |
| OD-4 | Restricted/duplicate analytics surface | `/vendor/insights` 403 "invite-only" even for Pro; overlaps `/vendor/analytics` | P2 |
| OD-5 | No organiser event duplicate | Repository evidence not found | P2 |
| OD-6 | Dashboard missing page-level h1 | heading capture starts at h2 | P2 (a11y) |
| OD-7 | Recurring "paid booking availability / headers already sent" error | dblog repeated for event 1755 (cron + render) | P2 (investigate) |

No **P0** organiser launch blockers found. Money/security integrity already verified in the prior
programme.

---

## 12. Verdict

See `organiser-final-score.md` for scores and the Go/No-Go. Headline: a **strong, consolidated,
benchmark-credible organiser experience** (Studio canonical, command-centre dashboard, launch-grade
check-in, verified money/refund safety) with a **small set of P1 fixes** (insights 500, comms 404,
Pro upsell copy) and accessibility/mobile verification standing between it and a ≥9.5 score.
