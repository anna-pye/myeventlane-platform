# Workspace Zones

**Sprint:** VS1.1 — Workspace Zones  
**Status:** ✅ **APPROVED · FROZEN** (Product Owner 2026-07-25) — part of Vendor Studio v1  
**Authority:** First-class Vendor Studio design principle (not a visual-language footnote)  
**Pack location:** `docs/design/vendor-studio-visual/07-workspace-zones.md` (discovery with B.5; governance via PDS INDEX / poster / Zone Gate)  
**Related:** [03-option-b5.md](03-option-b5.md) · PDS · [Component Catalogue](../vendor-workspace-v2/23-vendor-component-catalogue.md) · Workspace Foundation  
**Constraint:** Design system stable — no further design expansion. Next work is implementation (VL-1…VL-6).

---

## Purpose

Vendor Studio no longer composes pages as a stack of components.

It composes pages as **spaces**.

```text
Components live inside spaces.
Spaces create rhythm.
Rhythm creates calm.
```

This document is the **master composition guide** for every Vendor Workspace page. Visual Language B.5 defines *how* surfaces look. Workspace Zones define *where* they sit and *how* attention flows.

| Layer | Owns |
| --- | --- |
| PDS | Product philosophy, IA, frozen contracts |
| **Workspace Zones (this doc)** | Page rhythm: Identity → Guidance → Work → Outcome |
| Visual Language B.5 | Colour, type, cards, motion, feel |
| Component Catalogue | Freeze ledger (Hero, MC, Launch Centre, …) |

**Build order (PO):** PDS → Workspace Zones → Visual Language → Component Catalogue → Implementation.

---

## Zone Test (design test)

Every future Workspace page must answer:

| Zone | Question |
| --- | --- |
| Identity | Where am I? |
| Guidance | What should I do next? |
| Work | How do I do it? |
| Outcome | What happened? |

If any page cannot answer those four questions clearly, **it is not finished**.

---

## Zone Gate (governance)

Every new Workspace PR must begin with:

```text
Zone map

Identity

Guidance

Work

Outcome
```

**before any screenshots.**

If someone cannot produce that map, the page has not been designed yet.

Enforced in: [PDS 16](../vendor-studio/16-design-review-checklist.md) · [PDS 21](../vendor-studio/21-definition-of-done.md) · [PR template](../../../.github/PULL_REQUEST_TEMPLATE.md).

---

## The four permanent zones

Every Event Workspace page is composed from up to four zones, always in this order when present:

```text
ZONE 1  Identity     Where am I?
   ↓
ZONE 2  Guidance     What should I do next?
   ↓
ZONE 3  Work         How do I do it?
   ↓
ZONE 4  Outcome      What happened?
```

Zones may be **empty or omitted** when inappropriate (e.g. no Outcome until success/error/empty applies). Zones must **never reorder**. Zones must **never compete** for the same job.

---

### ZONE 1 — Identity

| | |
| --- | --- |
| **Purpose** | Orient the organiser |
| **Answers** | Where am I? |
| **Contains** | Hero · event identity · date · venue · status · primary CTA · secondary View/Share as chrome allows |
| **Does not contain** | Forms · editing · metrics · checklists · tables · multiple cards · Mission Control · Launch Centre body |
| **Feel** | Confident · Calm · Immediate |

**Rules**

1. No work happens here.
2. One authoritative primary CTA only (Hero / CTA resolver — frozen).
3. No multiple cards — Identity is chrome, not a card wall.
4. Visual weight: event name and primary CTA lead; meta is quiet.
5. Present on **every** Event Workspace page (shell constant).

---

### ZONE 2 — Guidance

| | |
| --- | --- |
| **Purpose** | Direct attention to the next useful action |
| **Answers** | What should I do next? |
| **Contains** | Mission Control · assistant / Helper tone guidance · **one** recommendation · short context |
| **Does not contain** | Operational tables · long forms · multi-priority lists presented as equals · Launch Centre full bands · KPI walls |
| **Feel** | Helpful · Calm · Human |

**Rules**

1. **Only one recommendation** (next action). Never multiple competing priorities as co-equal primaries.
2. Minimal interaction — open a fix link, acknowledge a hint; not bulk edit.
3. Mission Control is the canonical Guidance surface on **Home**.
4. On non-Home sections, Guidance is **optional and lighter**: a single next-action line, Soft Sky strip, or “Use Publish in the header” hint — not a second Mission Control.
5. Stripe Connect / approved exceptions remain Guidance-owned when they appear on Home — not duplicated as Hero rivals.

---

### ZONE 3 — Work

| | |
| --- | --- |
| **Purpose** | Enable the organiser to complete the section’s job |
| **Answers** | How do I do it? |
| **Contains** | Launch Centre · Information editors · Tickets · Orders · Messages · Marketing · Analytics · forms · tables · editors · section headers · earned soft panels |
| **Does not contain** | A second Hero · a second Mission Control · celebration that blocks the task |
| **Feel** | Focused · Capable · Professional |

**Rules**

1. Largest Workspace area on section pages.
2. Minimal decoration; editorial spacing (B.5 / Option A breath).
3. Panels only when they earn it; cards earn their existence.
4. Forms and operational data belong here — nowhere else.
5. One dominant Work purpose per page (Tickets work ≠ Marketing work).
6. Launch Centre lives entirely in Work (its internal bands are Work sub-rhythm, not new zones).

---

### ZONE 4 — Outcome

| | |
| --- | --- |
| **Purpose** | Reflect result, recovery, or absence |
| **Answers** | What happened? |
| **Contains** | Success · errors · empty states · reports summaries that close a loop · aftercare · calm celebration |
| **Does not contain** | Primary setup forms · the only path to do the work · competing Hero CTAs |
| **Feel** | Reassuring · Confident · Optimistic |

**Rules**

1. Appears **only when appropriate**.
2. Never dominates Work — Outcome follows or overlays briefly; Work remains recoverable.
3. Celebrates calmly; always offers a clear next action.
4. Empty states sit in Outcome (or Outcome-within-Work) with Helper tone — not three promo cards.
5. Launch Success / publish handoff is Outcome; “After you publish” info preview on Ready state may sit at the end of Work as aftercare **preview** (informational) — true celebration is Outcome after publish.

---

## Zone transitions

### Spacing rhythm

Whitespace **separates zones more than borders**.

| Transition | Desktop | 390px | Intent |
| --- | --- | --- | --- |
| Shell → Identity | Existing chrome padding | Existing | Immediate orientation |
| Identity → Guidance | space-8–12 | space-6–8 | Soft step into “what next” |
| Guidance → Work | space-10–16 | space-8–12 | **Primary breath** — largest intentional gap |
| Work internal bands | space-6–10 | space-4–8 | Sub-rhythm inside Work |
| Work → Outcome | space-8–12 | space-6–8 | Reflect without a hard admin rule |
| Zone omission | Collapse gap; do not leave a blank “missing card” | Same | Absent zone = no ghost panel |

Borders and elevation must not fake zone separation when spacing can do it.

### Visual rhythm

```text
Identity     high clarity, low decoration, scarce purple (CTA)
Guidance     one soft panel or strip, Soft Sky next-action, human copy
Work         editorial titles + dense capability; flat first, panels earned
Outcome      brief affirmative or honest empty/error; then release attention
```

### Typography hierarchy across zones

| Zone | Title role | Body |
| --- | --- | --- |
| Identity | Event name (chrome hierarchy) | Quiet meta (date · venue) |
| Guidance | Short “what needs you” / MC title | One recommendation sentence |
| Work | Section / band H2–H3 (editorial when narrative) | Operational body 16px |
| Outcome | Affirming or honest H2 | One sentence + next link/button |

Typography creates hierarchy **before** elevation. A Work band title should not outshout the event name in Identity.

### Natural reading path

1. Land → Identity answers location and primary verb.  
2. Eyes drop → Guidance answers next step (especially on Home).  
3. Commit to section → Work is where time is spent.  
4. Complete or fail → Outcome closes the loop, then returns attention to Identity CTA or Work.

On 390px the path is strictly vertical. Sticky Identity CTA may remain visible; it must not visually invent a fifth zone.

---

## Relationship to components

Frozen / catalogue components map to zones. Visual expression may change under B.5; **zone membership and product contracts do not**.

| Component | Primary zone | May appear elsewhere? |
| --- | --- | --- |
| **Hero** (event chrome + primary CTA) | **Identity** | No — never in Guidance/Work/Outcome as a second hero |
| **Primary CTA (authoritative)** | **Identity** | Resolver may *point* into Work (e.g. Continue setup → Publishing); control stays in Identity |
| **Mission Control** | **Guidance** | Home only as full MC. Non-Home: do not relocate full MC into Work |
| **Hero publish hint** | **Guidance** or end of Work (Launch) | Text hint only — never a second Publish control |
| **Launch Centre** | **Work** | No — Publishing section only |
| **Launch checklist** | **Work** (inside Launch Centre) | Aligned lists may reuse pattern inside Work elsewhere; not in Identity |
| **Visibility disclosure** | **Work** (Launch progressive) | Settings may hold fuller controls — still Work on Settings page |
| **After you publish** (info band) | **Work** (aftercare preview) or soft bridge to Outcome | Not Identity |
| **Launch Success / Success panel** | **Outcome** | Transient overlay/region OK; must not become permanent Identity content |
| **Error panel** | **Outcome** (or inline Outcome within Work) | Inline field errors stay in Work forms; page-level failure → Outcome |
| **Empty state** | **Outcome** (within the section’s Work column) | Replaces Work content when empty; does not add Identity cards |
| **Forms** | **Work** | Never Identity; never full forms in Guidance |
| **Tables / editors** | **Work** | Never Guidance |
| **Cards / soft panels** | Mostly **Work**; MC panel in **Guidance** | Identity: no card wall. Outcome: at most one soft success/empty surface |
| **Section header** | Top of **Work** | Not a second Identity |
| **Metrics (≤4)** | **Work** (decision support) | Never Identity; never as Guidance replacement for next action |
| **Illustrations / Guide moments** | **Outcome** (empty/success) or rare first-run Guidance | Not inside Identity; not default inside Mission Control |

---

## Page mapping

Shell Identity (Zone 1) is constant on all Event Workspace pages below.

### Home (Overview / Mission Control)

| Zone | Content |
| --- | --- |
| **1 Identity** | Hero — event, status, authoritative CTA, View |
| **2 Guidance** | Mission Control — **one** next recommendation + checklist context |
| **3 Work** | Light ops snapshots / deep links only (Tickets, Orders, …) — not full tables; muted setup links |
| **4 Outcome** | Usually omitted; empty first-run or post-action toast/handoff when needed |

**Dominant purpose:** Guidance (what needs me). Work stays subordinate on Home.

---

### Information

*(Event information / setup content — Details, Schedule, Venue, Images and kindred editors as implemented in Workspace IA.)*

| Zone | Content |
| --- | --- |
| **1 Identity** | Hero |
| **2 Guidance** | Optional single line if readiness points here (“Add a cover to improve discovery”) — not full MC |
| **3 Work** | Section header + forms / media editors — primary mass of the page |
| **4 Outcome** | Save success, validation Outcome, empty media state |

**Dominant purpose:** Work (edit information).

---

### Publishing (Launch Centre)

| Zone | Content |
| --- | --- |
| **1 Identity** | Hero — Publish / Share / Continue setup as resolver dictates |
| **2 Guidance** | Optional publish hint only (“Use Publish in the header”) — **no** second Publish; no full MC |
| **3 Work** | Launch Centre bands: Ready to Launch → Checklist → Controls → Aftercare preview |
| **4 Outcome** | Launch Success after publish; publish errors; Ready-state aftercare is Work preview until live celebration |

**Dominant purpose:** Work (launch confidence) with Identity owning the go-live verb.

---

### Tickets

| Zone | Content |
| --- | --- |
| **1 Identity** | Hero |
| **2 Guidance** | Optional one-liner if tickets block readiness |
| **3 Work** | Ticket types, pricing, capacity editors/tables |
| **4 Outcome** | Empty “no tickets yet”; save/error Outcome |

**Dominant purpose:** Work.

---

### Messages

| Zone | Content |
| --- | --- |
| **1 Identity** | Hero |
| **2 Guidance** | Optional (“1 draft needs sending”) — single recommendation |
| **3 Work** | Event-scoped message list, compose, history |
| **4 Outcome** | Send success; empty inbox/list |

**Dominant purpose:** Work.

---

### Orders

| Zone | Content |
| --- | --- |
| **1 Identity** | Hero |
| **2 Guidance** | Optional (“2 refunds need review”) — one priority |
| **3 Work** | Orders table / Commerce truth · filters · row actions |
| **4 Outcome** | Empty orders; action confirmations |

**Dominant purpose:** Work. Money treatment stays sober (B.5 / PDS).

---

### Marketing

| Zone | Content |
| --- | --- |
| **1 Identity** | Hero (often Share when live) |
| **2 Guidance** | Optional single growth nudge — never FOMO stack |
| **3 Work** | Share tools, Boost hubs, campaigns as designed |
| **4 Outcome** | Share/Boost confirmation; empty “not shared yet” |

**Dominant purpose:** Work (grow). Boost never steals Identity primary when Share is authoritative.

---

### Analytics

| Zone | Content |
| --- | --- |
| **1 Identity** | Hero |
| **2 Guidance** | Rare — only if a single insight demands action (“Ticket type B almost sold out — review Tickets”) |
| **3 Work** | Charts/metrics ≤ decision set; reports |
| **4 Outcome** | Empty analytics; report-ready summaries that close a question |

**Dominant purpose:** Work (understand). Metrics do not migrate into Identity.

---

### Zone presence matrix

| Page | Z1 Identity | Z2 Guidance | Z3 Work | Z4 Outcome |
| --- | --- | --- | --- | --- |
| Home | Always | **Full MC** | Light | As needed |
| Information | Always | Optional light | **Full** | As needed |
| Publishing | Always | Hint only | **Launch Centre** | Success/errors |
| Tickets | Always | Optional light | **Full** | As needed |
| Messages | Always | Optional light | **Full** | As needed |
| Orders | Always | Optional light | **Full** | As needed |
| Marketing | Always | Optional light | **Full** | As needed |
| Analytics | Always | Rare light | **Full** | As needed |

Settings (when present in shell): same pattern as Information — Identity + Work (+ Outcome); Guidance only if a single security/billing next step applies.

---

## Visual hierarchy

```text
Identity     (orient — scarce purple CTA)
    ↓  breath
Guidance     (one next step — soft panel / strip)
    ↓  primary breath
Work         (largest — focused capability)
    ↓  soft breath
Outcome      (reflect — then release)
```

### Weight rules

1. **Components never compete across zones** — two Publish buttons across Identity and Work is a zone violation, not a styling issue.
2. **Every page has one dominant purpose** — Home → Guidance; most sections → Work; success beat → Outcome briefly.
3. **Whitespace separates zones more than borders.**
4. **Typography before elevation.**
5. **Cards earn their existence** — default flat in Work narrative; soft panel for MC and earned groups.
6. **The Workspace is a calm operating environment** — not a dashboard of equal cards.
7. **Hero owns orientation** (Identity).
8. **Mission Control owns guidance** (Guidance on Home).
9. **The page owns work** (Work).
10. **Outcome owns reflection** (Outcome).

### Anti-hierarchy (forbidden feel)

```text
Card
Card
Card
Card
```

Equal-weight panels across Identity + Guidance + Work destroy rhythm. If a mockup reads as four stacked admin cards, it fails Zone System review.

---

## Design principles (zone system)

1. Components never compete across zones.  
2. Every page has one dominant purpose.  
3. Whitespace separates zones more than borders.  
4. Typography creates hierarchy before elevation.  
5. Cards earn their existence.  
6. The Workspace is a calm operating environment.  
7. The Hero owns orientation.  
8. Mission Control owns guidance.  
9. The page owns work.  
10. Outcome owns reflection.  
11. Guidance carries **one** recommendation only.  
12. Zones never reorder; optional zones collapse without ghosts.  
13. B.5 visual language applies *inside* zones — zones do not invent new tokens.  
14. Architecture, eligibility, and CTA resolver remain frozen — zones are composition, not behaviour.

---

## Examples

### Good — Desktop Home

```text
┌─ ZONE 1 Identity ─────────────────────────────────────────────┐
│ ← Events   Summer Night Market   Live   [ Share event ]  View │
│            Sat 18 Oct · Fitzroy                               │
└───────────────────────────────────────────────────────────────┘
         ║  breath
┌─ ZONE 2 Guidance ─────────────────────────────────────────────┐
│ Mission Control (one soft panel)                              │
│ ┌ Soft Sky ─────────────────────────────────────────────────┐ │
│ │ Next: Share with your community                           │ │
│ └───────────────────────────────────────────────────────────┘ │
│ ✔ Setup complete · ○ Optional cover                         │ │
└───────────────────────────────────────────────────────────────┘
         ║  primary breath
┌─ ZONE 3 Work (light) ─────────────────────────────────────────┐
│ Tickets →     Orders →     Messages →                         │
│ (snapshots / links — not full tables)                         │
└───────────────────────────────────────────────────────────────┘
         ║
   ZONE 4 omitted
```

**Why good:** Clear Identity → one Guidance recommendation → light Work. No card wallpaper.

### Poor — Desktop Home

```text
┌ Hero card ┐ ┌ KPI ┐ ┌ KPI ┐ ┌ KPI ┐ ┌ KPI ┐
┌ MC card ┐ ┌ Launch checklist card ┐ ┌ Orders table card ┐
┌ Publish now card ┐ ┌ Boost card ┐
```

**Why poor:** Zones collapsed into competing cards; Guidance and Work fight; Identity diluted; dual Publish risk.

---

### Good — Desktop Publishing (Ready)

```text
┌─ Z1 Identity — [ Publish event ] ─────────────────────────────┐
└───────────────────────────────────────────────────────────────┘
         ║
┌─ Z2 Guidance (hint only) ─────────────────────────────────────┐
│ Use Publish in the header when you’re ready.                  │
└───────────────────────────────────────────────────────────────┘
         ║  primary breath
┌─ Z3 Work — Launch Centre ─────────────────────────────────────┐
│ Ready to launch          (flat editorial)                     │
│ Launch checklist         (one soft panel)                     │
│ Status — Hero owns Publish                                    │
│ After you publish        (preview)                            │
└───────────────────────────────────────────────────────────────┘
```

**Why good:** Go-live verb stays in Identity; Work narrates confidence; no Settings dump; no second Publish.

### Poor — Publishing

```text
Hero [Publish]
MC full checklist (again)
Settings form card
Publish now card
Boost primary card
```

**Why poor:** Guidance duplicated; Work is admin; Outcome/marketing compete; zone competition.

---

### Good — 390px Orders

```text
┌─ Z1 Identity ───────────────┐
│ Event · Live                │
│ [ Share event ]             │
├─ Z2 (optional one line) ────┤
│ 2 refunds need review →     │
├─ Z3 Work ───────────────────┤
│ Orders                      │
│ [filters]                   │
│ row · row · row             │
├─ Z4 if empty ───────────────┤
│ No orders yet · …           │
└─────────────────────────────┘
```

**Why good:** Vertical zone order; one Guidance nudge; Work owns the table; empty is Outcome.

### Poor — 390px Orders

```text
KPI cards ×4
Full Mission Control
Orders rows as nested cards in cards
Floating Boost sticker
```

**Why poor:** No rhythm; Guidance overweight; Work unreadable; Identity lost.

---

## Validation

| Source | Check | Result |
| --- | --- | --- |
| Visual Language B.5 | Zones use B.5 surfaces (cream, soft panel, Soft Sky, card budget) | **Pass** — no new palette |
| PDS | One primary action; guide don’t overwhelm; cards earn size; Golden Rule | **Pass** — zones operationalise these |
| Component Catalogue | Hero / MC / Launch Centre remain frozen; zone doc assigns membership only | **Pass** — no unfreeze, no redesign |
| Workspace Foundation | Shell + section model; Home = MC; sections = Work | **Pass** — maps Foundation, does not replace it |
| Launch Centre `18`/`17`/`19` | Launch in Work; Publish in Identity; no dual Publish | **Pass** |
| Architecture / behaviour | Eligibility, routes, resolver, Commerce | **Unchanged** — composition only |
| Component redesign | None | **Confirmed** |
| Behaviour changes | None | **Confirmed** |

**Assumption:** “Information” maps to event information/setup sections in Workspace IA (Details / Schedule / Venue / Images and equivalents). If PO names a single `/information` route differently, zone mapping still applies to that section’s job.

---

## How Zones complete the design language

B.5 answered *how Vendor Studio should feel*. Zones answer *how every Workspace page should breathe*:

```text
Identity  →  Guidance  →  Work  →  Outcome
```

| Without zones | With zones |
| --- | --- |
| Component · Component · Component | Space · Rhythm · Calm |
| Equal cards | Dominant purpose per page |
| Competing CTAs across the fold | Hero / MC / page / outcome each own one job |

### Inheritance rule for future pages

Any new Event Workspace page must declare in design review:

1. Zone 1–4 contents (or explicit omission).  
2. Dominant purpose (usually Work; Home = Guidance).  
3. Single Guidance recommendation if Zone 2 present.  
4. Which frozen components are reused — never forked.  
5. B.5 visual expression inside each zone.

No new page ships as a vertical card stack without zone mapping.

---

## Freeze note (PO 2026-07-25)

Workspace Zones are **frozen** with:

- Workspace Foundation  
- Mission Control  
- Launch Centre Composition  
- Vendor Component Catalogue  
- Vendor Studio Visual Language (B.5)  

**No further design work.** Implementation proceeds as VL-1…VL-6 with PO approval between phases.
