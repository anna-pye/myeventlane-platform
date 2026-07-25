# Vendor Workspace v2 — Implementation Strategy

**Status:** Implementation readiness (Sprint 1B) — documentation only  
**Date:** 2026-07-25  
**Branch:** `feature/vendor-workspace-v2-discovery`  
**Base:** `37fcdc449`  
**Authority:** PDS 09 · 10 · 21 · 16 · ADR-0001 · prepared DDR-008/009  

**Do not implement from this document until human approval.** No PHP/Twig/SCSS/JS/YAML changes in Sprint 1B.

---

## Preconditions (blockers)

| Gate | Why |
| --- | --- |
| Human approval of Sprint 1B pack | Wireframes + architecture accepted |
| **DDR-008 / DDR-009** Accepted **or** explicit PO waiver for composition-only slices on `/studio` | Avoid building against undecided IA/paths |
| Config hygiene: `user.role.vendor` + `views.view.myeventlane_vendor_rsvps` active vs sync | Access-adjacent trust |
| Author or remove **ADR-0002** reference in cursor rule | Governance honesty |
| Feature branch from latest `main` | Dashboard Foundation already merged |

---

## Slice overview

```text
Slice 1  Workspace shell (presentation constancy)
Slice 2  Workspace Hero (event chrome / identity)
Slice 3  Readiness (honest health)
Slice 4  Publishing (deliberate gate UX)
Slice 5  Tickets
Slice 6  Operations (Attendees/Door, Orders, Messages, Marketing/Analytics emphasis)
Slice 7  Polish (a11y, mobile sticky, density, copy)
```

**Parallel / later (not Foundation):** Path unification (DDR-008 Phase 2), nav single-source collapse, Manager staff-gate.

---

## Slice 1 — Workspace shell

| | |
| --- | --- |
| **Scope** | Establish constant shell composition: global context + event chrome region + section nav + main band structure matching wireframes (`09`). No route renames. Clarify organiser copy: “Event Workspace”. |
| **Risk** | Low–Medium — theme/Twig structure; must not break section plugin rendering or libraries |
| **Reusable components** | Layout intents (DDR-003), existing Studio workspace Twig, vendor tokens (11) |
| **Reusable builders** | `EventStudioSectionManager`, `EventStudioPreprocess` |
| **Twig impact** | `mel-event-studio-workspace.html.twig`, sidebar/topbar wrappers — structure only |
| **SCSS impact** | `mel-event-studio-shell.css` / nav CSS — spacing hierarchy, not new kit |
| **Validation** | Visual: Desktop/tablet/390px shell; `ddev drush cr`; keyboard to section nav; no dual global nav; `npm run mel:lint` / `mel:build` if theme assets touched |

---

## Slice 2 — Workspace Hero

| | |
| --- | --- |
| **Scope** | Event chrome: name, status, date clarity, **one** primary CTA slot, secondary View/Share. Align CTA with lifecycle tables (`08`). Remove competing dual-primary (topbar publish vs Home) by slot rules. |
| **Risk** | Medium — publish affordance must remain reachable when Ready; do not hide money gates |
| **Reusable components** | PDS 05 Workspace Hero patterns; existing topbar |
| **Reusable builders** | VM `event` + `next_action`; presentation layer |
| **Twig impact** | `mel-event-studio-topbar.html.twig` (+ Home header if split) |
| **SCSS impact** | Hero/chrome spacing; sticky mobile CTA prep |
| **Validation** | CTA matrix spot-check Draft/Ready/Published/Live; focus visible; SR status not colour-only |

---

## Slice 3 — Readiness

| | |
| --- | --- |
| **Scope** | Health strip + Event Ready presentation: honest checklist, counts, recovery links. Never green without eligibility. Unify messaging with Publishing section. |
| **Risk** | High if readiness cosmetics diverge from `PublishEligibilityEvaluator` / Stripe gate |
| **Reusable components** | Readiness checklist patterns; alerts (severity) |
| **Reusable builders** | `EventReadinessFacade` / `EventReadinessService`; `EventStudioWorkspacePresentation` |
| **Twig impact** | Overview readiness regions; strip partials |
| **SCSS impact** | Health strip; checklist density |
| **Validation** | Fixture events: blocked / ready / Stripe-blocked; confirm no false ready; cache tags preserved |

---

## Slice 4 — Publishing

| | |
| --- | --- |
| **Scope** | Publishing section + Home Focus alignment for Ready state; explicit publish/unpublish UX; dirty/stale/draft conflict messaging clarity. **No** autosave publish. |
| **Risk** | **High** — CSRF publish API, eligibility, Stripe paid-publish gate |
| **Reusable components** | Confirm patterns (07); Publishing section form |
| **Reusable builders** | `PublishEligibilityEvaluator`, `EventStudioPublishController`, PaidPublishStripeGate |
| **Twig impact** | Publishing section + Focus CTA labels |
| **SCSS impact** | Minimal — confirm dialog clarity |
| **Validation** | Publish happy path + blocked path + stale conflict; Network CSRF header; staff vs organiser access unchanged |

---

## Slice 5 — Tickets

| | |
| --- | --- |
| **Scope** | Tickets section body composition per wireframes: organiser language list, progressive disclosure for advanced inventory. Home pulse row links here. |
| **Risk** | **High** — Commerce variations, capacity, stock; do not collapse event≠product model |
| **Reusable components** | Tickets app library; MEL list/row patterns |
| **Reusable builders** | Tickets section plugin + existing ticket services |
| **Twig impact** | Tickets section templates / app mount |
| **SCSS impact** | Section header + row list |
| **Validation** | Create/edit ticket type; capacity display honesty; no product/variation jargon in UI |

---

## Slice 6 — Operations

| | |
| --- | --- |
| **Scope** | Home operational pulse rows; Attendees entry + **Door Mode continuity** (same-app feel); Orders readonly-safe presentation; Messages/Marketing/Analytics emphasis by state (`11`). |
| **Risk** | **High** — Door Mode access/mutations; order ownership; PII; messaging audience; Boost spend honesty |
| **Reusable components** | Attendees workspace Twig; Door Mode route (keep access logic); Orders section |
| **Reusable builders** | Overview builder pulse; attendees controllers; sales summaries |
| **Twig impact** | Overview pulse; attendees header Door CTA; avoid new Manager shell features for organisers |
| **SCSS impact** | Pulse rows; Live emphasis; Door entry |
| **Validation** | Live ≤2 taps to Door; order list scoped to event; no PII leakage; mobile Door targets ≥44px |

---

## Slice 7 — Polish

| | |
| --- | --- |
| **Scope** | Density reduction; empty states; activity grouping; sticky mobile CTA; focus order; `prefers-reduced-motion`; copy pass (PDS 15); Help links audience check. |
| **Risk** | Low–Medium — regressions in spacing/a11y |
| **Reusable components** | Empty states; skeletons if Dashboard patterns apply lightly |
| **Reusable builders** | None new |
| **Twig impact** | Copy + empty partials |
| **SCSS impact** | Whitespace hierarchy; sticky bar; reduced motion |
| **Validation** | `16` checklist + `21` DoD; axe/keyboard pass on Home; 390px Live sticky; `mel:lint` / `mel:build` |

---

## Out-of-slice (explicit)

| Work | When |
| --- | --- |
| Path unify `/studio` → `/vendor/events/{id}` | After DDR-008 Accepted — own PR train |
| Collapse `VendorEventTabsService` / console preprocess | After DDR-009 Accepted |
| Staff-gate / retire Manager organiser entry | DDR-008 Phase 3 |
| New entity fields for lifecycle state machine | Only if repository gap confirmed + asked |
| Dashboard queue duplication on Home | Never |

---

## Recommended delivery order

```text
Hygiene (config) → DDR acceptances → Slice 1 → 2 → 3 → 4 → 5 → 6 → 7
                         ↘ path unification (parallel track, later)
```

Each slice: feature branch · cite PDS + DDRs in PR · stop after slice · human review.

---

## Validation command set (per runtime slice)

```bash
ddev drush cr
ddev drush config:status
ddev composer validate
npm run mel:lint
npm run mel:build
git status --short
git diff
```

Add targeted tests for publish/autosave/Door/access when those slices touch them.

---

## Residual risks after full slice train

- Dual shell until DDR-008 Phase 3  
- Door Mode route may still be `/operations/door` even with chrome continuity  
- Config drift if hygiene skipped  
- Lifecycle detection imperfect without confirmed temporal signals — ask before inventing  

---

## Definition of “Workspace Foundation done”

Slices **1–3** (+ Publishing Focus alignment from Slice 4 labels) ship with:

- Wireframe hierarchy on Home  
- One primary CTA  
- Honest readiness  
- No path rename required  
- PDS citations + DoD gates  

Operations depth (5–6) and polish (7) may follow as Workspace Ops train.
