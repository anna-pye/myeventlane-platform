# Vendor Dashboard v2 — Stage 1 Runtime Discovery (Slice 2)

**Status:** Discovery complete — proceed with stated assumptions  
**Branch:** `feature/vendor-dashboard-v2-slice2`  
**Base:** `origin/main` @ `e450a5e96` + uncommitted Dashboard Slice 1 WIP from `feature/vendor-dashboard-v2`  
**Route:** `/vendor/dashboard` · `myeventlane_vendor.console.dashboard`  
**Date:** 2026-07-25  
**Governing PDS:** Vendor Studio Product Design System v1.0.1

No Twig, SCSS, PHP, or config was modified solely to produce this document. Implementation follows in the same sprint branch after this gate.

---

## 0. Slice 1 merge gate

| Check | Result |
| --- | --- |
| Slice 1 merged to `origin/main`? | **No** — no Slice 1 commits on `main` |
| Approved Slice 1 branch? | `feature/vendor-dashboard-v2` holds **uncommitted** Slice 1 Twig/SCSS + design docs `00`–`06` |
| Repository clean at start? | **No** — dirty working tree (Slice 1) |
| Action taken | Created `feature/vendor-dashboard-v2-slice2` from that WIP so Slice 2 builds on Slice 1 presentation |

**Assumption:** Product treats the current working-tree Slice 1 hierarchy as the approved foundation. Slice 2 must not redesign that hierarchy — only add operational awareness on top.

---

## 1. Current Dashboard hierarchy (Slice 1 WIP)

Confirmed in working `dashboard.html.twig` (`data-mel-dashboard-slice="1"`):

1. Workspace Hero (identity + Create event)  
2. Action Queue / Needs attention (always present; calm caught-up when empty)  
3. Business health (≤4 from `model.kpis`)  
4. Upcoming events  
5. Recent activity  
6. Current focus (secondary) + Stripe + Pro  
7. Tools & settings (collapsed utilities)

**Matches Slice 1 / PDS `12` Action-Queue-first intent.** Runtime does **not** differ from Slice 1 presentation assumptions → **do not STOP** for hierarchy mismatch.

---

## 2. Existing builders

| Service | Role | Slice 2 reuse |
| --- | --- | --- |
| `VendorDashboardViewModelBuilder` | Primary view model | Yes — extend only to **surface** already-computed start time as `doors_open_label` / `start_timestamp` (not invent metrics) |
| `VendorActionQueueBuilder` | Action queue | Unchanged |
| `MetricsAggregator` / `VendorKpiService` | KPI sources behind view model | Unchanged; continue single strip from `model.kpis` |
| Controller `buildDashboardActivity()` | `dashboard_activity_items` | Yes — Daily Brief overnight lines + activity grouping |
| Controller `getNotifications()` | Alert-style notifications | **Not** organiser unread messages |

---

## 3. Existing Twig

| Template | Role |
| --- | --- |
| `templates/dashboard/dashboard.html.twig` | Primary composition (Slice 1) |
| `templates/page--vendor-dashboard.html.twig` | Page suggestion |
| `templates/layout/page.html.twig` | Shell |
| `templates/components/empty-state.html.twig` | Empty / caught-up |
| `templates/components/kpi-card.html.twig` | Rich KPI (not required if strip remains lean) |
| `templates/components/vendor-kpi-strip.html.twig` | Still unused by dashboard Twig |
| `templates/components/stripe-panel.html.twig` | Stripe |

No parallel dashboard template. No new architecture.

---

## 4. Existing preprocess

| Hook | File | Behaviour |
| --- | --- | --- |
| `myeventlane_vendor_theme_preprocess_myeventlane_vendor_dashboard` | `myeventlane_vendor_theme.theme` | Relative `timestamp_label` on activity rows |
| Module preprocess stack | pro, launch, donations, automation, event, help_centre, dashboard | Enrich Pro / launch / help — leave intact |

**Slice 2:** Extend theme preprocess for Daily Brief + activity grouping only (presentation assembly from existing payloads).

---

## 5. Existing SCSS

| Partial | Role |
| --- | --- |
| `pages/_dashboard-live-ops.scss` | Primary composition (Slice 1) — **main edit surface** |
| `pages/_dashboard.scss` | Legacy action-card helpers |
| `components/_empty-states.scss` | `.mel-skeleton`, `.mel-loading` |
| `components/_kpi-cards.scss` | Metric card system |

---

## 6. Existing libraries

Unchanged from Slice 1 discovery:

- `myeventlane_vendor_theme/global-styling`
- `myeventlane_vendor_theme/dashboard`
- `myeventlane_vendor_theme/mel_event_card_remove`
- Conditional `myeventlane_growth/dashboard_cards`

**No new libraries.**

---

## 7. Cache contexts & tags (pre-Slice 2)

| Mechanism | Evidence |
| --- | --- |
| Contexts | Controller merges `user`, `user.roles` |
| Max-age | `0` only for one-shot Pro welcome |
| Tags | **None** set on dashboard controller path (Pro preprocess may add order/store tags) |

**Slice 2 safe improvement:** add entity list / vendor tags + `timezone` context without changing behaviour of payloads.

---

## 8. Dashboard payloads (confirmed keys)

### View model (`VendorDashboardViewModelBuilder::build`)

`vendor`, `readiness`, `operational_readiness`, `lifecycle_guidance`, `kpis` (≤4; `trend` currently always `NULL`), `action_queue`, `events`, `current_event`, `organiser_actions`, `organiser_overview`, `attention_events`, `upcoming_events`, `analytics_summary`, `empty_state`, `priority_action`, `secondary_actions`, `workspace_updates`, `hero_shell_hint`, `homepage_visibility`.

### `current_event` / event row (confirmed)

`nid`, `title`, `status`, `status_label`, `date_label`, `event_type`, `booking_state_label`, `capacity_label`, `metric_label`, `revenue_label`, `attendee_summary`, `operation_summary`, `metrics[]` (`bookings`, `attendees`, `revenue`, `rsvps`, optional `capacity`), `attention_reasons`, `links` (includes `manage`, `checkin`), `quick_actions` (includes `checkin` → Door Mode route).

### Controller extras used by Twig

`dashboard_activity_items` (`timestamp`, `type`, `message`, `url`), `account.display_name`, `stripe`, Pro flags.

---

## 9. Slice 2 signal audit (critical)

| Brief asks for | Runtime? | Decision |
| --- | --- | --- |
| Event name / status | Yes | Show |
| Attendee count | Yes — `metrics[attendees]` / `attendee_summary` | Show (expected guests, not check-ins) |
| Open Workspace | Yes — `links.manage` | Label as Open Workspace |
| Door Mode | Yes — `links.checkin` / quick action `checkin` | Label as Door Mode |
| Doors open countdown | **Not in payload** — `startTs` computed inside builder only | **Surface** as `doors_open_label` from existing `field_event_start` (not invent) |
| Unread organiser messages | **Absent** (no dashboard unread count) | **Omit** — do not invent |
| Overnight bookings | No dedicated signal | Derive **only** by counting `dashboard_activity_items` with booking/RSVP types whose `timestamp` falls in overnight window; else omit line |
| Daily Brief | Composite | Show **only** if ≥1 factual operational line can be built; else hide entirely |
| KPI trends | Key exists; always `NULL` today | Render only when non-empty |
| Skeleton styling | `.mel-skeleton` in `_empty-states.scss` | Reuse; no fake revenue numbers |

**Gate:** Runtime differs from the *aspirational* Slice 2 brief on unread messages and pre-baked countdown — not from Slice 1 hierarchy. Proceed with omissions + thin exposure of existing start time. **No DDR** required if we do not invent a messages subsystem.

---

## 10. Governing PDS documents (why each applies)

| Document | Why it governs Slice 2 |
| --- | --- |
| **01 Vision** | Calm operating system organisers enjoy; reduce anxiety without CMS vocabulary |
| **03 Layout** | Dashboard layout intent / max-width token; no parallel layout system |
| **05 Components** | Reuse Action Cards, Metric Cards, empty states, buttons — invent nothing |
| **06 Workspace Patterns** | Today’s Event → Workspace / Door Mode entries must match workspace entry patterns |
| **07 Interaction** | Primary/secondary CTAs, focus, no toast theatre over attention path |
| **08 Mobile** | 390px-first; stack operational panels; 44px targets |
| **09 Drupal Mapping** | Map to existing builders/theme homes; no duplicate dashboard |
| **11 Tokens** | Spacing/type/colour from vendor tokens; whitespace over decoration |
| **12 Dashboard Philosophy** | “What do I need to know right now?”; Action Queue remains P0; Today’s focus after queue |
| **15 Copy Guide** | Australian English; sentence-case buttons; calm Daily Brief voice |
| **16 Review Checklist** | Pre-ship a11y / hierarchy / anti-pattern review |
| **18 Success Metrics** | Anxiety ↓, time-to-know operational state ↓ — not vanity metrics |
| **19 Anti-patterns** | No oversized coloured KPI cards; no marketing metrics on home; no AI |
| **21 Definition of Done** | A11y, mobile, reuse, docs, validation commands |
| **ADR-0001** | PDS is design authority; do not contradict higher-order docs |
| **ADR-0002** | Implementation follows PDS — **file missing in repo** (cited in README/plan); treat as binding intent; residual governance debt |
| **DDR-001** | Shell/navigation unchanged |
| **DDR-003** | Layout intents — dashboard composition stays within dashboard intent |
| **DDR-004** | Component philosophy — extend existing components |
| **DDR-005** | Mobile-first |

---

## 11. Implementation constraints (Slice 2)

1. Keep Slice 1 Action Queue first after hero (+ optional Daily Brief as greeting-adjacent).  
2. Elevate Today’s Event only when status is upcoming/in-session — not draft/past.  
3. Never invent unread counts, overnight stats, or countdown without a start timestamp.  
4. Single ≤4 KPI strip; reduce visual weight; optional trend only.  
5. Hierarchy/typography polish for Upcoming + Activity only — no new features.  
6. Skeletons reuse `.mel-skeleton`; respect `prefers-reduced-motion`; no fake stats.  
7. Performance: cache tags/contexts only; no behavioural payload changes beyond surfacing start-derived label.  
8. No config/sync changes. No AI. No Events / Event Workspace redesign.

**Discovery complete. Slice 2 implementation may begin under the assumptions above.**
