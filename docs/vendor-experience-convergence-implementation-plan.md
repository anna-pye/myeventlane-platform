# Vendor Experience Convergence — Implementation Plan

**Status:** Shippable epic plan  
**Date:** 2026-07-22  
**Related:** [`vendor-experience-convergence-roadmap.md`](vendor-experience-convergence-roadmap.md), [`vendor-experience-convergence-priority-matrix.md`](vendor-experience-convergence-priority-matrix.md)

This plan tells engineering **what to build in what order**. It does not prescribe Drupal APIs beyond naming surfaces already confirmed in-repo.

### Sprint 1 (2026-07-22) — VX2-00 shipped slice

**Branch:** `feature/vx2-trust-language-navigation` (merged PR #701)

**Implemented**

| ID | Item |
| --- | --- |
| C-03 | Create Event shell/account CTAs → `create_event_gateway` (draft-choice path) |
| C-05 | Organiser-visible Commerce / Ticket product / variation language purge |
| C-06 | Studio nav group Commerce → Tickets & sales; Manage Event → Event |
| C-07 | Shell nav Convergence IA (≤10 items) |
| — | Insights → Analytics; Grow → Marketing; Ticket holders → Attendees; Event Editor/Manager labels |
| — | Placeholder manage routes redirect (promote/comms/payments/advanced) |
| — | Onboarding reduced shell: Dashboard · Events · Support |

### Sprint 2 (2026-07-22) — VX2-03 One Event Workspace

**Branch:** `feature/vx2-event-workspace`

**Implemented**

| ID | Item |
| --- | --- |
| C-09 | One Event Workspace nav chrome (Overview → Settings Convergence map) |
| — | Shell rebrand: Event Workspace (not Studio/Manager product names) |
| — | Overview organiser home (next action, readiness, sales, Stripe, marketing, analytics) |
| — | Human readiness checklist + explanatory strip |
| — | Marketing + Publishing workspace sections |
| — | Schedule / Venue / Images / Messages aliases + section plugins |
| — | Empty states + subtle publish celebration |
| — | Manager tabs / preprocess aligned to Workspace routes |

**Deferred (later epics)**

| ID | Item | Epic |
| --- | --- | --- |
| C-01 / C-02 | Check-in / ticket permission drift | VX2-00 follow-up / VX2-05 |
| C-04 | Broader singular path 301 sweep | VX2-00 follow-up |
| C-15 | Payments hub page | VX2-07 |
| C-16 / C-17 | Messages send unification / Orders hub | VX2-06 / later |
| C-12 | Check-in stacks → Door Mode | VX2-05 |
| — | Schedule/Venue dedicated field forms (currently shared information form) | VX2-03 follow-up |
| C-14 | Tickets app (advanced collapsed UX depth) | VX2-04 | **Sprint 3 shipped** |

**Screens / surfaces touched (Sprint 2)**

- Event Workspace sidebar + topbar + readiness strip + Overview template  
- Event Studio section plugins / routes (Convergence IA; advanced hidden)  
- `EventWorkspaceOverviewBuilder`, readiness, empty states, publish handoff  
- Vendor event tabs + console preprocess tabs  
- Convergence docs (this pack)

---

## Operating rules

1. Feature branch per epic or bounded slice.  
2. No commit of secrets; no force-push to main.  
3. Organiser-visible string changes require language-guide compliance.  
4. Access remains server-side; UI hiding is never the control.  
5. Prefer redirects over leaving parallel UIs live.  
6. Validate with DDEV/Drush/theme build as appropriate; do not claim green without running commands.  
7. Map prior R-## tickets from `vendor-experience-v2-roadmap.md` into Convergence IDs (C-## / VX2-##).

---

## VX2-00 — Trust & integrity (P0)

### Outcome

Critical paths do not 403, dump Commerce jargon, or strand organisers on placeholders.

### Scope

- Check-in and ticket permission drift (C-01, C-02)  
- Draft-choice coverage (C-03)  
- Legacy singular redirects (C-04)  
- Commerce / Ticket Product string purge (C-05, C-06)  
- Placeholder manage routes retire or redirect  
- End-to-end organiser journey test plan (manual + BrowserTest where feasible)

### Out of scope

Full Workspace redesign (VX2-03).

### Dependencies

None — start here.

### Exit criteria

- Known P0 dead ends closed or explicitly staff-only — **Sprint 1: placeholders redirect for all users**  
- Studio nav group not labelled Commerce — **Done**  
- Create CTAs respect draft choice — **Done for shell/account menu**  

### Suggested validation

```text
ddev drush cr
# Manual: create event (draft resume), tickets list, door check-in, publish readiness
# Grep organiser templates for Product|Variation|Commerce (spot-check)
```

---

## VX2-01 — Onboarding

### Outcome

Organiser completes profile → Stripe → first event path with progress and celebration.

### Scope

- Progress framing; honest step titles  
- Terms link + clarity  
- Stripe as “Get paid”  
- Celebrate complete; optional Boost track  
- Reduced shell during onboard  

### Business / support / revenue

High activation; fewer “how do I start?” tickets; faster path to paid publish.

### Dependencies

VX2-00 language; Stripe flows remain.

### Exit criteria

New organiser understands next step on every onboard screen.

---

## VX2-02 — Dashboard

### Outcome

Action queue first; business KPIs; Stripe health; clear Create CTA.

### Scope

- Reorder IA (Phase 14 / Convergence)  
- Attention items for refunds, Stripe, drafts  
- Performance/cache follow-ups as needed  

### Quick wins

Template/IA reorder without backend rewrite if view model already has queue + KPIs.

### Exit criteria

First viewport answers “what should I do now?”

---

## VX2-03 — Workspace

### Outcome

One Event Workspace application; one secondary nav; Studio builder + ops without dual products.

### Scope

- Unified section map (Overview → Settings)  
- Retire dual tab/sidebar mental model  
- Redirect Event Editor / Manager labels  
- Publishing celebration  
- Alias routes as needed; single chrome  

### Complexity

High — many modules touch event chrome. Slice by: (1) shell/nav, (2) section redirects, (3) template convergence.

### Dependencies

VX2-00 redirects; nav IA from Convergence navigation doc.

### Exit criteria

Organiser never chooses between “Studio” and “Manager” for the same job.

### Sprint 2 shipped note (2026-07-22)

Organiser chrome is **Event Workspace** with Convergence secondary nav. Studio remains the implementation shell (not redesigned/replaced). Staff may still open mission-control Overview. Schedule/Venue share the information form until a dedicated field split ships.

---

## VX2-04 — Tickets

### Outcome

Ticket application language only; advanced tools progressive.

### Scope

- Primary Tickets UI in Workspace  
- Advanced: groups, codes, widgets behind disclosure  
- Inventory/availability/pricing clarity  
- Zero Product/Variation in UI  

### Dependencies

Workspace shell (VX2-03); mel_ticket_type remains abstraction.

### Exit criteria

GA + VIP creatable without Commerce words; advanced optional.

### Sprint 3 shipped (2026-07-22)

| Item | Status |
| --- | --- |
| Workspace Tickets app shell | Done — `EventStudioSectionRenderer::buildTicketsStack` |
| Card UX + empty state (AU English) | Done |
| Add Ticket / Duplicate / Archive | Done |
| Advanced Ticket Tools disclosure | Done |
| Commerce language cleanup on organiser ticket surfaces | Done |
| Instrumentation hooks (logger + JS) | Done (analytics consumer deferred) |
| Full retirement of `/vendor/events/{id}/tickets` manager | Remaining — demoted, not deleted |
| Guest questions under Advanced only | Remaining — still independent Workspace section when enabled |

### Remaining after Sprint 3

- Optional redirect of Advanced manager Overview → Workspace Tickets  
- Deeper per-ticket drawer edit (vs inline cards) if large inventories need tables  
- Wire `mel:analytics` / logger hooks into the platform analytics pipeline  
- VX2-05 Attendees / Door Mode

## VX2-05 — Attendees

### Outcome

One guest workspace; Door Mode canonical.

### Scope

- Merge paid / RSVP / waitlist presentation  
- Search, filters, bulk, CSV, message, refunds entry  
- Redirect check-in stacks → Door Mode  
- Mobile Door Mode quality  

### Dependencies

VX2-00 check-in trust; Workspace Attendees section.

### Exit criteria

One place for “who’s coming” and “who’s in”.

---

## VX2-06 — Messages

### Outcome

One Messages product globally and per event.

### Scope

- Entry points converge  
- Announcements, updates, cancel, reminders  
- Branding, audience, history, templates, schedule  
- Analytics hooks; AI assist later (no auto-send)  

### Dependencies

Existing vendor_comms live path; Pro message; Studio messaging — decide canonical writer first.

### Exit criteria

One “Message attendees” mental model; brand settings not a separate product.

---

## VX2-07 — Payments

### Outcome

Payments hub: Stripe, payouts, refunds, tax, failures, health.

### Scope

- Hub IA + status card  
- Deep links to existing Stripe/payouts/refunds/finance  
- Refund queue under Payments  
- Failure recovery copy  

### Dependencies

Stripe Connect stability; refund modules.

### Exit criteria

Organiser finds money questions under Payments — never Store/Gateway.

---

## VX2-08 — Analytics

### Outcome

One Analytics product; free pulse; Pro depth.

### Scope

**P1:** Rename Insights→Analytics; free pulse; Pro upgrade screens  
**P3:** Charts, compare, date filters, Boost metrics, exports centre  

### Dependencies

Gating honesty (C-11); merge insights routes gradually.

### Exit criteria

No triplicate product names; free value before upsell.

---

## VX2-09 — Marketing

### Outcome

Boost and share live under Marketing.

### Scope

- Rename Grow → Marketing  
- Event Marketing section  
- Boost wizard remains guided  
- Widgets entry from Marketing or Tickets (single link)  

### Exit criteria

Promote/Grow/Boost synonym soup reduced to Marketing + Boost.

---

## VX2-10 — Settings & Support

### Outcome

Settings hub; warm Support + Help.

### Scope

- Consolidate branding/venues/questions  
- Help language organisers (not vendors)  
- Support without escalation jargon  

### Exit criteria

Organiser finds defaults in Settings; help in Support.

---

## Slice guidance (engineering)

| Slice style | When |
| --- | --- |
| String/IA only | Language, nav labels, empty states |
| Redirect only | Legacy routes |
| Template + view model | Dashboard, overview |
| Multi-module merge | Attendees, Messages, Analytics |
| Commerce-touching | Tickets pricing/capacity — treat as high risk |

Commerce-adjacent slices must explicitly call out: product type, variation, order, payment state, refund, capacity — even when UI hides them.

---

## Mapping from prior R-IDs

| Prior | Convergence |
| --- | --- |
| R-01, R-02, R-03, R-04, R-05, R-06, R-22, R-27 | VX2-00 / C-01–C-06 |
| R-07, R-11 | VX2-01 |
| R-08, R-09, R-10 | VX2-02 + nav |
| R-12 | VX2-03 |
| R-17 | VX2-04 |
| R-15, R-16 | VX2-05 |
| R-20 | VX2-06 |
| R-18, R-21 | VX2-07 |
| R-13, R-14, R-23 | VX2-08 |
| R-24 | VX2-09 |
| R-25, R-30 | VX2-10 / P3 |
| R-28, R-29 | P4 |

---

## What this plan does **not** include

- Runtime code changes in this documentation pack  
- Drupal architecture refactors for their own sake  
- Recreating Event Studio from scratch  
- Exposing admin Commerce UI to organisers  
