# Vendor Dashboard v2 — Drupal Mapping (Sprint 1A)

**Status:** Design package only — no implementation  
**Authority:** [09-drupal-mapping.md](../vendor-studio/09-drupal-mapping.md) · repository evidence  
**Rule:** Do not invent architecture. Prefer reuse. If a payload is missing, Technical Authority chooses theme-only vs builder extension — not Twig philosophy redesign ([ADR-0002](../vendor-studio/decisions/ADR-0002-implementation-follows-pds.md)).

---

## 1. Runtime homes (confirmed)

| Concern | Path / ID |
| --- | --- |
| Organiser console theme | `web/themes/custom/myeventlane_vendor_theme` |
| Vendor domain module | `web/modules/custom/myeventlane_vendor` |
| Canonical route | `myeventlane_vendor.console.dashboard` → `/vendor/dashboard` |
| Access | `Drupal\myeventlane_vendor\Access\VendorConsoleAccess` · permission `access vendor dashboard` |
| Public theme | `web/themes/custom/myeventlane_theme` — **do not** implement Dashboard polish here |
| Body scope | `.mel-vendor` |

---

## 2. Controllers & builders (reuse)

| Class / service | Role | Reuse for v2 |
| --- | --- | --- |
| `VendorDashboardController` | Assembles page; attaches libraries; `buildVendorPage('myeventlane_vendor_dashboard', …)` | Keep as page owner; slim presentation vars to match PDS hierarchy |
| `VendorConsoleBaseController::buildVendorPage()` | Shared console page wrap + access assert | Reuse |
| `VendorDashboardViewModelBuilder` (`myeventlane_vendor.dashboard_view_model_builder`) | Vendor, readiness, KPIs, events, action queue, overview, upcoming, empty state, activity | **Primary payload source** — prefer exposing/using existing keys over new builders |
| `VendorActionQueueBuilder` (`myeventlane_vendor.action_queue_builder`) | Severity-ordered queue (max 6) | **Required** — ensure items expose severity, title, reason, CTA URL/label for Action Cards |
| `MelVendorDashboardActionQueueGovernance` | Presentation reorder/suppress | Keep presentation-only; do not invent money states |
| `VendorEventPresentationAlertsBuilder` | Presentation chips on event rows | Reuse for upcoming/focus badges where already used |
| `MetricsAggregator` / ticket & RSVP services | KPI inputs | Reuse for ≤4 Metric Cards |
| `VendorKpiService` (`myeventlane_vendor_analytics`) | STAGE A2 store KPIs (`vendor_kpis`) | Candidate data for consolidated strip — confirm product KPI set |
| `MelReadinessHelper` | Readiness / operational copy | Prefer feeding Action Queue / calm status line — not a competing Tools wall |
| `MelDataPresentationManager::decorateVendorDashboardMetricStrip()` | Metric presentation contracts | Reuse for Metric Cards |
| `MelOperationalPolicyManager` | Suppress growth when policy demands | Keep |
| `VendorNavBuilder` | Shell active section `dashboard` | Reuse — no Dashboard-local nav |
| `CurrentVendorResolverInterface` / membership query | Vendor + managed events | Reuse |

### Related — do not conflate with global Dashboard

| Class | Note |
| --- | --- |
| `EventWorkspaceOverviewBuilder` | Per-event Workspace Home — out of Sprint 1A redesign scope |
| `VendorDashboardMessagingBrandController` | Nested under `/vendor/dashboard/messaging/brand` — Messages settings, not home composition |
| `myeventlane_dashboard` module controllers | Legacy / customer — not live `/vendor/dashboard` owner |

---

## 3. Twig (reuse / reshape)

| Template | Role | v2 guidance |
| --- | --- | --- |
| `templates/dashboard/dashboard.html.twig` | Main organiser home | **Primary change surface** — reorder to PDS hierarchy; empty caught-up queue; remove priority inversion |
| `templates/page--vendor-dashboard.html.twig` | Page suggestion → shell | Keep |
| `templates/layout/page.html.twig` | VendorShell | Keep; no dual nav |
| `templates/includes/vendor-shell-main-content.html.twig` | Main content slot | Keep |
| `templates/includes/mel-vendor-dashboard-governance-stack.html.twig` | Governance `<details>` | Demote / keep collapsed; do not promote above queue |
| `templates/includes/footer-dashboard-light.html.twig` | Light footer | Keep if non-competing |
| `templates/includes/dashboard-mel-support-strip.html.twig` | Support CTA | Quietest help rank |
| `components/stripe-panel.html.twig` | Stripe status UI | Prefer Action Card path for incomplete connect; panel only if it does not invert priority |
| `components/vendor-kpi-strip.html.twig` | KPI strip partial | **Reuse** for ≤4 Metric Cards — currently unused by main Twig |
| `components/mel-event-card-thumb.html.twig` | Upcoming thumbs | Reuse |
| `components/mel-pro-badge.html.twig` / Pro panels | Pro confirmation | Celebration rank only — not above queue |
| Growth / analytics / homepage performance includes | Deep boards | Link out or keep deeply collapsed — not first paint |

Theme hook: `myeventlane_vendor_dashboard` (registered in vendor theme). Preprocess: `preprocess_myeventlane_vendor_dashboard` (activity timestamps) — extend carefully; no business logic in Twig.

---

## 4. Libraries (reuse)

| Library | Defined in | v2 guidance |
| --- | --- | --- |
| `myeventlane_vendor_theme/global-styling` | `myeventlane_vendor_theme.libraries.yml` | Shell + main CSS/JS |
| `myeventlane_vendor_theme/dashboard` | Alias → global-styling | Keep attach from controller |
| `myeventlane_vendor_theme/mel_event_card_remove` | Remove/archive dialog JS | Keep only if Upcoming/events still use it |
| `myeventlane_growth/dashboard_cards` | Conditional | Keep suppression via operational policy; do not expand on first paint |
| `myeventlane_vendor_theme/footer-internal` | Shell footer | Keep |

Do not invent a parallel `dashboard-v2` library unless Technical Authority requires asset isolation — prefer existing `dashboard` / global pipeline ([09](../vendor-studio/09-drupal-mapping.md)).

---

## 5. SCSS (reuse)

| File | Role | v2 guidance |
| --- | --- | --- |
| `src/scss/pages/_dashboard-live-ops.scss` | Primary `.mel-vendor-dashboard` composition | Reshape hierarchy styles here |
| `src/scss/pages/_dashboard.scss` | Older hero/governance | Avoid dual competing page systems; converge |
| `src/scss/pages/_dashboard-mel-support.scss` | Support strip | Quiet help |
| `src/scss/pages/_mel-dashboard.scss` | Broader workspace/analytics shell | Do not fork for home |
| `src/scss/components/_kpi-cards.scss` | KPI cards | Align to Metric Cards ≤4 |
| `src/scss/components/_empty-states.scss` | Empty states | Caught-up + first-run |
| `src/scss/components/_vendor-alert.scss` | Alerts | Errors/warnings |
| `src/scss/components/_buttons.scss` / badges / cards | Shared | Extend — do not invent `dashboard-*` twins |
| Layout tokens | `--mel-layout-dashboard` | Apply `.mel-layout--dashboard` — no hardcoded widths in Twig |

Build via existing vendor theme Vite pipeline (`npm run mel:lint` / `npm run mel:build` at implementation time).

---

## 6. Modules touching Dashboard (boundary map)

| Module | Touch level |
| --- | --- |
| `myeventlane_vendor` | Owner — route, controller, view model, queue, permissions |
| `myeventlane_surface` | Queue governance, metric decoration, operational policy |
| `myeventlane_vendor_analytics` | Optional KPI strip data |
| `myeventlane_core` | Readiness helper, onboarding, domain |
| `myeventlane_pro` | Pro panels — demote visually |
| `myeventlane_growth` / `myeventlane_boost` | Optional cards — suppress / demote |
| `myeventlane_front` / `myeventlane_event` | Homepage visibility / readiness — not first paint |
| `myeventlane_event_studio` | Destination of Open event — no home redesign in 1A |
| `myeventlane_vendor_nudges` | Not wired from dashboard controller in current evidence — do not invent wiring without Product |

---

## 7. Payload → PDS region mapping

| PDS region | Likely existing keys | Gap? |
| --- | --- | --- |
| Identity / Hero | `account`, `model.vendor`, `organiser_actions` (create) | Calm status line may use unused `hero_shell_hint` / lifecycle — confirm in builder |
| Action Queue | `model.action_queue` | Confirm **reason** field on items; extend builder if missing — not a new pattern |
| Today’s focus | `model.current_event` | Present |
| Upcoming | `model.upcoming_events` | Present |
| Business health ≤4 | `model.kpis`, `model.organiser_overview`, `vendor_kpis` | **Consolidate** — Product picks ≤4; stop dual strips |
| Activity | `model.activity_items` / `dashboard_activity_items` | Present |
| Empty first-run | `model.empty_state` | Present |
| Caught-up queue | — | **Theme state** when queue empty — required by PDS |
| Celebrations | Pro welcome / milestones | Only when earned; Success panel contract |
| Attention events list | `attention_events` built | Optional feed into queue/focus — do not invent second queue |

Controller extras (`dashboard_kpis`, `dashboard_action_cards`, `dashboard_alerts`) exist as vars but are unused in current Twig — prefer view model consistency; do not grow parallel APIs without cleanup.

---

## 8. Access, cache, Commerce boundaries

| Concern | Mapping |
| --- | --- |
| Access | Keep `VendorConsoleAccess`; no UI-only security |
| Cache contexts | Controller merges `user`, `user.roles` — verify vendor-scoped tags on entities used |
| Cache tags | **Gap:** add appropriate tags when implementing — do not skip |
| Stripe / payouts | Existing routes/panels only; UI must not claim connected/paid until state confirms |
| Orders / revenue KPIs | Commerce-backed services remain authoritative |
| Help audience | Organiser-safe only — no staff diagnostics |

---

## 9. What can be reused vs what must change

### Reuse as-is (architecture)

- Route ID and path  
- Access class + permissions  
- View model + Action Queue services  
- Theme hook name  
- Shell regions and `VendorNavBuilder`  
- Layout token `--mel-layout-dashboard`  
- Component SCSS foundations (buttons, alerts, empty states, KPI cards)  
- Libraries attach pattern  

### Likely reshape (presentation / composition)

- `dashboard.html.twig` section order and empty-queue landmark  
- Demotion of Pro / Tools / duplicate metrics  
- Optional thin builder fields: `reason` on queue items, caught-up copy keys, KPI allow-list  

### Do not invent

- New Dashboard module  
- Parallel component namespace  
- Dashboard-local navigation  
- New payment states  
- Widget customisation framework  
- AI assistant panel  

---

## 10. DDR check (architecture)

Existing Drupal homes match [09](../vendor-studio/09-drupal-mapping.md). **No architectural DDR required** to map Dashboard v2.

File a DDR only if Technical + Design Authority conclude the Action Queue must become a new entity type, a new route namespace, or a second shell — none of which Sprint 1A needs.

**Next:** [06-dashboard-implementation-plan.md](06-dashboard-implementation-plan.md)
