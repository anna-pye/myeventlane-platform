# Vendor Workspace v2 — Workspace Unification Architecture

**Status:** Architecture (Sprint 1B) — documentation only  
**Date:** 2026-07-25  
**Branch:** `feature/vendor-workspace-v2-discovery`  
**Base commit:** `37fcdc449` (Dashboard Foundation merged via PR #716)  
**Authority:** Vendor Studio PDS v1.0 · ADR-0001 · DDR-001 · DDR-002 (amendment pending DDR-008/009)  
**Depends on:** Sprint 1A — `00`–`06` in this pack  

No Twig, SCSS, PHP, JS, YAML, routes, builders, or view models were modified for this document.

---

## Stage 1 — Discovery review (confirmed)

### Confirmations

| Claim | Status | Evidence |
| --- | --- | --- |
| **Studio is organiser truth** | **Confirmed** | `EventStudioController` → `#theme => mel_event_studio_workspace`; entry `/vendor/events/{node}/studio`; `VendorLegacyWizardRedirectSubscriber` funnels trusted organisers from most Manager routes |
| **Manager remains staff/operational** | **Confirmed** | Staff with `administer nodes` / uid 1 skip redirect; many controllers still render `mel_event_workspace`; Door Mode still uses Manager shell |
| **Dashboard Foundation assumptions remain valid** | **Confirmed** | PR #716 on `main` @ `37fcdc449`; Dashboard = portfolio attention; Workspace = per-event attention; do not duplicate action queues (`04-mission-control-model.md`, dashboard pack `09-merge-readiness.md`) |

### Current architecture (as-is)

```text
Vendor Studio (global shell — DDR-001)
├── Dashboard          ← portfolio attention (Foundation merged)
├── Events             ← catalogue
│     └── Event Studio Workspace   ← organiser product truth
│           path: /vendor/events/{node}/studio[/{section}]
│           theme: mel_event_studio_workspace
│           Home: EventWorkspaceOverviewBuilder
└── … Orders, Attendees, Messages, Payments, …

Parallel residual:
Manager Event Workspace
  path: /vendor/events/{event}[/{section}]
  theme: mel_event_workspace
  users: staff / uid 1 / deep links / Door Mode / some Boost & refund surfaces
```

### Current problems

1. **Dual shells** — organisers redirected to Studio; Manager chrome remains for staff, Door Mode, and residual controllers (conflict C1 in `00-runtime-discovery.md`).
2. **Path shape vs DDR-002** — Accepted DDR-002 claims `/vendor/events/{id}/{section}`; runtime requires `/studio` (conflict C2).
3. **Nav source fragmentation** — `EventStudioSectionManager` ≠ `VendorEventTabsService` ≠ `VendorConsolePagePreprocess` ≠ local tasks.
4. **Section order vs PDS 13** — Runtime: Attendees (60) before Orders (90); PDS 13: Orders before Attendees (conflict C3).
5. **Door Mode shell split** — Highest-stress path leaves Studio chrome.
6. **Config drift** — Active DB vs sync on `user.role.vendor` and RSVP `organiser_owned` filter (access-adjacent).
7. **Governance gaps** — ADR-0002 referenced by cursor rule but missing; PDS version label 1.0 vs 1.0.1.

### Current strengths

1. Real per-event application with section plugins, autosave, publish CSRF APIs.
2. Home mission-control seed: readiness + `resolveNextRecommendedAction()` + ops cards.
3. Shared `VendorEventWorkspaceViewModelBuilder` for focus/metrics/actions.
4. Organiser funnel already reduces dual-nav exposure in practice.
5. Clear Dashboard ↔ Workspace scope split after Foundation merge.
6. PDS pack (01–21, DDR-001–007) provides design authority.

### Discovery contradiction check

**No repository evidence contradicts Sprint 1A Discovery.** Spot-checks on this branch still show:

- Studio workspace theme + `/studio` routes
- Legacy redirect subscriber + staff skip
- Attendees weight 60 / Orders weight 90
- Door Mode on Manager-themed controllers
- Dashboard Foundation commit on `main`

Conflicts C1–C5 remain **design authority vs runtime** gaps — not discovery errors.

---

## Stage 2 — Future architecture (to-be)

### Definitions

| Term | Definition | Who uses it |
| --- | --- | --- |
| **Vendor Studio** | The organiser console product: one global shell (Dashboard, Events, hubs). Not a CMS. Not staff Manager. | Organisers (vendors) and their authorised team roles with console trust |
| **Event Workspace** | The **single contextual application** for one event — builder + operations. Entered from Events (or Dashboard shortcuts). Shell constant; emphasis changes by lifecycle. | Organisers operating **this** event |
| **Manager (future: Staff Operations)** | Staff / platform operational surfaces for support, diagnostics, and privileged overrides. **Not** an organiser product twin. | Staff (`administer nodes` / uid 1) and explicitly staff-gated tools |

### Navigation flow (canonical product)

```text
Dashboard
  ↓ (attention item / “Open event”)
Events
  ↓ (select event / Create event → land in Workspace)
Event Workspace (Home = Mission Control)
  ↓
Operational sections (same app)
  Overview · Details · Schedule · Venue · Images · Tickets
  · Attendees (+ Door Mode) · Messages · Marketing · Orders
  · Analytics · Publishing · Settings
```

**Context rules**

- Global → Event is the only major context switch (PDS 13).
- Section → Section never re-asks “which event?”.
- Global Orders / Attendees remain cross-event hubs; Workspace sections are event-filtered (PDS 02).
- Workspace is **never** a permanent global sidebar peer of Events (DDR-002).

### Manager → Staff Operations

| Dimension | Decision |
| --- | --- |
| Organiser-facing name | **Do not** say “Manager” in product UI |
| Staff product name | **Staff Operations** (internal/docs) |
| Theme | May retain `mel_event_workspace` temporarily for staff-only routes |
| Organiser entry | **Forbidden** — redirects remain until path unification retires Manager organiser entry |
| Door Mode | Capability stays; chrome must feel like Event Workspace (continuity), even if route path migrates later |

---

## What remains / disappears / redirects / becomes contextual

### Remains (organiser product)

| Asset | Why |
| --- | --- |
| `myeventlane_event_studio` Workspace app | Organiser truth |
| Section plugin architecture | Extensible IA without new route trees |
| `EventWorkspaceOverviewBuilder` + overview Twig | Mission Control body |
| `VendorEventWorkspaceViewModelBuilder` | Shared next-action / focus / metrics |
| Readiness facade + `PublishEligibilityEvaluator` + Stripe gate | Trust |
| Autosave + publish POST + CSRF | Safety |
| Door Mode check-in capability | Live ops |
| Vendor theme + layout intents / tokens | PDS 09 / DDR-003 / 11 |
| Dashboard Foundation | Portfolio attention home |

### Disappears (from organiser product story)

| Asset | Disposition |
| --- | --- |
| Organiser-facing “Studio vs Manager” language | Retire from UI, help, onboarding |
| Competing Manager event chrome as organiser destination | Staff-gate then retire |
| Duplicate tab builders as equal authorities | Collapse to `EventStudioSectionManager` as sole nav source |
| Topbar publish **competing** with Home primary CTA as two primaries | One primary CTA slot by state (publish may occupy it when Ready) |

### Redirects

| From | To | Notes |
| --- | --- | --- |
| Most `/vendor/events/{id}/*` Manager organiser routes | Workspace destinations | Already: `VendorLegacyWizardRedirectSubscriber` |
| Future (if DDR-008 Option A): `/…/studio[/{section}]` | `/vendor/events/{id}[/{section}]` | Requires accepted DDR + redirect map + help URL updates |
| Legacy publish Manager routes | Publishing / Workspace | Keep bookmark safety |

### Becomes contextual

| Capability | Global hub | Event Workspace |
| --- | --- | --- |
| Orders | Cross-event money trail | This event’s orders |
| Attendees | Cross-event guest search | This event’s list + Door Mode |
| Messages | Brand, templates, history | Event-scoped send |
| Marketing | Boost / growth hubs | This event share / Boost |
| Analytics | Business pulse | This event performance |
| Settings | Organiser defaults | This event settings / archive |

---

## Target information architecture (Workspace)

**Recommended section order for Event Workspace** (live-ops bias; amends PDS 13 via prepared **DDR-009**):

```text
Overview (Home)
→ Details → Schedule → Venue → Images
→ Tickets
→ Attendees   ← Door Mode nests here
→ Messages
→ Marketing
→ Orders
→ Analytics
→ Publishing
→ Settings
```

**Rationale:** Matches runtime weights today (Attendees 60, Orders 90); aligns with live-ops priority and PDS 02 note that Workspace may lead with Attendees while **global** nav keeps Orders before Attendees. Frozen PDS 13 text requires DDR-009 before amending.

**Transitional paths (until DDR-008 Accepted):**

```text
/vendor/events/{node}/studio
/vendor/events/{node}/studio/{section}
```

**Target paths (DDR-008 recommendation):**

```text
/vendor/events/{id}
/vendor/events/{id}/{section}
```

---

## Shell architecture (constant)

```text
┌────────────────────────────────────────────────────────────┐
│ Global header — Organiser · Create event · account         │
├────────────┬───────────────────────────────────────────────┤
│ Global nav │ Event chrome: name · status · 1° CTA · 2°    │
│ (context)  ├───────────────────────────────────────────────┤
│            │ Section nav (stable membership)               │
│            ├───────────────────────────────────────────────┤
│            │ Section body — Mission Control or section UI  │
│            │ Sticky action bar (mobile) when needed        │
└────────────┴───────────────────────────────────────────────┘
```

Lifecycle changes **emphasis and CTA content**, not shell membership (see `08`, `11`).

---

## Relationship to prepared DDRs

| Record | Topic | Status |
| --- | --- | --- |
| [DDR-008](../vendor-studio/decisions/DDR-008-canonical-event-workspace.md) | Canonical Event Workspace path & shell | **Prepared — not accepted** |
| [DDR-009](../vendor-studio/decisions/DDR-009-workspace-navigation.md) | Workspace navigation & section order | **Prepared — not accepted** |

Implementation must not rename paths or reorder nav until these are Accepted (or Product Owner issues an explicit waiver for composition-only slices).

---

## Explicit non-goals of this architecture doc

- No runtime changes
- No new Drupal module or theme
- No greenfield shell
- No collapsing event nodes with Commerce products
- No staff tools in organiser IA
