# Vendor Dashboard v2 — Research (Sprint 1A)

**Status:** Design package only — no implementation  
**Authority:** Vendor Studio Product Design System (PDS) v1.0.1  
**Canonical route (repository):** `/vendor/dashboard` · `myeventlane_vendor.console.dashboard`  
**Roadmap:** Phase 2 — Dashboard ([10-roadmap.md](../vendor-studio/10-roadmap.md))

---

## 0. Governing documents (complete list)

Every document below governs Dashboard design for this sprint. **Do not invent patterns outside this set.** If a required pattern is missing, stop and recommend a DDR.

### Primary philosophy & composition

| Document | Role |
| --- | --- |
| [12-dashboard-philosophy.md](../vendor-studio/12-dashboard-philosophy.md) | **Primary authority** — mission, hierarchy, Action Queue, KPIs, empty/loading |
| [06-workspace-patterns.md](../vendor-studio/06-workspace-patterns.md) §1 Dashboard | Page-level composition pattern |
| [01-vendor-studio-vision.md](../vendor-studio/01-vendor-studio-vision.md) | Golden Rule, Three Questions, Ten Principles |
| [DESIGN_PRINCIPLES_POSTER.md](../vendor-studio/DESIGN_PRINCIPLES_POSTER.md) | One-page culture reminder |

### Information architecture & shell

| Document | Role |
| --- | --- |
| [02-information-architecture.md](../vendor-studio/02-information-architecture.md) | Dashboard as first global destination |
| [03-layout-system.md](../vendor-studio/03-layout-system.md) | Shell + Dashboard layout intent |
| [DDR-001-shell-navigation.md](../vendor-studio/decisions/DDR-001-shell-navigation.md) | Single global shell — no Dashboard-local nav |
| [DDR-003-layout-intents.md](../vendor-studio/decisions/DDR-003-layout-intents.md) | Dashboard content container intent |
| [DDR-005-mobile-first.md](../vendor-studio/decisions/DDR-005-mobile-first.md) | Mobile as first-class ops surface |

### Components, interaction, mobile, tokens, copy

| Document | Role |
| --- | --- |
| [05-component-library.md](../vendor-studio/05-component-library.md) | Workspace Hero, Task Lists, Action Cards, Metric Cards, Empty states, Alerts, Badges, Buttons, Success panels |
| [DDR-004-component-philosophy.md](../vendor-studio/decisions/DDR-004-component-philosophy.md) | Extend MEL components; cards earn size |
| [07-interaction-guidelines.md](../vendor-studio/07-interaction-guidelines.md) | Loading, focus, hover, notifications, motion |
| [08-mobile-guidelines.md](../vendor-studio/08-mobile-guidelines.md) | Mobile dashboard ops rules |
| [11-design-tokens.md](../vendor-studio/11-design-tokens.md) | Visual token source of truth |
| [04-design-language.md](../vendor-studio/04-design-language.md) | MEL extension philosophy |
| [14-visual-identity.md](../vendor-studio/14-visual-identity.md) | Calm ops feel |
| [15-copywriting-guide.md](../vendor-studio/15-copywriting-guide.md) | Organiser language (Australian English) |
| [19-anti-patterns.md](../vendor-studio/19-anti-patterns.md) | Vanity metrics, empty voids, dual nav, fake loading numbers |

### Drupal mapping & implementation governance

| Document | Role |
| --- | --- |
| [09-drupal-mapping.md](../vendor-studio/09-drupal-mapping.md) | Theme, routes, builders, Commerce boundaries |
| [ARCHITECTURE.md](../vendor-studio/ARCHITECTURE.md) | Visual overview |
| [QUICK_REFERENCE.md](../vendor-studio/QUICK_REFERENCE.md) | Implementation aid |
| [ADR-0001-design-authority.md](../vendor-studio/decisions/ADR-0001-design-authority.md) | Precedence constitution |
| [ADR-0002-implementation-follows-pds.md](../vendor-studio/decisions/ADR-0002-implementation-follows-pds.md) | Runtime follows design |
| [IMPLEMENTATION_WORKFLOW.md](../vendor-studio/IMPLEMENTATION_WORKFLOW.md) | Gates before coding |
| [IMPLEMENTATION_CHECKLIST.md](../vendor-studio/IMPLEMENTATION_CHECKLIST.md) | Pre-implementation gate |
| [FEATURE_IMPLEMENTATION_TEMPLATE.md](../vendor-studio/FEATURE_IMPLEMENTATION_TEMPLATE.md) | Per-feature template |
| [DASHBOARD_IMPLEMENTATION_PREPARATION.md](../vendor-studio/DASHBOARD_IMPLEMENTATION_PREPARATION.md) | Sprint 1A planning prep |
| [PDS_REFERENCE_TEMPLATE.md](../vendor-studio/PDS_REFERENCE_TEMPLATE.md) | PR citation template |
| [16-design-review-checklist.md](../vendor-studio/16-design-review-checklist.md) | Mandatory PR checklist |
| [21-definition-of-done.md](../vendor-studio/21-definition-of-done.md) | Merge gates |
| [18-product-success-metrics.md](../vendor-studio/18-product-success-metrics.md) | Dashboard clarity ≤5s |
| [10-roadmap.md](../vendor-studio/10-roadmap.md) | Phase 2 Dashboard |

### Explicitly out of Sprint 1A (cite only to avoid scope creep)

| Document | Why excluded |
| --- | --- |
| [13-event-workspace-philosophy.md](../vendor-studio/13-event-workspace-philosophy.md) | Event Workspace, not global Dashboard |
| [DDR-002](../vendor-studio/decisions/DDR-002-event-workspace.md) · [DDR-006](../vendor-studio/decisions/DDR-006-payments-hub.md) · [DDR-007](../vendor-studio/decisions/DDR-007-marketing-analytics-separation.md) | Unless accidentally touched |
| [20-vendor-studio-v2-vision.md](../vendor-studio/20-vendor-studio-v2-vision.md) | AI panel / long-term — parked |

### Pattern gap check (DDR gate)

| Needed Dashboard behaviour | PDS home | Gap? |
| --- | --- | --- |
| Attention-led hierarchy | [12](../vendor-studio/12-dashboard-philosophy.md) | No |
| Action Queue / Task List | [05](../vendor-studio/05-component-library.md) §4 · [12](../vendor-studio/12-dashboard-philosophy.md) | No |
| Action Cards (severity + reason + CTA) | [05](../vendor-studio/05-component-library.md) §3 | No |
| Metric Cards ≤4 | [05](../vendor-studio/05-component-library.md) §2 · [12](../vendor-studio/12-dashboard-philosophy.md) | No |
| Compact Workspace Hero | [05](../vendor-studio/05-component-library.md) §1 | No |
| Empty / caught-up / first-run | [05](../vendor-studio/05-component-library.md) Empty states · [12](../vendor-studio/12-dashboard-philosophy.md) | No |
| Loading skeletons (honest) | [07](../vendor-studio/07-interaction-guidelines.md) · [12](../vendor-studio/12-dashboard-philosophy.md) | No |
| Celebrations (quiet) | [05](../vendor-studio/05-component-library.md) Success panels · [12](../vendor-studio/12-dashboard-philosophy.md) | No — use Success panels; do not invent a celebration widget system |
| Dashboard layout intent | [03](../vendor-studio/03-layout-system.md) · [DDR-003](../vendor-studio/decisions/DDR-003-layout-intents.md) | No |
| Mobile stack / 390px | [08](../vendor-studio/08-mobile-guidelines.md) · [12](../vendor-studio/12-dashboard-philosophy.md) | No |

**DDR recommendation:** None required for Dashboard composition. Open **product decisions** (KPI set, Sprint 1A scope for activity/celebrations, sticky Create on mobile) are not DDRs — resolve via Product Owner before Gate E.

If a future PR proposes a parallel `dashboard-widget-*` system, customisable widget soup, or Dashboard-local navigation → **reject** under [19](../vendor-studio/19-anti-patterns.md) / [DDR-001](../vendor-studio/decisions/DDR-001-shell-navigation.md) / [DDR-004](../vendor-studio/decisions/DDR-004-component-philosophy.md). Only file a DDR if Design Authority agrees the PDS itself is wrong.

---

## 1. Current runtime inventory (repository evidence)

| Layer | Confirmed home |
| --- | --- |
| Route | `myeventlane_vendor.console.dashboard` → `/vendor/dashboard` |
| Access | `VendorConsoleAccess` · permission `access vendor dashboard` (post-onboarding) |
| Controller | `Drupal\myeventlane_vendor\Controller\VendorDashboardController` |
| View model | `VendorDashboardViewModelBuilder` |
| Action queue | `VendorActionQueueBuilder` (+ `MelVendorDashboardActionQueueGovernance` presentation reorder) |
| Theme hook | `myeventlane_vendor_dashboard` |
| Twig | `web/themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig` |
| Shell | `layout/page.html.twig` · `page--vendor-dashboard.html.twig` |
| Libraries | `myeventlane_vendor_theme/dashboard` (→ global-styling), `mel_event_card_remove`, optional `myeventlane_growth/dashboard_cards` |
| SCSS | `_dashboard-live-ops.scss`, `_dashboard.scss`, `_dashboard-mel-support.scss`, KPI/analytics partials |
| Layout token | `--mel-layout-dashboard` / `$mel-layout-dashboard` (1280px) |

**Not the global Dashboard:** Event Studio overview (`myeventlane_event_studio`) is per-event Workspace Home. Legacy `myeventlane_dashboard` / public-theme vendor dashboard templates are not the live `/vendor/dashboard` owner.

---

## 2. Phase 1 analysis — what works

| Strength | Evidence |
| --- | --- |
| Canonical route + vendor theme forced | Routing + `_theme: myeventlane_vendor_theme` |
| Real Action Queue builder (severity-ordered, max 6) | `VendorActionQueueBuilder` — Twig must not re-sort (already the design contract) |
| Identity header + Create event CTA | Present on first paint |
| Current focus + Upcoming + Recent activity sections | Exist in Twig; align directionally with [12](../vendor-studio/12-dashboard-philosophy.md) |
| Progressive disclosure for analytics / homepage / tools | Collapsed `<details>` reduces vanity density |
| Stripe panel can surface without digging into Tools | Supports P0 money setup honesty |
| Empty first-run path with Create event | Partial alignment with empty-state philosophy |
| Server-side console access | `VendorConsoleAccess` — UI never is security |
| View model already builds unused payloads | `attention_events`, readiness guidance, `vendor_kpis` strip partial exists — reuse before inventing |

---

## 3. What doesn’t work (vs PDS)

| Failure | PDS conflict |
| --- | --- |
| **Priority inversion** — Pro panel, Stripe, Workspace overview KPIs, and header metrics render **above** Needs attention | [12](../vendor-studio/12-dashboard-philosophy.md) ranks Action Queue strongest after identity/Create |
| **Duplicate metrics** — hero strip (3) + workspace overview (4) compete | Max **four** KPIs total; metrics support decisions, do not decorate |
| **Empty Action Queue = section omitted** | Empty queue must be calm “You’re caught up” — not a void ([12](../vendor-studio/12-dashboard-philosophy.md), [19](../vendor-studio/19-anti-patterns.md)) |
| Action items often lack visible **reason** (title + CTA only) | Action Card contract: severity + title + reason + one CTA |
| Built payloads unused on first paint | Dual data paths (controller extras + view model) increase noise risk |
| Pro welcome / Pro panel can dominate attention | Celebrations/milestones are quietest; Pro confirmation must not outrank blockers |
| Growth / Boost / homepage theatre under Tools still competes for mental model | Dashboard is attention home, not Marketing |
| Recent activity empty copy is fine; full activity may still feel secondary to Tools weight | Hierarchy: activity before celebrations/help |
| No honest skeleton / `aria-busy` loading contract on page | [07](../vendor-studio/07-interaction-guidelines.md) · [12](../vendor-studio/12-dashboard-philosophy.md) |
| Cache: user/role contexts present; **no dashboard-specific entity cache tags** visible on controller | Performance / personalisation risk |

---

## 4. Cognitive load

**Current load drivers**

1. Multiple “how is my business?” surfaces before the queue (header metrics + overview grid).  
2. Pro checklist as a long confirmation wall.  
3. Tools accordion containing homepage visibility, analytics, growth, full events grid — a second product inside the home.  
4. Duplicate “Current focus” (line inside overview + dedicated section).  
5. Organiser must learn which numbers matter when several strips disagree in emphasis.

**Target load (PDS)**

One scan path: **identity → queue → today’s focus → upcoming → ≤4 KPIs → activity → quiet help**. If space is scarce, preserve ranks 1–3 before metrics theatre ([12](../vendor-studio/12-dashboard-philosophy.md)).

---

## 5. Navigation

| Aspect | Current | Target |
| --- | --- | --- |
| Global shell | Present via `VendorNavBuilder` / sidebar | Keep — [DDR-001](../vendor-studio/decisions/DDR-001-shell-navigation.md) |
| Dashboard-local nav | Not a second sidebar; Tools acts as mini-hub | Do not add Dashboard-local nav; demote Tools content off first paint or out of Dashboard scope |
| Create event | Header/page primary | Remains chrome primary ([02](../vendor-studio/02-information-architecture.md)) |
| Entry to Event Workspace | Open event / Open from upcoming | Keep as launcher — not a Workspace clone |

---

## 6. Visual hierarchy

**Current first-paint order (Twig):** Pro welcome → Identity (+ metrics) → Pro panel → Stripe → Workspace overview → Needs attention → Current focus → Upcoming → Activity → Tools.

**PDS target order:**

```text
1. Identity + Create event (chrome)
2. Action Queue          ← strongest
3. Today’s focus
4. Upcoming events
5. Business health (≤4 KPIs)
6. Recent activity
7. Celebrations / milestones (quiet)
8. Help / guidance (quietest)
```

Stripe/connect blockers belong **in or immediately with** the Action Queue (P0 Action Cards), not as a competing hero above the queue. Pro confirmation belongs at celebration rank (quiet), not above Needs attention.

---

## 7. Accessibility

| Observation | Risk / gap |
| --- | --- |
| Single H1 on organiser name | Good — preserve |
| Timeline markers use symbols; severity also CSS modifiers | Ensure severity not colour-only; pair icon + text ([12](../vendor-studio/12-dashboard-philosophy.md)) |
| Queue CTAs are real links | Good |
| Empty queue removes the section | Keyboard/AT users lose a calm status landmark |
| Nested H2 inside Current focus card after section H2 | Heading outline noise — fix in implementation design |
| Pro welcome `aria-live` | Acceptable if rare; must not interrupt queue focus |
| Focus order / skip link | Shell responsibility — verify Create event + top queue reachable without trap ([07](../vendor-studio/07-interaction-guidelines.md)) |
| Touch targets | Enforce 44×44 on mobile CTAs ([08](../vendor-studio/08-mobile-guidelines.md)) |

---

## 8. Performance considerations

| Concern | Repository note |
| --- | --- |
| View model builds many event rows, KPIs, homepage visibility, analytics | Heavy work on paint — prefer caching with correct **user + vendor** contexts/tags |
| Pro welcome sets `max-age = 0` | Acceptable for one-shot; do not expand uncacheable surface |
| Growth/Boost cards optional + suppressible | Keep suppression; do not add more uncached third-party calls on first paint |
| Tools accordion still builds content server-side | Prefer lazy/secondary routes for deep analytics rather than stuffing Dashboard |
| Twig must not re-sort queue | Already contracted — keep |

---

## 9. Workflow friction

| Organiser job | Friction today |
| --- | --- |
| “What do I do in the next five seconds?” | Queue buried under Pro/metrics |
| First publish / Stripe connect | May appear in queue **and** Stripe panel — duplicate messaging risk |
| Door today | Current focus helps; LIVE chip present — keep, elevate after queue |
| Caught-up calm | Missing empty-queue state → organiser may hunt Tools for reassurance |
| Find Analytics / Marketing | Tools accordion vs future global nav hubs — Dashboard should not become growth home ([DDR-007](../vendor-studio/decisions/DDR-007-marketing-analytics-separation.md) for later hubs) |

---

## 10. Three Questions scorecard (current)

| Question | Score | Notes |
| --- | --- | --- |
| Where am I? | Partial | Organiser name + “Running your events”; shell Dashboard active |
| What needs me? | Partial | Queue exists but often not first visual priority; empty = invisible |
| What should I do next? | Partial | Create event + queue CTAs + Current focus; competed by Pro/metrics/Tools |

**Golden Rule:** Cannot reliably claim ≤5s identification of top action for Pro vendors or metric-dense sessions.

---

## 11. Assumptions (explicit)

1. “Dashboard v2” means applying PDS Phase 2 composition to `/vendor/dashboard`, not inventing a new product surface.  
2. Event Studio overview remains out of scope except as the destination of “Open event”.  
3. Exact KPI labels (≤4) require Product Owner approval before implementation (prep Q3).  
4. Celebrations and rich activity may be **later polish** if Sprint 1A is scoped to hierarchy + empty queue + KPI consolidation — Product call.  
5. No Commerce/payment state invention; Stripe CTAs only route to existing connect/payout paths.

---

## 12. Research verdict

The runtime already contains most **data** needed for a PDS-compliant Dashboard. The failure is **composition and priority**, not missing philosophy. Sprint 1A design should reorder and reduce — reusing Action Queue, focus, upcoming, and Metric Card contracts — without new patterns.

**Next:** [02-dashboard-wireframes.md](02-dashboard-wireframes.md)
