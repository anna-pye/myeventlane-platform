# MyEventLane Vendor Experience Convergence (VX2)

**Product Blueprint & Migration Plan**  
**Status:** Complete — ready to drive the next generation of MyEventLane development  
**Date:** 2026-07-22  
**Runtime:** VX2 Sprint 3 (The Ticket Experience / VX2-04) on branch `feature/vx2-ticket-experience` (2026-07-22); Sprint 2 merged via PR #702; Sprint 1 merged via PR #701  
**Method:** Repository review + synthesis of VX2 product docs and prior audits  
**Language standard:** Organiser (human) · `vendor` (machine / URLs)

---

## Companion documents

| Document | Role |
| --- | --- |
| [`vendor-experience-convergence-information-architecture.md`](vendor-experience-convergence-information-architecture.md) | Objects, relationships, future IA |
| [`vendor-experience-convergence-navigation.md`](vendor-experience-convergence-navigation.md) | One organiser navigation |
| [`vendor-experience-convergence-screen-specifications.md`](vendor-experience-convergence-screen-specifications.md) | Screen-level product specs |
| [`vendor-experience-convergence-language-guide.md`](vendor-experience-convergence-language-guide.md) | Terminology keep / rename / hide |
| [`vendor-experience-convergence-roadmap.md`](vendor-experience-convergence-roadmap.md) | Prioritised roadmap |
| [`vendor-experience-convergence-priority-matrix.md`](vendor-experience-convergence-priority-matrix.md) | Epic scoring matrix |
| [`vendor-experience-convergence-implementation-plan.md`](vendor-experience-convergence-implementation-plan.md) | Shippable epics |
| [`vendor-experience-convergence-success-metrics.md`](vendor-experience-convergence-success-metrics.md) | Measurable success |

**Related prior authority (not replaced):**

- [`vendor-experience-v2.md`](vendor-experience-v2.md) and companions — discovery + principles
- [`vendor-experience-v2-design-principles.md`](vendor-experience-v2-design-principles.md) — permanent UX principles (still authoritative)
- [`vendor-console-v2-route-map.md`](vendor-console-v2-route-map.md)
- [`adoption/mel-vendor-workspace-convergence-plan.md`](adoption/mel-vendor-workspace-convergence-plan.md)

---

## Product mission

MyEventLane exists to help organisers create successful events.

The organiser should think about:

- their event
- their attendees
- their tickets
- their revenue

They should **never** think about:

Drupal · Commerce · Stores · Products · Product Variations · Media entities · Taxonomy · Paragraphs · Entity references

Everything technical becomes invisible.

---

## Design philosophy

Every screen answers: **What is the organiser trying to achieve right now?**  
Not: Which Drupal entity is being edited?

| Principle | Meaning |
| --- | --- |
| Guide | Progressive disclosure, readiness, next-step CTAs |
| Reduce decisions | One primary decision per screen |
| Celebrate progress | Milestones feel earned |
| Hide complexity | Commerce and CMS stay backstage |
| Always show the next step | Empty, error, and success states all answer “now what?” |
| Always explain why | Blocks include reason + recovery |
| One primary action | Secondary actions do not compete |
| Mobile-first | Door Mode and sales monitoring work on a phone |
| Accessible | Contrast, focus, severity not colour-only |
| Warm · Community-focused · Australian English | Grassroots organiser tone |

Permanent principles remain in [`vendor-experience-v2-design-principles.md`](vendor-experience-v2-design-principles.md).

---

## Assumptions

1. URL namespace `/vendor/*` remains for Convergence v1; user-visible copy says **Organiser**.
2. Event Studio remains the builder / publish authority; Event Manager and Studio converge into **one Event Workspace**.
3. `mel_ticket_type` remains the ticket abstraction; Commerce stays hidden.
4. Workspace ownership (ADR-0008) remains the access contract.
5. ~~This pack does **not** change runtime behaviour.~~ **Update (Sprint 1):** organiser-visible language, shell navigation, Create Event gateway alignment, and placeholder redirects are live in code. **Update (Sprint 2):** One Event Workspace shell/nav, Overview home, human readiness, Marketing/Publishing sections, and Manager tab convergence are live in code. **Update (Sprint 3):** One Tickets app in Event Workspace with card UX, empty states, duplicate/archive, progressive Advanced Ticket Tools, and Commerce terminology removed from organiser ticket surfaces.
6. Prior VX2 findings are treated as authoritative unless contradicted by the 2026-07-22 inventory.

---

# Stage 1 — Organiser journey

## Journey map

```text
Visitor
  ↓
Register
  ↓
Become organiser
  ↓
Connect Stripe
  ↓
Create first event
  ↓
Create tickets
  ↓
Publish
  ↓
Promote
  ↓
Watch sales
  ↓
Manage attendees
  ↓
Repeat organiser
```

---

### 1. Visitor

| Field | Detail |
| --- | --- |
| **Goal** | Understand MEL, trust the brand, start creating an event |
| **Questions** | Is this for community organisers? What does it cost? Can I get paid in Australia? |
| **Pain points** | Mixed “vendor / organiser” language on public help; create CTAs may land in console without context |
| **Current** | Public site + `/create-event` gateway; help hubs `/help/organisers` and `/help/vendors` |
| **Ideal** | One public story: create → sell → check in. Single organiser language. CTA lands in guided first-event path |

### 2. Register

| Field | Detail |
| --- | --- |
| **Goal** | Create an account with minimal friction |
| **Questions** | Do I need a business ABN now? Can I explore first? |
| **Pain points** | Account vs organiser profile vs store creation feel like separate products if exposed |
| **Current** | Auth + vendor entity creation paths; SSO callback `/vendor/sso/callback` → dashboard |
| **Ideal** | Soft account creation → immediate “set up your organiser profile” with progress, no Commerce language |

### 3. Become organiser

| Field | Detail |
| --- | --- |
| **Goal** | Complete profile, terms, branding enough to unlock Studio |
| **Questions** | What do I need before I can publish? Why Stripe later? |
| **Pain points** | Onboarding steps vs optional Boost feel uneven; “vendor” in titles |
| **Current** | `/vendor/onboard/*` (account, profile, stripe, branding, first-event, boost, complete) + terms |
| **Ideal** | Action-first: unlock create early; celebrate each milestone; Stripe positioned as “get paid”, not “configure gateway” |

### 4. Connect Stripe

| Field | Detail |
| --- | --- |
| **Goal** | Receive payouts for paid tickets |
| **Questions** | Is my account ready? Why can’t I publish paid tickets? When do I get paid? |
| **Pain points** | Status can feel opaque; return/refresh routes are technical; failures lack recovery copy |
| **Current** | Onboard Stripe + `/stripe/connect`, `/stripe/callback`, `/stripe/manage`, return/refresh under `/vendor/onboard/stripe-*` |
| **Ideal** | Payments health card: Connected / Needs attention / Incomplete — with “why” and “fix it” |

### 5. Create first event

| Field | Detail |
| --- | --- |
| **Goal** | Get a draft event ready without confusion |
| **Questions** | Continue last draft or start new? Where do I edit after create? |
| **Pain points** | Silent draft resume; parallel Studio / Manager / legacy manage; “Event Editor” vs “Event Studio” |
| **Current** | `/vendor/events/create`, draft-choice, Studio workspace, Event Manager `/vendor/events/{id}`, legacy `/vendor/event/*` |
| **Ideal** | One create path → one Event Workspace. Draft choice always explicit |

### 6. Create tickets

| Field | Detail |
| --- | --- |
| **Goal** | Price and capacity that match the event |
| **Questions** | Free vs paid? VIP? Codes? Why “ticket product”? |
| **Pain points** | Studio Tickets vs Advanced ticket manager vs ticket types/groups/widgets; Commerce leakage |
| **Current** | Studio `/studio/tickets`; manager `/tickets`; advanced `/tickets/types|groups|access-codes|widgets` |
| **Ideal** | One Tickets app: types → pricing → capacity → optional codes/widgets. Advanced collapsed until needed |

### 7. Publish

| Field | Detail |
| --- | --- |
| **Goal** | Make the event live and shareable |
| **Questions** | What’s blocking me? Will attendees see this? |
| **Pain points** | Readiness vs moderation vs Stripe gates explained inconsistently |
| **Current** | Studio publish + readiness; workspace publish; build wizard (staff) |
| **Ideal** | Single Publish readiness checklist with human blockers and celebration on success |

### 8. Promote

| Field | Detail |
| --- | --- |
| **Goal** | Drive discovery and ticket sales |
| **Questions** | Share link? Boost? What’s the difference? |
| **Pain points** | Grow / Boost / Promote / Marketing language mix; Boost wizard separate from Messages |
| **Current** | `/vendor/boost`, event Boost wizard, Studio messaging, promotion routes |
| **Ideal** | Marketing hub: Share · Boost · Messages · Widgets — one place |

### 9. Watch sales

| Field | Detail |
| --- | --- |
| **Goal** | Know if the event is working |
| **Questions** | How many sold? Revenue? Refunds? What’s trending? |
| **Pain points** | Analytics vs Insights vs Charts vs Studio Insights; Pro bare gates |
| **Current** | `/vendor/analytics`, `/vendor/insights`, event analytics, Studio analytics, charts/exports |
| **Ideal** | One Analytics product: free business pulse + Pro depth |

### 10. Manage attendees

| Field | Detail |
| --- | --- |
| **Goal** | Message, check in, export, refund |
| **Questions** | Where is the guest list? RSVP vs paid? Door Mode? |
| **Pain points** | Attendees / Ticket holders / RSVPs / Waitlist / 4 check-in stacks |
| **Current** | Multiple modules and path grammars (plural + singular) |
| **Ideal** | One Attendees workspace per event + global Attendees; Door Mode as check-in |

### 11. Repeat organiser

| Field | Detail |
| --- | --- |
| **Goal** | Duplicate, reuse venues/questions, grow revenue |
| **Questions** | Can I copy last year’s event? Where are venues and templates? |
| **Pain points** | Settings satellites; questions library separate; series UX legacy |
| **Current** | Duplicate routes, venues under settings, question library, series stubs |
| **Ideal** | “Create from previous”, reusable venues & questions, clear Settings hub |

---

# Stage 2 — Convergence inventory

Classification of organiser-facing surfaces. Full route tables live in the IA and navigation companions; this stage states the migration rule.

### KEEP (canonical, polish in place)

| Surface | Current URL (examples) | Future |
| --- | --- | --- |
| Organiser dashboard | `/vendor/dashboard` | Dashboard |
| Events list | `/vendor/events` | Events |
| Event Studio create | `/vendor/events/create` | Event Workspace · Create |
| Studio sections (information, content, branding, tickets, …) | `/vendor/events/{id}/studio/*` | Event Workspace sections |
| Stripe Connect manage | `/stripe/manage` | Payments · Connection |
| Boost wizard | `/vendor/events/{id}/boost/wizard/*` | Marketing · Boost |
| Door Mode (canonical ops) | `/vendor/events/{id}/operations/door` | Attendees · Door Mode |
| Support | `/vendor/support` | Support |
| Help | `/vendor/help` | Support · Help |
| Settings | `/vendor/settings` | Settings |
| Onboarding spine | `/vendor/onboard/*` | Onboarding (copy polish) |

### MERGE

| Current | Into | Why |
| --- | --- | --- |
| Event Manager workspace + Event Studio | **Event Workspace** | Two products for one event |
| `/vendor/analytics` + `/vendor/insights` + event analytics + Studio Insights + charts | **Analytics** | Triplicate reporting |
| Attendee Messaging + Studio messaging + Pro message + messaging brand | **Messages** | Fragmented comms |
| Attendees + RSVPs + Waitlist + Ticket holders | **Attendees** | One guest model in UI |
| Check-in stacks (checkin module, RSVP scan, tickets PWA, Door Mode) | **Door Mode** | One door experience |
| Payouts + Stripe + refunds + finance BAS + billing | **Payments** | Money belongs together |
| Boost hub + promotion + audience growth CTAs | **Marketing** | One growth home |
| Branding (settings, Pro, messaging brand, Studio branding) | **Settings + Event Workspace branding** | Split global vs event |

### REDIRECT

| Current URL | Expected redirect | Priority |
| --- | --- | --- |
| `/vendor/events/add` | Event create (already redirects — keep) | Done / verify |
| `/vendor` | `/vendor/dashboard` | Keep |
| `/vendor/studio`, `/vendor/events/{id}/editor` | Event Workspace | P0 |
| `/vendor/event/{id}/*` (singular legacy) | Plural Event Workspace equivalents | P0 |
| `/vendor/events/{id}/build/*` (vendor users) | Studio / Workspace | Keep staff-only or redirect |
| Studio merchandise/addons aliases | `/studio/extras` | Keep |
| Studio promotions | `/studio/messaging` → future Messages | P1 |
| `/vendor/charts/*` | Analytics event charts | P2 |
| `/help/vendors` | `/help/organisers` | P1 |

### RETIRE

| Surface | Reason | Replacement |
| --- | --- | --- |
| Manage-event placeholders (promote / payments / comms / advanced stubs) | Dead ends | Workspace + Payments + Messages |
| Duplicate nav builders disagreeing on tabs | Trust damage | One tab service |
| Staff build wizard for organisers | Parallel product | Studio only |
| Legacy singular RSVP/attendee paths (after 301) | Path grammar debt | Plural canonical |

### HIDE (capability exists; never organiser-labelled)

| Concept | Future label / home |
| --- | --- |
| Ticket Product / Product / Variation | Tickets |
| Commerce (Studio nav group) | Tickets & sales |
| Store / Gateway | Payments |
| Media / Taxonomy / Paragraph / Node | Images / Categories / Content (human terms only) |
| Order item | Order line (or just Order detail) |

### RENAME

| Current label | Future label |
| --- | --- |
| Vendor settings | Organiser settings |
| Vendor help | Help |
| Ticket holders | Attendees |
| Event Editor | Event (open in Workspace) |
| Event Manager | Event Workspace |
| Grow event | Marketing |
| Insights (when meaning analytics) | Analytics |
| Attendee Messaging | Messages |
| Advanced ticket manager | Advanced ticket tools (progressive disclosure) |
| Visibility & updates | Publishing & updates (or Messages section) |
| Collection / Fulfilment | Collection (merch pickup) — keep human term |

---

## Inventory examples (required pattern)

```text
/vendor/events/add
  → REDIRECT
  → Event Workspace · Create

Ticket Product
  → HIDE
  → Tickets

Vendor Comms / Attendee Messaging / Pro Message
  → MERGE
  → Messages

Analytics / Insights / Charts / Reporting
  → MERGE
  → Analytics

Legacy manage placeholders
  → RETIRE
```

---

# Stages 3–10 — Blueprints (summary)

Detailed blueprints live in companion docs. Summary:

| Stage | Verdict |
| --- | --- |
| **3 Navigation** | One shell: Dashboard · Events · Attendees · Orders · Messages · Payments · Analytics · Marketing · Settings · Support |
| **4 Workspace** | One Event Workspace — builder + ops, no duplicate nav |
| **5 Tickets** | Ticket application language only |
| **6 Attendees** | Paid + RSVP + waitlist + Door Mode + export + refunds |
| **7 Messages** | One Messages product |
| **8 Analytics** | Business dashboard + event depth |
| **9 Payments** | Stripe, payouts, refunds, tax — never Commerce |
| **10 Language** | Full guide in language companion |

---

# Stage 11 — Epics (index)

| Epic | Name | Launch priority |
| --- | --- | --- |
| VX2-01 | Onboarding | P1 |
| VX2-02 | Dashboard | P1 |
| VX2-03 | Workspace | P1 |
| VX2-04 | Tickets | P2 |
| VX2-05 | Attendees | P2 |
| VX2-06 | Messages | P2 |
| VX2-07 | Payments | P2 |
| VX2-08 | Analytics | P1 free pulse / P3 depth |
| VX2-00 | Trust & route integrity (P0) | P0 |
| VX2-09 | Marketing | P1–P2 |
| VX2-10 | Settings & Support | P3 |

Full scoring: [`vendor-experience-convergence-priority-matrix.md`](vendor-experience-convergence-priority-matrix.md).

---

# Stage 12 — Success metrics (index)

Primary north stars:

1. Time to first published event
2. Stripe completion rate
3. Support tickets per active organiser
4. Events published / month
5. Paid GMV and Boost conversion
6. Organiser 30-day retention

Full definitions: [`vendor-experience-convergence-success-metrics.md`](vendor-experience-convergence-success-metrics.md).

---

# Stage 13 — Deliverables checklist

- [x] `docs/vendor-experience-convergence.md` (this file)
- [x] `docs/vendor-experience-convergence-roadmap.md`
- [x] `docs/vendor-experience-convergence-information-architecture.md`
- [x] `docs/vendor-experience-convergence-navigation.md`
- [x] `docs/vendor-experience-convergence-screen-specifications.md`
- [x] `docs/vendor-experience-convergence-language-guide.md`
- [x] `docs/vendor-experience-convergence-implementation-plan.md`
- [x] `docs/vendor-experience-convergence-priority-matrix.md`
- [x] `docs/vendor-experience-convergence-success-metrics.md`

---

## Sprint 1 runtime status (VX2-00)

| Area | Status |
| --- | --- |
| Shell nav → Convergence IA | **Done** — `VendorNavBuilder` + vendor theme sidebar |
| Language sweep (Commerce / Insights / Ticket holders / Grow / Event Editor…) | **Done** — organiser UI + Studio groups |
| Create Event → gateway / draft-choice | **Done** — shell CTA + account menu |
| Placeholder manage routes | **Done** — redirect (not “coming soon”) |
| Payments / Orders hubs | **Deferred** — labels point at existing payouts / event-scoped orders |
| Door Mode check-in merge | **Deferred** — VX2-05 (removed from shell only) |

## Sprint 2 runtime status (VX2-03 One Event Workspace)

| Area | Status |
| --- | --- |
| One Workspace shell language | **Done** — Event Workspace topbar/sidebar; Events breadcrumb; View page + Publish |
| Convergence secondary nav | **Done** — Overview → Details → Schedule → Venue → Images → Tickets → Attendees → Messages → Marketing → Orders → Analytics → Publishing → Settings |
| Advanced sections off primary nav | **Done** — Content, Guest questions, Capacity, Merch & add-ons, Collection hidden |
| Overview as organiser home | **Done** — readiness, next action, sales, Stripe, marketing, analytics snapshot |
| Human publishing readiness | **Done** — checklist nouns + “You’re almost there…” explanations |
| Empty states / celebration | **Done** — AU warm empty copy; publish celebration without emoji gimmick |
| Manager dual chrome | **Converged** — tabs/preprocess point at Workspace routes; staff mission-control retained |
| Dedicated Schedule/Venue field forms | **Partial** — nav sections share Event information form (field split deferred) |
| Full Attendees / Door depth | **Deferred** — VX2-05 |
| Tickets app depth | **Done** — VX2-04 (see Sprint 3) |

## Sprint 3 runtime status (VX2-04 The Ticket Experience)

| Area | Status |
| --- | --- |
| One Tickets app in Event Workspace | **Done** — `workspace_tickets` stack: booking mode → ticket cards → preview → Advanced |
| Ticket cards (name, price, capacity, availability, status, sales) | **Done** — `EventStudioOperationalTicketsForm` |
| Primary CTA **Add Ticket** | **Done** — empty-state + details + mobile sticky CTA |
| Duplicate / Archive | **Done** — lifecycle `duplicateTicketOnEvent` + quick actions |
| Progressive **Advanced Ticket Tools** | **Done** — codes, groups, widgets, settings, inventory/sync nested |
| Commerce Product / Variation / SKU organiser copy | **Removed** from ticket manager + booking product autocomplete hidden for organisers |
| Instrumentation hooks | **Partial** — logger + JS hooks for ticket_created/updated/archived, advanced_tools_opened, access_code_created, widget_created; full analytics pipeline deferred |
| Redirect Advanced manager to Workspace-only | **Partial** — manager demoted with Workspace CTA; deep routes retained under Advanced |

---

## Executive verdict

**Biggest organiser friction:** Parallel products for one event (Studio vs Manager vs legacy) plus fragmented Attendees, Messages, Analytics, and Check-in — compounded by Commerce language leaks.

**Biggest revenue opportunities:** Faster first publish; higher Stripe completion; free Analytics pulse that earns Pro; clearer Boost / Marketing; fewer support dead ends that abandon paid flows.

**Vendor Experience Convergence blueprint is complete.** Sprint 1 delivered Trust, Language & Navigation; Sprint 2 delivered One Event Workspace; Sprint 3 delivered The Ticket Experience; remaining epics follow the roadmap.
