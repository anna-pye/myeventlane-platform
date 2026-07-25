# Vendor Workspace v2 — Runtime Discovery

**Status:** Discovery only (no runtime changes)  
**Branch:** `feature/vendor-workspace-v2-discovery`  
**Commit:** `37fcdc4494b4c841b55eb01fbde59cbd0f7aaba9` (`origin/main` — PR #716 Dashboard Foundation merged)  
**Date:** 2026-07-25  
**Authority:** Vendor Studio PDS v1.0 (pack) · cursor rule claims v1.0.1 — see conflicts below

No Twig, SCSS, PHP, JS, YAML, routes, builders, or view models were modified for this document.

---

## Stage 1 — Repository health

| Check | Result |
| --- | --- |
| Working tree | Clean at branch creation |
| Branch | `feature/vendor-workspace-v2-discovery` from latest `origin/main` |
| Dashboard Foundation | **Merged** — `37fcdc449` Merge PR #716 (`feature/vendor-dashboard-v2-slice2`) |
| Config drift | **Present** — see §1.1 |
| Runtime safety | Bootstrap OK (DDEV Drupal 11.4.4); Workspace dual-shell remains (see §2) |

### 1.1 Config drift affecting Workspace

`ddev drush config:status` shows local active ≠ sync. Workspace-relevant diffs:

| Config | Drift | Workspace impact |
| --- | --- | --- |
| `user.role.vendor` | Active **missing** permission `resend ticket emails` that exists in sync | Ticket/attendee ops capability may differ from exported intent |
| `views.view.myeventlane_vendor_rsvps` | Active **missing** `organiser_owned` filter present in sync | RSVP list isolation weaker in active DB than in repo sync — **access-adjacent** |

Other drift (Klaro apps, payment gateways, checkout flow, help content, `field_product_target`) is **not** Event Workspace chrome, but payment/checkout items remain high risk for any later publish/Stripe work.

**Stage 1 verdict:** Discovery may proceed (docs only). **Implementation must not start** until Workspace-relevant config is reconciled (especially RSVP organiser ownership filter and vendor role perms).

---

## Stage 2 — PDS governance map (why each doc governs Event Workspace)

| Document | Why it governs Event Workspace |
| --- | --- |
| **01 Vision** | Golden Rule / Three Questions / Ten Principles apply to every Workspace screen (next step, honesty, hide Commerce language). |
| **02 Information Architecture** | Defines Workspace as contextual app under Events; forbids dual Studio/Manager products; section nesting rules. |
| **03 Layout System** | Event chrome + Workspace/Reading intents for Overview next-action vs wide ops boards. |
| **05 Component Library** | Contracts for Workspace Hero, readiness, metrics, alerts, tables used inside the event app. |
| **06 Workspace Patterns** | Page composition for Event Workspace (readiness + next action + KPIs + activity). |
| **07 Interaction Guidelines** | Autosave vs explicit publish/money confirm; focus, loading, keyboard under ops stress. |
| **08 Mobile Guidelines** | Door Mode, sticky CTAs, 390px baseline for live event phone use. |
| **09 Drupal Mapping** | Homes for theme, routes, builders, Commerce boundaries — prevents parallel trees. |
| **11 Design Tokens** | Spacing, intents, severity colours for Workspace chrome consistency. |
| **13 Event Workspace Philosophy** | **Primary** Workspace specification: shell, sections, readiness, publish, lifecycle, Door Mode. |
| **15 Copywriting Guide** | Organiser language for status, blockers, publish, refunds — no CMS vocabulary. |
| **16 Design Review Checklist** | PR gate (IA1 no dual nav, readiness honesty, a11y, mobile). |
| **18 Product Success Metrics** | Time-to-publish, next-step clarity, Door Mode usability targets for Workspace work. |
| **19 Anti-patterns** | Dual nav, fake readiness, nested cards, CMS chrome — common Workspace failure modes. |
| **21 Definition of Done** | Completion gates before calling Workspace slices “done”. |
| **ADR-0001** | Constitution / precedence — Workspace design cannot silently override higher docs. |
| **ADR-0002** | **MISSING from repository** — referenced by `.cursor/rules/mel-vendor-studio-pds.mdc` as `ADR-0002-implementation-follows-pds.md`. Pack README is v1.0 FROZEN without ADR-0002. |
| **DDR-001** | One global shell — Workspace must not reintroduce parallel global nav. |
| **DDR-002** | One Event Workspace; canonical paths `/vendor/events/{id}` · `/{section}`. |
| **DDR-003** | Layout intents for Workspace content widths. |
| **DDR-004** | Extend MEL components; no parallel `vendor-studio-*` kit. |
| **DDR-005** | Mobile-first ops including Door Mode. |

### PDS ↔ runtime conflicts (STOP / DDR)

| Conflict | PDS / DDR claim | Runtime evidence | Recommendation |
| --- | --- | --- | --- |
| **C1 Dual shells** | DDR-002 / 13: one Event Workspace | Organisers redirected into Studio (`mel_event_studio_workspace`); Manager (`mel_event_workspace`) remains for staff / uid 1 / deep links | **DDR** (or DDR-002 amendment): declare Studio-under-`/studio` as transitional canonical **or** path-unify to `/vendor/events/{id}/{section}` |
| **C2 Path shape** | DDR-002: `/vendor/events/{id}` and `/{section}` | Product path is `/vendor/events/{node}/studio` (+ `/studio/{section}`) | Same DDR as C1 |
| **C3 Section order** | 13: … Tickets → **Orders** → Attendees → … | Studio nav + `VendorEventTabsService`: Tickets → **Attendees** → Messages → Marketing → **Orders** | Clarify in DDR: live-ops priority vs money trail; amend 13 if Attendees-before-Orders is intentional |
| **C4 ADR-0002 missing** | Cursor rule requires ADR-0002 | No `docs/design/vendor-studio/decisions/ADR-0002-*.md` | Author ADR-0002 (implementation follows PDS) or remove rule reference |
| **C5 Version label** | Rule: PDS v1.0.1 | Pack README/INDEX: **1.0 FROZEN** | Align CHANGELOG / README via governance lifecycle |

These are **design authority conflicts**. Discovery documents them; it does **not** invent a new architecture.

---

## 2. Executive runtime verdict

At this commit, **organiser product truth** for per-event work is **Event Studio Workspace**:

- Theme: `mel_event_studio_workspace`
- Entry: `/vendor/events/{node}/studio`
- Redirect: `VendorLegacyWizardRedirectSubscriber` sends trusted organisers away from most Manager routes

**Manager Event Workspace** (`mel_event_workspace`, `/vendor/events/{event}`) remains in code for staff (`administer nodes` / uid 1) and some surfaces (notably **Door Mode** still renders Manager shell).

Dashboard v2 Foundation (merged) is the **global** attention home; Event Workspace Home is the **per-event** mission control — they must not duplicate business-wide queues.

---

## 3. Routes

### 3.1 Manager console (`myeventlane_vendor.routing.yml`)

| Route | Path | Notes |
| --- | --- | --- |
| `myeventlane_vendor.console.event_workspace` | `/vendor/events/{event}` | `EventWorkspaceController::workspace` → redirected for organisers |
| `…event_overview` | `…/overview` | Overview controller |
| `…event_tickets` | `…/tickets` | Ticket manager form |
| `…event_orders` | `…/orders` | Orders list |
| `…event_order_view` | `…/orders/{order}` | Order detail |
| `…event_operational_addon_orders` | `…/addons` | Add-on orders |
| `…event_rsvps` | `…/rsvps` | RSVPs |
| `…event_analytics` | `…/analytics` | Analytics (Pro-gated in places) |
| `…event_settings` | `…/settings` | Settings |
| `…event_publish` | `…/publish` | Redirect toward Studio |
| `…event_unpublish` | `…/unpublish` | Unpublish form |
| `…event_promotion` | `…/promotion` | Legacy comms |
| `myeventlane_event_attendees.vendor_list` | `/vendor/events/{node}/attendees` | Attendees |
| `myeventlane_event_attendees.vendor_operations_door` | `/vendor/events/{node}/operations/door` | **Door Mode** (kept) |

### 3.2 Event Studio (`myeventlane_event_studio.routing.yml`)

| Route | Path | Section |
| --- | --- | --- |
| `myeventlane_event_studio.workspace` | `/vendor/events/{node}/studio` | overview (Home) |
| `…workspace_information` / `details` | `…/studio/information` | information |
| `…workspace_schedule` | `…/studio/schedule` | schedule |
| `…workspace_venue` | `…/studio/venue` | venue |
| `…workspace_branding` / `images` | `…/studio/branding` | branding |
| `…workspace_tickets` | `…/studio/tickets` | tickets |
| `…workspace_attendees` | `…/studio/attendees` | attendees |
| `…workspace_messaging` | `…/studio/messaging` | messaging |
| `…workspace_marketing` | `…/studio/marketing` | marketing |
| `…workspace_orders` | `…/studio/orders` | orders |
| `…workspace_analytics` | `…/studio/analytics` | analytics |
| `…workspace_publishing` | `…/studio/publishing` | publishing |
| `…workspace_settings` | `…/studio/settings` | settings |
| Hidden/nested | questions, capacity, extras, fulfilment, content | `navigationVisible: FALSE` on several plugins |
| `myeventlane_event_studio.autosave` | `/vendor/events/autosave` | POST + CSRF header |
| `myeventlane_event_studio.publish` | `/vendor/events/{node}/studio/publish` | POST + CSRF header |

---

## 4. Controllers

| Controller | Theme / response |
| --- | --- |
| `EventStudioController::workspace` | `#theme => mel_event_studio_workspace` |
| `EventStudioAutosaveController` | JSON |
| `EventStudioPublishController` | JSON (+ Home guide AJAX refresh) |
| `EventWorkspaceController::workspace` | `mel_event_workspace` via `buildVendorPage` |
| Vendor event Orders / Overview / Analytics / Settings / RSVP / Attendees / Addon | `mel_event_workspace` |
| Door Mode ops | `mel_event_workspace` + venue ops library |

---

## 5. Builders & view models

| Service / class | Module | Role |
| --- | --- | --- |
| `EventWorkspaceOverviewBuilder` | `myeventlane_event_studio` | Studio **Home** render (`mel_event_studio_overview`) — readiness, next action, ops cards |
| `VendorEventWorkspaceViewModelBuilder` | `myeventlane_vendor` | Mission-control payload; **reused** by Home guide state |
| `EventStudioSectionManager` + `Plugin/EventStudioSection/*` | `myeventlane_event_studio` | Section nav + metadata |
| `EventStudioSectionRenderer` | `myeventlane_event_studio` | Section bodies (Home → overview builder) |
| `EventStudioWorkspacePresentation` | `myeventlane_event_studio` | Readiness strip / event health |
| `EventReadinessFacade` / `EventReadinessService` | `myeventlane_event_studio` | Publish readiness evaluation |
| `PublishEligibilityEvaluator` | `myeventlane_event_studio` | Publish gating |
| `PaidPublishStripeGate` | (Studio stack) | Stripe health on Home |
| `VendorEventTabsService` | `myeventlane_vendor` | Tab rows (mostly Studio route targets) |
| `EventStudioSaveService` / `EventStudioAutosaveService` | `myeventlane_event_studio` | Persist + draft autosave |
| Sales / extras summary builders | `myeventlane_event_studio` | Compact panels on Manager root |

---

## 6. Twig templates

### Studio shell

| Hook / file |
| --- |
| `mel-event-studio-workspace.html.twig` |
| `mel-event-studio-overview.html.twig` (Home) |
| `mel-event-studio-sidebar.html.twig` |
| `mel-event-studio-topbar.html.twig` |
| `mel-event-studio-section.html.twig` |
| `mel-event-studio-attendees-workspace.html.twig` |

Registered in `myeventlane_event_studio.module` `hook_theme()`.

### Manager shell

| File |
| --- |
| `web/themes/custom/myeventlane_vendor_theme/templates/mel-event/mel-event-workspace.html.twig` |
| `…/components/workspace/workspace-tabs.html.twig` |
| `…/components/workspace/workspace-header.html.twig` |
| `…/event/mel-event-overview-page.html.twig` |
| `…/event/mel-event-settings-page.html.twig` |

---

## 7. Theme suggestions & preprocess

| Mechanism | Evidence |
| --- | --- |
| Studio preprocess | `template_preprocess_mel_event_studio()` → `EventStudioPreprocess` |
| Studio HTML markers | `myeventlane_event_studio_preprocess_html` |
| Manager workspace preprocess | `myeventlane_vendor_theme_preprocess_mel_event_workspace()` |
| Console page tabs | `VendorConsolePagePreprocess` (shorter Studio URL list) |
| Vendor theme negotiator | `VendorThemeNegotiator` → `myeventlane_vendor_theme` for `/vendor/*` |

---

## 8. Libraries & SCSS

| Library | Role |
| --- | --- |
| `myeventlane_event_studio/mel_event_studio_shell_only` | Studio workspace shell CSS/JS |
| `myeventlane_event_studio/mel_event_studio_tickets_app` / `attendees_app` | Section apps |
| `myeventlane_vendor_theme/vendor-workspace` | Manager shell grid (`workspace.scss`) |
| `myeventlane_vendor_theme/event_mission_control` | Mission-control SCSS |
| `myeventlane_vendor_theme/event_overview` | Overview / charts |
| Panel libs | Ticket/extras sales panel CSS from Event Studio |

SCSS homes: `mel-event-studio-shell.css`, `mel-event-studio-nav.css`, vendor `_event-mission-control.scss`, `_workspace.scss`, `workspace.scss`.

---

## 9. Cache

| Surface | Evidence |
| --- | --- |
| Studio workspace | `#cache` tags = `$node->getCacheTags()`; contexts `route`, `user`, `user.permissions`, `url.query_args:mel_celebrate` |
| Studio access | Cacheable deps on event/vendor + user contexts |
| Manager `EventWorkspaceController` | **No** `#cache` on workspace root render array |
| Manager overview body | Tags/contexts from `$event` |
| `VendorEventWorkspaceViewModelBuilder` | No builder-level `#cache` metadata |

---

## 10. Workspace shell

| Concern | Studio (organiser) | Manager (staff / residual) |
| --- | --- | --- |
| Theme | `mel_event_studio_workspace` | `mel_event_workspace` |
| Event chrome | Topbar (name, status, publish) | Workspace header + tabs |
| Section nav | Sidebar plugins (`EventStudioSectionManager`) | Tabs Twig + local tasks + `VendorEventTabsService` |
| Global shell | Same vendor theme sidebar (DDR-001) | Same |

**Triple nav sources (debt):** Studio sidebar plugins ≠ `VendorEventTabsService` ≠ `VendorConsolePagePreprocess` ≠ Drupal local tasks.

---

## 11. Per-surface inventory

| Surface | Exists | Primary organiser home | Reusable? | Never change casually |
| --- | --- | --- | --- | --- |
| **Overview / Home** | Yes | Studio `workspace` + `EventWorkspaceOverviewBuilder` | Yes — extend Home, don’t fork | Next-action resolution order |
| **Publishing** | Yes | `workspace_publishing` | Yes | `PublishEligibilityEvaluator` + CSRF publish |
| **Readiness** | Yes | Home + strip + Publishing | Yes — facade | Honesty of green/ready |
| **Tickets** | Yes | `workspace_tickets` | Yes | Commerce variation modelling |
| **Orders** | Yes | `workspace_orders` (readonly section) | Yes | Order ownership / payment state |
| **Attendees** | Yes | `workspace_attendees` | Yes | Access / PII |
| **Messages** | Yes | `workspace_messaging` | Yes | Audience boundaries |
| **Marketing** | Yes | `workspace_marketing` | Yes | Boost spend honesty |
| **Analytics** | Yes | `workspace_analytics` | Yes | Pro gating if present |
| **Door Mode** | Yes | `/operations/door` (Manager theme) | Yes — keep under Attendees IA | Check-in mutation access |
| **Settings** | Yes | `workspace_settings` | Yes | Dangerous toggles + ownership |

---

## 12. Current event payload

### Studio Home (`EventWorkspaceOverviewBuilder::build`)

```text
#theme mel_event_studio_overview
#event_ready, #next_action, #readiness
#tickets, #attendees, #sales, #marketing, #boost, #analytics, #activity
```

### Shared VM (`VendorEventWorkspaceViewModelBuilder`) — confirmed top-level keys

```text
event { nid, title, status, status_label, status_severity, date_label,
        event_type, event_type_label, public_url, image }
readiness, operational_readiness, todays_focus, sales_snapshot
action_grid { sales[], setup[], growth[] }
readiness_summary, lifecycle_guidance, next_action, metrics, tabs
actions { edit, advanced_tickets, edit_tickets, extras, promote, rsvps,
          orders, addon_orders, attendees, checkin, analytics, settings, preview }
presentation_alerts, empty_state
```

### Studio shell variables

```text
node, sections, sidebar_guidance, current_section*, section_content,
topbar, event_health, readiness, homepage_readiness, boost*, publish_handoff
```

---

## 13. CTA hierarchy (Studio Home — organiser truth)

From `resolveNextRecommendedAction()` + overview Twig:

1. **Primary:** Next recommended action (`next_action`) — booking/setup error from VM → publish blockers → Stripe connect → “Go to publishing” → “Share” (marketing)
2. **Event Ready** card + expandable checklist
3. **Ops cards** (Tickets, Attendees, Sales, Marketing, Boost, Analytics) with ghost CTAs
4. **Topbar publish** (shell) — separate from Home next-action
5. Mobile: Next Action `order: -1` below 720px (`mel-event-studio-shell.css`)

Manager hero (if reached): Edit event → Preview → Advanced ticket tools + Today’s focus + action grid.

---

## 14. Save / autosave / publish

| Flow | Runtime |
| --- | --- |
| Explicit section save | `EventStudioBaseForm` → `EventStudioSaveService::save` |
| Autosave | POST `myeventlane_event_studio.autosave`; JS delay **12000ms**; only if section `supports_autosave`; draft-oriented |
| Publish | POST `myeventlane_event_studio.publish`; dirty/stale/draft conflict checks; eligibility + Stripe gate; AJAX refreshes Home guide |
| Money / refunds | Explicit confirm paths — do not silent-commit via autosave |

---

## 15. Navigation (Studio section order — weights)

```text
Home(0) → Details(10) → Schedule(20) → Venue(30) → Images(40) → Tickets(50)
→ Attendees(60) → Messages(70) → Marketing(80) → Orders(90)
→ Analytics(100) → Publishing(110) → Settings(120)
```

PDS 13 places **Orders before Attendees**. Runtime places **Attendees before Orders** (live-ops bias). Documented as conflict C3.

---

## 16. Mobile behaviour

- Studio shell JS: mobile priority sidebar filtering (`data-mobile-priority`)
- Home next-action promoted on small screens
- Door Mode: separate stress UI; large targets expected by PDS 08/13
- Breakpoints dense in shell CSS (480–900+)

---

## 17. What already exists / reusable / must not change

### Already exists

- Full Studio section plugin architecture
- Home mission-control builder + readiness facade
- Autosave + publish JSON APIs with CSRF
- Manager mission-control VM + Twig (staff / residual)
- Door Mode route and check-in stack
- Legacy redirect funnel (organisers → Studio)

### Reusable for Workspace v2

- `EventWorkspaceOverviewBuilder` + overview Twig
- `VendorEventWorkspaceViewModelBuilder` (shared next_action / focus)
- Section plugins / `EventStudioSectionManager`
- Readiness facade + publish eligibility
- Vendor theme layout intents / tokens (DDR-003/11)
- Shell libraries (`mel_event_studio_shell_only`)

### Should never change without explicit risk review

- `EventVendorAccessChecker` / `EventStudioAccess` / `accountHasWorkspaceParityForEvent`
- Commerce order/payment state truth on Orders
- Publish eligibility + Stripe paid-publish gate
- Autosave dirty/stale vs publish conflict rules
- CSRF on autosave/publish
- Door Mode check-in access / mutations
- Help audience boundaries

---

## 18. Redirect map (organiser funnel)

Evidence: `VendorLegacyWizardRedirectSubscriber.php`

Most Manager console event routes → Studio destinations. Door Mode routes handled separately (canonical door ops). Staff with `administer nodes` / uid 1 skip redirect.

---

## Cannot confirm from repository alone

- Production hit rates for Manager vs Studio after redirect
- Full page-cache HIT/MISS for Manager workspace without `#cache`
- Whether every deep link (Boost wizards, diagnostics) is on the redirect list
- Exact visual Door Mode class inventory beyond theme hook + library attach

---

## Related packs

- Dashboard Foundation discovery: `docs/design/vendor-dashboard-v2/`
- PDS home: `docs/design/vendor-studio/`
- Prior VX2 notes: `docs/implementation/vx2-event-workspace-home-redesign.md`, `docs/vendor-console-v2-audit.md`
