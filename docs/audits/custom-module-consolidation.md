# Custom Module Consolidation Audit

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-22  
**Scope:** All modules under `web/modules/custom/`  
**Type:** Audit only — no modules were disabled, uninstalled, merged, or deleted.

---

## Decision update — 2026-08-09

Owner review confirmed the ticket-module boundary:

- Keep `mel_ticket` as the reusable ticket-type entity foundation.
- Keep `myeventlane_tickets` as the canonical ticket runtime and operational
  module.
- Retire `mel_universal_ticket` through a two-release compatibility migration.

The first retirement release transfers legacy field-provider metadata to
`myeventlane_tickets`, retains the old capability-manager service ID as an
alias, and removes `mel_universal_ticket` from exported enabled configuration.
The module directory must remain until every deployed environment confirms the
module is disabled and ticket/redemption field storage is intact.

This decision supersedes the ticket-trio **needs owner review** entries below;
the remaining audit counts are preserved as the original June snapshot.

---

## Executive summary

| Metric | Count |
|--------|------:|
| Custom modules with `*.info.yml` | **85** |
| Enabled (local DDEV) | **80** |
| Disabled (local DDEV) | **5** |
| Non-module stub directory | **1** (`myeventlane_analytics_pageviews/` — README only) |

**Key findings**

1. **Escalations cluster (9 modules)** is an intentional submodule architecture, not empty stubs. The base module `myeventlane_escalations` is thin (entity + admin routes only; no `.module`, no `services.yml`) while feature submodules carry portal, SLA, AI, analytics, policy, capacity, and refund-correlation logic. All nine are **enabled** locally.
2. **Five modules are disabled** locally: `mel_admin_dashboard`, `myeventlane_debug`, `myeventlane_diagnostics`, `myeventlane_docs_importer`, `myeventlane_questions`.
3. **Legacy / shim modules** exist for route compatibility (`myeventlane_help_centre_ai`, `myeventlane_support`, `myeventlane_vendor_settings`) and a **replacement-in-progress** admin dashboard (`mel_admin_dashboard` vs `myeventlane_admin_dashboard`).
4. **Thin service-only modules** (no `.module` file, few PHP classes) are common and often intentional: e.g. `myeventlane_attendee`, `myeventlane_api`, `myeventlane_stripe`, `myeventlane_help_shared`, `myeventlane_metrics`, `myeventlane_vendor_analytics`.
5. **Highest consolidation interest** (outside escalations): admin dashboard pair, help legacy shims, vendor settings compatibility shim, ticket module trio (`mel_ticket`, `mel_universal_ticket`, `myeventlane_tickets`), and dev/seed tooling enabled in production-like environments.

---

## Validation commands run

```bash
ddev drush pm:list --type=module --status=enabled
find web/modules/custom -maxdepth 2 -name "*.info.yml" | sort
ddev drush cst
```

### Config status (`ddev drush cst`)

Six configs differ between active store and sync (unrelated to this audit; noted for completeness):

| Config | State |
|--------|-------|
| `core.entity_form_display.node.event.default` | Different |
| `core.entity_form_display.node.event.studio_branding` | Different |
| `core.entity_view_display.node.event.full` | Different |
| `field.field.node.event.field_event_image` | Different |
| `field.storage.node.field_event_image` | Different |
| `image_widget_crop.settings` | Different |

---

## Recommendation legend

| Recommendation | Meaning |
|----------------|---------|
| **keep** | Active production module; substantial responsibility; no consolidation action suggested now |
| **disable candidate** | Safe to disable in production or when superseded; dev/ops tooling |
| **delete candidate** | Stub, placeholder, or compatibility shim whose removal should be planned after dependency audit |
| **merge candidate** | Overlapping responsibility with another module; consolidation would reduce surface area |
| **needs owner review** | Unclear ownership, replacement in progress, or disabled with uncertain product intent |

---

## Priority consolidation candidates

| Module(s) | Issue | Recommendation |
|-----------|-------|----------------|
| `mel_admin_dashboard` + `myeventlane_admin_dashboard` | Both target Platform Control Centre (`/admin/myeventlane`); replacement documented in `mel_admin_dashboard.info.yml` | **needs owner review** — finish cutover or abandon replacement |
| `myeventlane_help_centre_ai` | Legacy redirect shim for `/help/ask` | **merge candidate** → `myeventlane_help_assistant` |
| `myeventlane_support` | Legacy `/support` redirect + optional JSON API | **merge candidate** → `myeventlane_help_centre` |
| `myeventlane_help_shared` | Single shared retriever service (1 PHP class) | **merge candidate** → `myeventlane_help_assistant` |
| `myeventlane_vendor_settings` | Explicit compatibility metadata shim | **delete candidate** after route/config migration audit |
| `myeventlane_analytics_pageviews/` | README stub only; not a Drupal module | **delete candidate** or implement; do not leave as orphan directory |
| `mel_ticket` + `mel_universal_ticket` + `myeventlane_tickets` | Three ticket-related modules with overlapping domain | **needs owner review** |
| `myeventlane_demo`, `myeventlane_seed` | Drush seed/demo tooling; enabled locally | **disable candidate** for production |
| `myeventlane_debug` | Response tracing; already disabled | **disable candidate** (keep in repo for dev) |
| `myeventlane_questions` | Disabled; store-based question library | **needs owner review** vs Event Studio / checkout questions |
| `myeventlane_diagnostics` | Disabled; event readiness diagnostics | **needs owner review** vs `myeventlane_event_state` |
| Escalations AI pair | `myeventlane_escalations_ai` + `myeventlane_escalations_ai_draft` | **merge candidate** (optional submodule fold) |
| Escalations staff dashboards | `myeventlane_escalations_analytics` + `myeventlane_escalations_capacity` | **merge candidate** (staff UX consolidation) |

---

## Special focus: Escalations cluster

### Architecture

The escalations system uses a **hub-and-spoke** pattern:

```
myeventlane_escalations (core entity: escalation)
├── myeventlane_escalations_sla (timers, breach, level fields)
├── myeventlane_escalations_portal (customer/vendor threading, admin console)
├── myeventlane_escalations_ai (triage / insight entities, queue worker)
├── myeventlane_escalations_ai_draft (staff draft reply UI)
├── myeventlane_escalations_analytics (vendor health, staff dashboards)
├── myeventlane_escalations_capacity (staff workload dashboard)
├── myeventlane_escalations_policy (weekly vendor risk evaluation; depends on analytics)
└── myeventlane_escalations_refunds (read-only correlation with myeventlane_refunds)
```

Related but separate: `myeventlane_support_console` (staff landing), `myeventlane_staff_playbooks` (+ `_ai`), `myeventlane_refunds` (refund engine).

### Dependency chain

| Module | Depends on |
|--------|------------|
| `myeventlane_escalations` | `myeventlane_core`, `myeventlane_vendor` |
| `myeventlane_escalations_sla` | `myeventlane_escalations` |
| `myeventlane_escalations_portal` | `myeventlane_escalations`, `myeventlane_escalations_sla`, `myeventlane_vendor` |
| `myeventlane_escalations_ai` | `myeventlane_ai`, `myeventlane_escalations` |
| `myeventlane_escalations_ai_draft` | `myeventlane_ai`, `myeventlane_escalations`, `myeventlane_escalations_portal`, `myeventlane_escalations_sla`, `myeventlane_staff_playbooks` |
| `myeventlane_escalations_analytics` | `myeventlane_escalations`, `myeventlane_escalations_sla`, `myeventlane_vendor` |
| `myeventlane_escalations_capacity` | `myeventlane_escalations` |
| `myeventlane_escalations_policy` | `myeventlane_escalations_analytics` |
| `myeventlane_escalations_refunds` | `myeventlane_escalations`, `myeventlane_refunds`, Commerce |

### Per-module assessment (escalations cluster)

| Machine name | Enabled | Files | PHP classes | Apparent responsibility | Recommendation |
|--------------|---------|------:|------------:|-------------------------|----------------|
| `myeventlane_escalations` | Yes | 11 | 4 | `escalation` content entity, admin CRUD routes, permissions | **keep** (required base) |
| `myeventlane_escalations_sla` | Yes | 30 | 7 | SLA policy, timers, breach detection, queue worker, 13 install field configs | **keep** |
| `myeventlane_escalations_portal` | Yes | 32 | 12 | Customer/vendor portal, comment threading, mailer, admin case console | **keep** (largest UX surface) |
| `myeventlane_escalations_ai` | Yes | 21 | 7 | AI triage/insights entity, queue worker, staff UI panel | **keep** (or **merge candidate** with `_ai_draft`) |
| `myeventlane_escalations_ai_draft` | Yes | 12 | 3 | Staff-only draft reply generation with playbook context | **merge candidate** → `myeventlane_escalations_ai` |
| `myeventlane_escalations_analytics` | Yes | 14 | 5 | Vendor health scoring, staff/vendor analytics dashboards | **keep** (or **merge candidate** with `_capacity`) |
| `myeventlane_escalations_capacity` | Yes | 14 | 4 | Staff workload / capacity dashboard | **merge candidate** → `myeventlane_escalations_analytics` |
| `myeventlane_escalations_policy` | Yes | 14 | 5 | Automated weekly vendor policy evaluation | **keep** |
| `myeventlane_escalations_refunds` | Yes | 17 | 5 | Read-only escalation ↔ refund correlation; 1 kernel test | **keep** |

### Escalations cluster verdict

**Not stubs.** The cluster is a deliberate bounded-context split (entity vs SLA vs portal vs AI vs analytics vs policy vs refunds). Submodule boundaries align with optional features and dependency isolation (e.g. refunds module requires Commerce + `myeventlane_refunds`).

**Consolidation options (future pass only):**

1. **Fold AI draft into AI** — `myeventlane_escalations_ai_draft` has only 3 PHP classes and depends on `_ai`'s parent stack; low risk merge if product accepts one "Escalations AI" module.
2. **Fold capacity into analytics** — both serve staff dashboards; merge reduces menu sprawl.
3. **Do not merge base, portal, SLA, or refunds** without full regression — these own entity schema, customer-facing flows, timer fields, and Commerce correlation respectively.

---

## Non-module stub

| Path | Has `*.info.yml` | Contents | Recommendation |
|------|------------------|----------|----------------|
| `web/modules/custom/myeventlane_analytics_pageviews/` | No | `README.md` only — documents unimplemented pageview tracking | **delete candidate** or promote to real module when implemented |

---

## Full module inventory

Columns: **Status** = local DDEV enable state; **src** = PHP class count under `src/`; **cfg** = `config/install` YAML count; **tests** = files under `tests/`.

| Machine name | Path | Status | .module | services | routing | permissions | src | cfg | tests | Apparent responsibility | Recommendation |
|--------------|------|--------|---------|----------|---------|-------------|----:|----:|------:|-------------------------|----------------|
| `mel_admin_dashboard` | `web/modules/custom/mel_admin_dashboard` | Disabled | yes | yes | yes | no | 2 | 0 | 0 | Platform Control Centre replacement for `myeventlane_admin_dashboard` | **needs owner review** |
| `mel_ticket` | `web/modules/custom/mel_ticket` | Enabled | yes | no | no | yes | 6 | 0 | 0 | Reusable ticket type entities (RSVP, paid, external) | **needs owner review** (overlap with `myeventlane_tickets`) |
| `mel_universal_ticket` | `web/modules/custom/mel_universal_ticket` | Enabled | no | yes | no | no | 1 | 0 | 1 | Universal entitlement foundation on tickets | **needs owner review** |
| `myeventlane_account` | `web/modules/custom/myeventlane_account` | Enabled | yes | yes | yes | yes | 15 | 11 | 3 | Customer My Account dashboard and profile | **keep** |
| `myeventlane_admin_dashboard` | `web/modules/custom/myeventlane_admin_dashboard` | Enabled | yes | yes | yes | yes | 25 | 2 | 1 | Platform admin dashboard (events, vendors, stats) | **keep** (until `mel_admin_dashboard` cutover) |
| `myeventlane_ai` | `web/modules/custom/myeventlane_ai` | Enabled | yes | yes | yes | yes | 16 | 9 | 0 | Generic AI provider abstraction | **keep** |
| `myeventlane_analytics` | `web/modules/custom/myeventlane_analytics` | Enabled | yes | yes | yes | yes | 31 | 0 | 9 | Vendor analytics, funnels, time-series | **keep** |
| `myeventlane_api` | `web/modules/custom/myeventlane_api` | Enabled | no | yes | yes | yes | 11 | 0 | 0 | Public and vendor-scoped REST API | **keep** |
| `myeventlane_attendee` | `web/modules/custom/myeventlane_attendee` | Enabled | no | yes | no | yes | 8 | 0 | 0 | Attendee abstraction (RSVP + ticket unified) | **keep** |
| `myeventlane_auth` | `web/modules/custom/myeventlane_auth` | Enabled | yes | yes | yes | yes | 28 | 1 | 0 | OAuth2-style SSO, JWT, refresh tokens | **keep** |
| `myeventlane_automation` | `web/modules/custom/myeventlane_automation` | Enabled | yes | yes | yes | yes | 19 | 8 | 0 | Event notification automation (reminders, waitlist, etc.) | **keep** |
| `myeventlane_blocks` | `web/modules/custom/myeventlane_blocks` | Enabled | yes | no | no | no | 4 | 0 | 0 | Homepage custom blocks | **keep** |
| `myeventlane_boost` | `web/modules/custom/myeventlane_boost` | Enabled | yes | yes | yes | yes | 47 | 2 | 14 | Event promotion / featuring via Commerce | **keep** |
| `myeventlane_capacity` | `web/modules/custom/myeventlane_capacity` | Enabled | yes | yes | no | no | 4 | 0 | 1 | Event capacity tracking and enforcement | **keep** |
| `myeventlane_cart` | `web/modules/custom/myeventlane_cart` | Enabled | yes | no | no | no | 1 | 0 | 0 | Cart-phase per-ticket attendee capture | **keep** |
| `myeventlane_checkin` | `web/modules/custom/myeventlane_checkin` | Enabled | yes | yes | yes | yes | 3 | 0 | 0 | Mobile-first event check-in | **keep** |
| `myeventlane_checkout_flow` | `web/modules/custom/myeventlane_checkout_flow` | Enabled | yes | yes | yes | no | 33 | 2 | 9 | Custom single-page Commerce checkout | **keep** |
| `myeventlane_checkout_paragraph` | `web/modules/custom/myeventlane_checkout_paragraph` | Enabled | yes | yes | yes | no | 13 | 10 | 2 | Checkout attendee forms via Paragraphs | **keep** |
| `myeventlane_commerce` | `web/modules/custom/myeventlane_commerce` | Enabled | yes | yes | yes | no | 64 | 0 | 19 | Commerce integration, booking page, ticket products | **keep** |
| `myeventlane_core` | `web/modules/custom/myeventlane_core` | Enabled | yes | yes | yes | yes | 85 | 5 | 4 | Core platform services and shared architecture | **keep** |
| `myeventlane_dashboard` | `web/modules/custom/myeventlane_dashboard` | Enabled | yes | yes | yes | yes | 15 | 0 | 0 | Vendor dashboard hub | **keep** |
| `myeventlane_debug` | `web/modules/custom/myeventlane_debug` | Disabled | no | yes | no | no | 1 | 0 | 0 | Response tracing / diagnostics | **disable candidate** |
| `myeventlane_demo` | `web/modules/custom/myeventlane_demo` | Enabled | no | yes | no | no | 2 | 0 | 0 | Demo event seeding via Drush | **disable candidate** (prod) |
| `myeventlane_diagnostics` | `web/modules/custom/myeventlane_diagnostics` | Disabled | yes | yes | yes | yes | 4 | 0 | 0 | Event configuration readiness diagnostics | **needs owner review** |
| `myeventlane_docs_importer` | `web/modules/custom/myeventlane_docs_importer` | Disabled | yes | yes | no | no | 4 | 3 | 0 | CSV import of help/playbook content via Drush | **disable candidate** (ops-only) |
| `myeventlane_domain_events` | `web/modules/custom/myeventlane_domain_events` | Enabled | no | yes | yes | no | 22 | 0 | 0 | Append-only domain event store + projectors | **keep** |
| `myeventlane_donations` | `web/modules/custom/myeventlane_donations` | Enabled | yes | yes | yes | yes | 25 | 1 | 0 | Platform and RSVP donations via Stripe Connect | **keep** |
| `myeventlane_escalations` | `web/modules/custom/myeventlane_escalations` | Enabled | no | no | yes | yes | 4 | 0 | 0 | Escalation entity and admin routes | **keep** |
| `myeventlane_escalations_ai` | `web/modules/custom/myeventlane_escalations_ai` | Enabled | yes | yes | yes | yes | 7 | 1 | 0 | AI triage/insights for escalations | **keep** |
| `myeventlane_escalations_ai_draft` | `web/modules/custom/myeventlane_escalations_ai_draft` | Enabled | yes | yes | yes | yes | 3 | 0 | 0 | Staff AI draft replies for escalations | **merge candidate** |
| `myeventlane_escalations_analytics` | `web/modules/custom/myeventlane_escalations_analytics` | Enabled | yes | yes | yes | yes | 5 | 0 | 0 | Escalation analytics and vendor health | **keep** |
| `myeventlane_escalations_capacity` | `web/modules/custom/myeventlane_escalations_capacity` | Enabled | yes | yes | yes | yes | 4 | 1 | 0 | Staff workload/capacity dashboard | **merge candidate** |
| `myeventlane_escalations_policy` | `web/modules/custom/myeventlane_escalations_policy` | Enabled | yes | yes | yes | yes | 5 | 1 | 0 | Weekly vendor policy evaluation | **keep** |
| `myeventlane_escalations_portal` | `web/modules/custom/myeventlane_escalations_portal` | Enabled | yes | yes | yes | yes | 12 | 4 | 0 | Customer/vendor support portal and threading | **keep** |
| `myeventlane_escalations_refunds` | `web/modules/custom/myeventlane_escalations_refunds` | Enabled | yes | yes | yes | yes | 5 | 1 | 1 | Escalation ↔ refund read-only correlation | **keep** |
| `myeventlane_escalations_sla` | `web/modules/custom/myeventlane_escalations_sla` | Enabled | yes | yes | yes | yes | 7 | 13 | 0 | SLA timers, breach detection, escalation levels | **keep** |
| `myeventlane_event` | `web/modules/custom/myeventlane_event` | Enabled | yes | yes | yes | yes | 60 | 14 | 6 | Canonical event orchestration and CTA building | **keep** |
| `myeventlane_event_attendees` | `web/modules/custom/myeventlane_event_attendees` | Enabled | yes | yes | yes | yes | 21 | 0 | 1 | Unified attendance records (RSVP + tickets) | **keep** |
| `myeventlane_event_state` | `web/modules/custom/myeventlane_event_state` | Enabled | yes | yes | yes | yes | 5 | 0 | 0 | Event state machine and resolution | **keep** |
| `myeventlane_event_studio` | `web/modules/custom/myeventlane_event_studio` | Enabled | yes | yes | yes | no | 102 | 0 | 46 | Vendor Event Studio authoring UI | **keep** |
| `myeventlane_finance` | `web/modules/custom/myeventlane_finance` | Enabled | yes | yes | yes | yes | 6 | 0 | 0 | Financial reporting and BAS aggregation | **keep** |
| `myeventlane_front` | `web/modules/custom/myeventlane_front` | Enabled | yes | yes | yes | no | 38 | 0 | 10 | Front page UI blocks and homepage logic | **keep** |
| `myeventlane_growth` | `web/modules/custom/myeventlane_growth` | Enabled | yes | yes | yes | yes | 11 | 1 | 0 | Growth insights, nudges, funnel tracking | **keep** |
| `myeventlane_help_assistant` | `web/modules/custom/myeventlane_help_assistant` | Enabled | yes | yes | yes | yes | 13 | 1 | 2 | Grounded AI Help Centre assistant | **keep** |
| `myeventlane_help_centre` | `web/modules/custom/myeventlane_help_centre` | Enabled | yes | yes | yes | yes | 26 | 12 | 0 | Public/vendor help centre content | **keep** |
| `myeventlane_help_centre_ai` | `web/modules/custom/myeventlane_help_centre_ai` | Enabled | yes | yes | yes | no | 1 | 0 | 0 | Legacy `/help/ask` redirect shim | **merge candidate** |
| `myeventlane_help_improvement` | `web/modules/custom/myeventlane_help_improvement` | Enabled | yes | yes | yes | yes | 10 | 0 | 0 | Doc improvement queue and AI draft assistance | **keep** |
| `myeventlane_help_shared` | `web/modules/custom/myeventlane_help_shared` | Enabled | no | yes | no | no | 1 | 0 | 0 | Shared help retrieval service | **merge candidate** |
| `myeventlane_launch` | `web/modules/custom/myeventlane_launch` | Enabled | yes | yes | yes | no | 13 | 0 | 0 | Launch readiness orchestration | **needs owner review** (post-launch) |
| `myeventlane_legal` | `web/modules/custom/myeventlane_legal` | Enabled | yes | yes | yes | yes | 17 | 4 | 4 | Terms, privacy, cookie consent, audit | **keep** |
| `myeventlane_location` | `web/modules/custom/myeventlane_location` | Enabled | yes | yes | yes | no | 9 | 1 | 0 | Event location, maps, address autocomplete | **keep** |
| `myeventlane_messaging` | `web/modules/custom/myeventlane_messaging` | Enabled | yes | yes | yes | yes | 41 | 16 | 4 | Email templates and transactional messaging | **keep** |
| `myeventlane_metrics` | `web/modules/custom/myeventlane_metrics` | Enabled | yes | yes | no | no | 2 | 0 | 0 | Centralized event metrics service | **keep** |
| `myeventlane_notifications` | `web/modules/custom/myeventlane_notifications` | Enabled | yes | yes | yes | yes | 34 | 0 | 1 | Platform notifications and in-app toasts | **keep** |
| `myeventlane_page_visuals` | `web/modules/custom/myeventlane_page_visuals` | Enabled | yes | yes | yes | yes | 7 | 0 | 0 | Admin-configurable route hero/illustrations | **keep** |
| `myeventlane_privacy` | `web/modules/custom/myeventlane_privacy` | Enabled | yes | no | yes | yes | 1 | 1 | 0 | Privacy tracking IDs and consent-aware scripts | **keep** |
| `myeventlane_pro` | `web/modules/custom/myeventlane_pro` | Enabled | yes | yes | yes | yes | 44 | 25 | 4 | Professional subscription feature layer | **keep** |
| `myeventlane_public_trust` | `web/modules/custom/myeventlane_public_trust` | Enabled | yes | yes | yes | yes | 4 | 1 | 0 | Public trust signals (marketing-safe) | **keep** |
| `myeventlane_questions` | `web/modules/custom/myeventlane_questions` | Disabled | yes | yes | yes | yes | 7 | 0 | 0 | Store-based reusable attendee question library | **needs owner review** |
| `myeventlane_refunds` | `web/modules/custom/myeventlane_refunds` | Enabled | yes | yes | yes | yes | 20 | 0 | 7 | Vendor refunds and cancellations | **keep** |
| `myeventlane_reporting` | `web/modules/custom/myeventlane_reporting` | Enabled | yes | yes | yes | yes | 6 | 0 | 0 | Vendor/admin reporting UI and exports | **keep** |
| `myeventlane_rsvp` | `web/modules/custom/myeventlane_rsvp` | Enabled | yes | yes | yes | yes | 42 | 3 | 6 | RSVP workflow, waitlist, ICS, check-in | **keep** |
| `myeventlane_schema` | `web/modules/custom/myeventlane_schema` | Enabled | yes | no | no | no | 0 | 140 | 0 | Core fields, types, taxonomies (foundational config) | **keep** |
| `myeventlane_search` | `web/modules/custom/myeventlane_search` | Enabled | yes | yes | yes | no | 3 | 4 | 0 | Search API grouped site search | **keep** |
| `myeventlane_seed` | `web/modules/custom/myeventlane_seed` | Enabled | no | yes | yes | yes | 8 | 1 | 0 | Deterministic test seed via Drush | **disable candidate** (prod) |
| `myeventlane_shared` | `web/modules/custom/myeventlane_shared` | Enabled | no | yes | no | no | 1 | 0 | 1 | Shared utilities (e.g. colour definitions) | **keep** |
| `myeventlane_staff_playbooks` | `web/modules/custom/myeventlane_staff_playbooks` | Enabled | yes | yes | yes | yes | 2 | 22 | 0 | Staff escalation playbooks (no AI) | **keep** |
| `myeventlane_staff_playbooks_ai` | `web/modules/custom/myeventlane_staff_playbooks_ai` | Enabled | yes | yes | yes | yes | 4 | 1 | 0 | AI summarisation for playbooks | **keep** |
| `myeventlane_stripe` | `web/modules/custom/myeventlane_stripe` | Enabled | no | yes | no | no | 1 | 0 | 0 | Stripe Connect / payout services | **keep** |
| `myeventlane_summary` | `web/modules/custom/myeventlane_summary` | Enabled | yes | yes | no | no | 2 | 0 | 0 | Pre-aggregated platform metrics (cron) | **keep** |
| `myeventlane_support` | `web/modules/custom/myeventlane_support` | Enabled | yes | no | yes | no | 1 | 0 | 0 | Legacy `/support` redirect and JSON search API | **merge candidate** |
| `myeventlane_support_console` | `web/modules/custom/myeventlane_support_console` | Enabled | yes | yes | yes | yes | 2 | 1 | 0 | Staff support console landing page | **keep** |
| `myeventlane_surface` | `web/modules/custom/myeventlane_surface` | Enabled | yes | yes | no | yes | 115 | 0 | 27 | Product surface registry and UX governance | **keep** |
| `myeventlane_theme_settings` | `web/modules/custom/myeventlane_theme_settings` | Enabled | no | no | yes | no | 2 | 0 | 0 | Admin UI for theme hero images | **needs owner review** (overlap with `page_visuals`) |
| `myeventlane_tickets` | `web/modules/custom/myeventlane_tickets` | Enabled | yes | yes | yes | yes | 94 | 1 | 30 | Ticket groups, PDFs, purchase surfaces | **keep** |
| `myeventlane_vendor` | `web/modules/custom/myeventlane_vendor` | Enabled | yes | yes | yes | yes | 95 | 63 | 5 | Vendor entity and organiser tooling | **keep** |
| `myeventlane_vendor_ai` | `web/modules/custom/myeventlane_vendor_ai` | Enabled | yes | yes | yes | yes | 5 | 0 | 0 | Vendor-facing policy assistant | **keep** |
| `myeventlane_vendor_analytics` | `web/modules/custom/myeventlane_vendor_analytics` | Enabled | yes | yes | no | no | 1 | 0 | 0 | Vendor KPI aggregation service (no UI) | **keep** |
| `myeventlane_vendor_comms` | `web/modules/custom/myeventlane_vendor_comms` | Enabled | no | yes | yes | no | 6 | 1 | 0 | Vendor event update emails to attendees | **keep** |
| `myeventlane_vendor_nudges` | `web/modules/custom/myeventlane_vendor_nudges` | Enabled | yes | yes | no | yes | 8 | 1 | 0 | Educational vendor dashboard nudges | **keep** |
| `myeventlane_vendor_settings` | `web/modules/custom/myeventlane_vendor_settings` | Enabled | no | no | yes | no | 1 | 0 | 0 | Compatibility shim for former vendor settings routes | **delete candidate** |
| `myeventlane_venue` | `web/modules/custom/myeventlane_venue` | Enabled | yes | yes | yes | yes | 29 | 1 | 0 | Reusable venues and public directory | **keep** |
| `myeventlane_views` | `web/modules/custom/myeventlane_views` | Enabled | yes | no | yes | no | 5 | 1 | 0 | Views plugins and CSV export | **keep** |
| `myeventlane_wallet` | `web/modules/custom/myeventlane_wallet` | Enabled | no | yes | yes | yes | 10 | 2 | 2 | Apple/Google Wallet ticket passes | **keep** |
| `myeventlane_webhooks` | `web/modules/custom/myeventlane_webhooks` | Enabled | no | yes | no | no | 3 | 0 | 0 | Webhook delivery for integrations | **keep** |

---

## Summary by recommendation

| Recommendation | Count | Modules |
|----------------|------:|---------|
| **keep** | 66 | Core platform, commerce, events, vendor, tickets, most escalations submodules, help (except shims), etc. |
| **disable candidate** | 4 | `myeventlane_debug`, `myeventlane_demo`, `myeventlane_docs_importer`, `myeventlane_seed` |
| **delete candidate** | 2 | `myeventlane_vendor_settings`, `myeventlane_analytics_pageviews/` (stub dir) |
| **merge candidate** | 7 | `myeventlane_help_centre_ai`, `myeventlane_support`, `myeventlane_help_shared`, `myeventlane_escalations_ai_draft`, `myeventlane_escalations_capacity` (+ optional AI/analytics folds noted above) |
| **needs owner review** | 8 | `mel_admin_dashboard`, `mel_ticket`, `mel_universal_ticket`, `myeventlane_diagnostics`, `myeventlane_questions`, `myeventlane_launch`, `myeventlane_theme_settings`, admin dashboard cutover |

---

## Suggested next steps (documentation / planning only)

1. **Escalations:** Product owner confirms whether submodule split remains desired long-term; if yes, document ownership in `project-rules.md` and defer merges except optional AI/capacity folds.
2. **Admin dashboard:** Decide cutover timeline for `mel_admin_dashboard` ↔ `myeventlane_admin_dashboard`.
3. **Legacy shims:** Schedule route-alias migration plan for `myeventlane_help_centre_ai`, `myeventlane_support`, `myeventlane_vendor_settings`.
4. **Production hygiene:** Confirm `myeventlane_demo` and `myeventlane_seed` are disabled on staging/production (currently enabled locally).
5. **Ticket modules:** Architecture review for `mel_ticket`, `mel_universal_ticket`, and `myeventlane_tickets` boundaries.
6. **Before any delete/merge:** Run dependency grep across `config/sync`, `composer.json`, and enabled extensions; re-run full test suite and escalations/support smoke paths.

---

## Residual risk

- Recommendations are based on **repository structure and local DDEV enable state** only; production enable lists may differ.
- Disabling or merging modules touches **config export, routes, permissions, and Commerce/checkout paths** — out of scope for this audit.
- Escalations submodules are **all enabled** and wired into staff console, admin dashboard, and vendor flows; consolidation requires regression coverage for support operations.
