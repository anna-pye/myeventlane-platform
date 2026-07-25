# Vendor Workspace v2 — Product Experience Audit

**Status:** Discovery only  
**Branch:** `feature/vendor-workspace-v2-discovery` @ `37fcdc449`  
**Surface under review:** Organiser Event Studio Workspace (`/vendor/events/{node}/studio*`) — product truth after legacy redirects  
**Date:** 2026-07-25  

Ratings: **Excellent · Good · Average · Poor**  
Evidence from repository only (builders, Twig, routes, PDS). No redesign in this document.

---

## Golden Rule probe

Can an organiser opening **this event** quickly answer:

| Question | Rating | Why |
| --- | --- | --- |
| **Where am I?** | **Good** | Studio topbar + sidebar section + event title/status exist (`mel-event-studio-topbar`, section plugins). Path still includes `/studio`, and Overview is labelled **Home** in nav vs **Overview** in PDS — mild orientation tax. Dual shell still in codebase (staff Manager) risks support confusion. |
| **How is my event performing?** | **Good** | Home cards for sales, tickets, attendees, analytics, activity (`EventWorkspaceOverviewBuilder`). Not a second business Dashboard (correct per 13). Depth depends on sales summary / Pro analytics routes. |
| **What needs my attention?** | **Good** | `next_action` + readiness checklist + Stripe attention + presentation alerts. Priority order in `resolveNextRecommendedAction()` is explicit (setup errors → blockers → Stripe → publish → share). Risk: Manager “Today’s focus” + Studio next-action are parallel mental models if staff/docs mix shells. |
| **What should I do next?** | **Good → Excellent** on Home | Dedicated next-action CTA; mobile promotes it (`order: -1` &lt; 720px). Weaker on non-Home sections if strip/guidance is secondary to forms. |

**Overall confidence to run an event from Workspace today:** **Average → Good** — strong Home + publish/autosave stack; weakened by dual-shell debt, Door Mode shell split, nav order vs PDS, and config drift on RSVP isolation.

---

## Dimension scores

### Navigation — **Average**

**Why**

- Studio sidebar is coherent and weighted (Home → … → Settings).
- **Triple tab sources** still exist (`EventStudioSectionManager`, `VendorEventTabsService`, `VendorConsolePagePreprocess`, Manager local tasks).
- Section order **Attendees before Orders** conflicts with PDS 13 (Orders before Attendees).
- Door Mode is a peer tab/link, not nested visually under Attendees as strongly as DDR-002/13 imply.
- Organisers never “see” Manager nav (redirected), but URLs and support docs may still say `/vendor/events/{id}` without `/studio`.

### Hierarchy — **Good**

**Why**

- Home leads with next action + Event Ready, then ops cards — matches 06 Workspace pattern intent.
- Topbar publish vs Home next-action can compete (two primary-looking affordances).
- Builder sections (Details → Images) correctly precede ops for draft lifecycle; live ops emphasis is only partial (nav order helps Attendees; Home cards still equal-weight).

### Cognitive load — **Average**

**Why**

- Progressive disclosure via hidden plugins (questions, capacity, extras) is good.
- Home can still feel dense (ready + next + six cards + activity + boost).
- Historical Studio vs Manager vocabulary may leak in help/docs (`docs/product-language-inventory.md` notes past “Event workspace” naming).
- `/studio` path encodes internal product history.

### Readiness — **Good**

**Why**

- Facade + checklist + humanised labels; green gated through eligibility patterns.
- Publishing section + Home share readiness story.
- Anti-pattern risk remains if any surface marks “ready” without capability (must keep PublishEligibility / Stripe gate authoritative).

### Publishing — **Good**

**Why**

- Dedicated Publishing section; deliberate POST publish with dirty/stale/draft checks.
- Next-action routes unfinished organisers to Publishing.
- Residual: Manager `event_publish` redirects to Studio settings/edit paths — confusing if bookmarks survive.

### Workflow (draft → live) — **Good**

**Why**

- Create → Studio workspace; edit redirects into sections; autosave on capable sections; publish API.
- Lifecycle guidance exists on VM (`lifecycle_guidance`).
- Gap: no single lifecycle “mode” that reweights shell emphasis automatically (see `03-workspace-state-model.md` proposal — design only).

### Trust (money / capacity / status) — **Good**

**Why**

- Autosave does not publish; publish is explicit; Stripe gate on paid paths.
- Orders section marked readonly in Studio metadata — correct caution.
- Config drift: active RSVP view missing `organiser_owned` vs sync — **trust risk** for guest list isolation until reconciled.

### Accessibility — **Average** (cannot fully score without runtime a11y pass)

**Why (repo)**

- Focus/keyboard rules live in PDS 07/08; shell JS has mobile patterns.
- Cannot confirm WCAG AA contrast or Door Mode focus order from code alone without audit run.
- Multiple competing nav implementations increase a11y regression risk.

### Mobile — **Good**

**Why**

- Next-action priority on small screens; mobile_priority metadata on sections; drawer patterns in guidelines.
- Door Mode is the critical stress path — separate route/theme still Manager shell (split experience on phone).

### Operational awareness — **Good**

**Why**

- Today’s focus / next_action / activity feed / sales snapshot.
- Weaker: live/door-imminent “unmistakable status” (13) depends on status badges + organiser noticing Attendees — not a dedicated Live Ops mode yet.

---

## Three Questions Framework (01) on Home

| Question | Present? | Quality |
| --- | --- | --- |
| What needs my attention? | Yes — `next_action`, readiness errors | Good |
| What’s happening with my events? | Partial — **this** event pulse, not portfolio | Good (correct scope) |
| What should I do next? | Yes — primary CTA | Good / Excellent on Home |

---

## Anti-pattern scan (19)

| Anti-pattern | Present? |
| --- | --- |
| Studio/Manager dual product | **Yes in code**; organisers funnelled to one |
| Fake readiness | Not evidenced as intentional; protect via eligibility |
| Nested card chrome | Risk on dense Home — watch card boundaries |
| CMS vocabulary | Mostly avoided in Home copy; monitor forms |
| Global Check-in peer | Door Mode global history mitigated; still separate path |

---

## Summary verdict

Event Workspace Home is already a credible **mission-control seed**. The largest experience debts are **architectural duality** (two shells / path shapes), **nav source fragmentation**, **Door Mode shell split**, and **config drift** on RSVP ownership — not a blank-canvas redesign need.

**Proceed to wireframes only after** DDR resolution on path/shell unity (see `06-implementation-readiness.md`) so wireframes target the correct URL and shell.
