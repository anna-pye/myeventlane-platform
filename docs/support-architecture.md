# Support Architecture v1.0

Reference for the MyEventLane support subsystem. **No new features** — stabilisation only.

## Module Ownership

| Module | Ownership | Purpose |
|--------|-----------|---------|
| **myeventlane_escalations_sla** | Escalations SLA | SLA fields (first_due, resolution_due), escalation levels, SLA check queue, dashboard |
| **myeventlane_escalations_ai** | AI | Triage, reply suggestions, risk flags, breach_soon advisory; queue-based AI jobs |
| **myeventlane_escalations_ai_draft** | AI | Instant draft generation (staff UI) |
| **myeventlane_escalations_analytics** | Analytics | Escalation analytics dashboards, vendor health metrics, exports |
| **myeventlane_escalations_refunds** | Refunds correlation | Refund requests linked to escalations; vendor refund summary |
| **myeventlane_escalations_capacity** | Capacity | Staff workload and capacity analytics dashboard |
| **myeventlane_escalations_policy** | Policy | Policy triggers, vendor risk streak, escalation policy report |
| **myeventlane_escalations_portal** | Portal | Customer (/my/support) and vendor (/vendor/support) escalation UIs |
| **myeventlane_vendor_nudges** | Vendor nudges | Educational tips on vendor dashboard |
| **myeventlane_help_centre** | Help centre | Help articles, vendor help centre, staff snippet authoring |
| **myeventlane_help_centre_ai** | Help centre (legacy shim) | Redirects `/help/ask` → `/help/assistant` (single AI entry point) |
| **myeventlane_vendor_ai** | Vendor AI | MEL Assistant for vendors (policy questions, per-escalation) |
| **myeventlane_staff_playbooks** | Staff playbooks | Governance dashboard, playbook content type, communication standards |
| **myeventlane_staff_playbooks_ai** | Staff playbooks AI | AI summaries for playbooks (queue-based) |
| **myeventlane_public_trust** | Public trust | Trust signals page at /trust (marketing-safe aggregates) |

## Routes and Permissions Mapping

### Staff-only routes (require staff/admin permissions)

| Route | Permission | Module |
|-------|------------|--------|
| `/admin/myeventlane/escalations/dashboard` | manage escalations sla | myeventlane_escalations_sla |
| `/admin/myeventlane/escalations/{id}/ai/draft` | generate escalation ai drafts | myeventlane_escalations_ai |
| `/admin/myeventlane/escalations/{id}/ai/instant-draft` | generate escalation ai drafts | myeventlane_escalations_ai_draft |
| `/admin/myeventlane/escalations/capacity` | view escalation capacity dashboard | myeventlane_escalations_capacity |
| `/admin/myeventlane/escalations/policy` | view escalation policy report | myeventlane_escalations_policy |
| `/admin/myeventlane/escalations/analytics` | view escalation analytics | myeventlane_escalations_analytics |
| `/admin/myeventlane/escalations/analytics/export` | export escalation analytics | myeventlane_escalations_analytics |
| `/admin/myeventlane/governance` | administer escalations | myeventlane_staff_playbooks |
| `/admin/myeventlane/playbooks/{node}/ai/summary` | generate playbook ai summaries | myeventlane_staff_playbooks_ai |
| `/help/internal/staff-snippet-authoring` | administer escalations | myeventlane_help_centre |

### Vendor routes (require vendor role; vendor-scoped data only)

| Route | Permission | Module |
|-------|------------|--------|
| `/vendor/support` | view vendor escalations | myeventlane_escalations_portal |
| `/vendor/support/{escalation}` | view vendor escalations | myeventlane_escalations_portal |
| `/vendor/support/{escalation}/resolve` | resolve vendor escalations | myeventlane_escalations_portal |
| `/vendor/support/{escalation}/ai` | use vendor ai assistant | myeventlane_vendor_ai (custom access: vendor-scoped escalation) |
| `/vendor/support/refunds` | view vendor refund summary | myeventlane_escalations_refunds |
| `/vendor/support/analytics` | view vendor escalations | myeventlane_escalations_analytics |
| `/vendor/help` | view vendor help centre | myeventlane_help_centre |

### Customer routes

| Route | Permission | Module |
|-------|------------|--------|
| `/my/support` | view own escalation | myeventlane_escalations_portal |
| `/my/support/escalations` | view own escalation | myeventlane_escalations_portal |
| `/my/support/escalations/add` | create escalation | myeventlane_escalations_portal |
| `/my/support/escalations/{id}` | view own escalation | myeventlane_escalations_portal |

### Public routes (no auth required; safe content only)

| Route | Content safety | Module |
|-------|----------------|--------|
| `/help` | Help centre index (articles) | myeventlane_help_centre |
| `/help/ask` | **302** to `/help/assistant` (deprecated URL; logged) | myeventlane_help_centre_ai |
| `/help/assistant` | Help Assistant (retrieval + `AiManager`) | myeventlane_help_assistant |
| `/trust` | Trust signals (aggregated platform metrics; no PII, no numbers) | myeventlane_public_trust |

## Data Boundaries — What Must Never Be Exposed

- **Staff-only (never to vendor/authenticated):**
  - Escalation AI insights (triage, risk flags, breach advisories)
  - Staff capacity dashboard
  - Full escalation analytics exports
  - Policy triggers and risk streak details (beyond vendor’s own)
  - Staff playbook content (internal guides)
  - All-escalations view

- **Vendor-scoped only:**
  - Vendors see only escalations tied to their events
  - Vendor refund summary is aggregated, not per-order
  - Vendor analytics show only their health metrics

- **Public (e.g. /trust):**
  - TrustSignalFormatter outputs marketing-safe strings only
  - No exact numbers, percentages, or PII
  - Hedging language: “most”, “typically”, “generally”

- **Help Assistant (/help/assistant):**
  - Anonymous allowed; flood limits per visitor + per IP burst
  - Retrieval-first `help_article` content only; `AiManager` for completions

## Where Prompts and Guides Live

| Location | Content |
|---------|---------|
| `myeventlane_escalations_ai/config/install/myeventlane_escalations_ai.settings.yml` | Configurable prompts: triage, reply_suggestion, risk_flag, breach_soon |
| `myeventlane_help_assistant` | `HelpAssistantService` + `PromptDefinition` via `myeventlane_ai` |
| `myeventlane_vendor_ai` | Prompt built inline in `VendorAiAssistantForm::buildPrompt()` |
| `myeventlane_staff_playbooks_ai/config/install/myeventlane_staff_playbooks_ai.settings.yml` | AI summary settings |
| `/help/internal/staff-snippet-authoring` | Staff communication guide (Help Centre route) |
| `myeventlane_staff_playbooks` | Governance dashboard, playbook nodes, communication standards UI |

## Queues

| Queue | Purpose |
|-------|---------|
| myeventlane_escalations_sla.check | SLA checks (first_due, resolution_due) |
| myeventlane_escalations_ai.jobs | Triaging, reply suggestions, risk flags, breach advisories |
| myeventlane_staff_playbooks_ai.summary | Playbook AI summaries |

## Config / Install Defaults

Support modules with `config/install`:

- myeventlane_escalations_sla (settings + field config)
- myeventlane_escalations_ai (prompts, ai_options)
- myeventlane_escalations_capacity
- myeventlane_escalations_policy
- myeventlane_escalations_refunds
- myeventlane_escalations_portal (comment type, fields)
- myeventlane_help_centre (node type, fields, taxonomy)
- myeventlane_public_trust
- myeventlane_staff_playbooks (node type)
- myeventlane_staff_playbooks_ai

## Verified (Stabilisation Audit)

- **Config drift:** None (`config:status` clean)
- **Update hooks:** `updb` idempotent (no pending after second run)
- **Entity updates:** `entity:updates` not available; schema updates run via `updb`
- **Permissions:** Vendor and authenticated roles do NOT have staff permissions
- **Queue/cron:** Queues run without fatal errors
- **Known:** Cron may log `LogicException` from `ResponsiveImageBuilder` for some images (core module; content-level, not support-architecture)
