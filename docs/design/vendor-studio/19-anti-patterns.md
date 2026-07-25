# Vendor Studio — Design Anti-Patterns

**Version:** RC1  
**Status:** Design authority (documentation only)

## Purpose

Prevent regression years from now by naming harmful patterns, why they hurt, and what to do instead.

## Scope

Experience anti-patterns for Vendor Studio. Not a code lint list. Not competitor mockery.

## Audience

Everyone shipping Vendor Studio UI or OS docs.

## Related documents

- [01-vendor-studio-vision.md](01-vendor-studio-vision.md)
- [11-design-tokens.md](11-design-tokens.md)
- [12-dashboard-philosophy.md](12-dashboard-philosophy.md)
- [16-design-review-checklist.md](16-design-review-checklist.md)
- [DDR-004](decisions/DDR-004-component-philosophy.md)

---

## How to use

If a PR introduces an anti-pattern, it fails design review unless Design Authority accepts a documented exception with expiry.

---

### ❌ Card inside card inside card

**Why harmful:** Hierarchy collapses; whitespace and borders multiply; mobile becomes a tunnel of chrome.

**What to do instead:** One interactive card boundary. Nest content with spacing and headings, not nested surfaces.

**Example:** Action item as a single Action Card — not a card wrapping a card wrapping a button bar.

---

### ❌ Three primary buttons

**Why harmful:** Violates one primary action; organisers freeze; conversion and ops speed drop.

**What to do instead:** One primary; secondary/quiet for alternatives; move tertiary into menus.

**Example:** Publish (primary) · Preview (secondary) · Save draft (quiet) — not three filled purples.

---

### ❌ Hidden primary actions

**Why harmful:** Fails Golden Rule; hover-only or overflow-buried CTAs punish mobile and keyboard users.

**What to do instead:** Primary CTA in page header (sticky on mobile when needed); visible without hover.

**Example:** Refund available as a clear row action or detail primary — not only in a hover pencil icon.

---

### ❌ CMS terminology

**Why harmful:** Forces organisers to learn Drupal; destroys trust and increases support.

**What to do instead:** Organiser language ([15](15-copywriting-guide.md), [A01](appendices/A01-glossary.md)).

**Example:** “Event image” not “Add media entity”; “Ticket” not “Product variation”.

---

### ❌ Duplicate navigation

**Why harmful:** Studio vs Manager déjà vu; “Where am I?” fails; maintenance forks forever.

**What to do instead:** One global shell; Event Workspace as contextual app ([02](02-information-architecture.md), [DDR-002](decisions/DDR-002-event-workspace.md)).

**Example:** Orders appear globally and event-filtered — same product, not two nav trees named differently.

---

### ❌ Empty dashboards

**Why harmful:** Silence without a next step abandons first organisers.

**What to do instead:** Honest empty + Create event / Connect Stripe / finish tickets ([12](12-dashboard-philosophy.md)).

**Example:** “Create your first event” Action Card — not a blank warm cream void.

---

### ❌ Decorative metrics

**Why harmful:** Pretty numbers that don’t change decisions slow scanning and invite fake precision.

**What to do instead:** ≤4 decision-supporting KPIs; text truth; no charts without a question ([12](12-dashboard-philosophy.md)).

**Example:** “Tickets sold this week” with drill-down — not a sparkline orchard with no labels.

---

### ❌ Dashboard wallpaper

**Why harmful:** Marketing heroes, badge spam, and widget soup bury the Action Queue.

**What to do instead:** Attention-led composition; operational hero only ([12](12-dashboard-philosophy.md), [03](03-layout-system.md)).

**Example:** Action Queue first — not a full-bleed lifestyle photo with floating promo stickers.

---

### ❌ Overly dense forms

**Why harmful:** Setup pressure skyrockets; errors multiply; mobile fails.

**What to do instead:** Sectioned forms at 800px; progressive disclosure; autosave status where established ([13](13-event-workspace-philosophy.md)).

**Example:** Tickets basics first; advanced capacity rules nested — not a single scrolling field avalanche.

---

### ❌ Multiple competing layouts

**Why harmful:** Inconsistent max-widths teach organisers nothing and create theme debt.

**What to do instead:** Layout intents only ([03](03-layout-system.md), [11](11-design-tokens.md), [DDR-003](decisions/DDR-003-layout-intents.md)).

**Example:** `.mel-layout--workspace` — not hardcoded `1120px` in Twig beside `1080px` elsewhere.

---

### ❌ Different spacing systems

**Why harmful:** Visual noise; “almost aligned” UI feels unfinished; hard to theme dark mode later.

**What to do instead:** 4px spacing scale exclusively ([11](11-design-tokens.md)).

**Example:** `space-4` / `space-6` — not `13px` and `27px` one-offs.

---

### ❌ Fake readiness

**Why harmful:** Organisers publish into failure; trust collapses.

**What to do instead:** Honest readiness with reasons and recovery ([13](13-event-workspace-philosophy.md)).

**Example:** “Blocked: add a ticket” — not a green tick on an unsellable event.

---

### ❌ Playful money

**Why harmful:** Refunds and payouts are high-stakes; whimsy reads as unsafe.

**What to do instead:** Sober copy, confirmation dialogs, state honesty ([15](15-copywriting-guide.md)).

**Example:** “Refund this order” + consequence — not confetti on refund success.

---

### ❌ Colour-only status

**Why harmful:** Fails WCAG and colour-vision users; Door Mode mis-reads are costly.

**What to do instead:** Colour + text + icon ([11](11-design-tokens.md)).

**Example:** Red badge labelled “Failed” — not a lone red dot.

---

### ❌ Staff tools in organiser IA

**Why harmful:** Security, confusion, and audience boundary violations.

**What to do instead:** Diagnostics stay staff-only; organiser Support is Help Centre scoped ([02](02-information-architecture.md)).

**Example:** No “Entity debug” in sidebar.

---

## Design implications

- Checklist DOC3 points here  
- New anti-patterns require a PR to this file (owning list)  
- Exceptions need expiry and owner  

## Future considerations

- Add screenshots library of real regressions (docs-only)  
- Theme lint rules may later encode a subset — design ownership stays here  

## Related references

- [01](01-vendor-studio-vision.md) · [12](12-dashboard-philosophy.md) · [13](13-event-workspace-philosophy.md) · [15](15-copywriting-guide.md) · [16](16-design-review-checklist.md)
