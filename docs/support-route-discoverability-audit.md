# Support Route Discoverability & Menu Integration Audit

**Branch:** release/support-architecture-v1.0  
**Date:** 2026-02-12  
**Audit:** 2026-02-12 (logic and route verification)

## Audit Findings (Post-Implementation)

- **Route references:** All route names in links.menu.yml, Twig, and PHP match routing.yml definitions. Verified: `myeventlane_escalations_sla.dashboard`, `myeventlane_escalations_capacity.dashboard`, `myeventlane_escalations_refunds.vendor_refund_summary`, `myeventlane_escalations_analytics.vendor_dashboard`, `myeventlane_help_centre.public_index`, `myeventlane_public_trust.page`.
- **Parent menu:** `entity.escalation.collection` is defined in myeventlane_escalations.links.menu.yml (parent: system.admin_config). SLA and other staff links correctly use it as parent.
- **AdminCaseConsoleBuilder:** Constructor and services.yml argument order match. Added `moduleHandler->moduleExists('myeventlane_escalations_capacity')` guard to avoid RouteNotFoundException when capacity module is disabled.
- **Vendor sidebar routes:** All mgmt_items routes exist (support, refunds, analytics, audience, donations, payouts, vendor_bas).
- **Footer menu:** system.menu.footer config creates menu id "footer". Links use menu_name: footer. Update 10002 creates menu for existing installations.

## STEP 1 — Route Audit

| Route | Permission | Module | Has Menu Link? | Has Contextual Link? | Status |
|-------|------------|--------|----------------|----------------------|--------|
| myeventlane_escalations_sla.dashboard | manage escalations sla | myeventlane_escalations_sla | ✅ Yes (added) | N/A | FIXED |
| myeventlane_escalations_ai.generate_draft | generate escalation ai drafts | myeventlane_escalations_ai | N/A (escalation-scoped) | ✅ In AI panel (generate_url) | OK |
| myeventlane_escalations_ai_draft.generate | generate escalation ai drafts | myeventlane_escalations_ai_draft | N/A (AJAX) | ✅ In mel_ai_draft_root (Draft button) | OK |
| myeventlane_escalations_analytics.staff_dashboard | view escalation analytics | myeventlane_escalations_analytics | ✅ Yes | N/A | OK |
| myeventlane_escalations_analytics.staff_export | export escalation analytics | myeventlane_escalations_analytics | No (secondary action from dashboard) | N/A | OK |
| myeventlane_escalations_analytics.vendor_dashboard | view vendor escalations | myeventlane_escalations_analytics | ✅ Yes (vendor sidebar) | N/A | FIXED |
| myeventlane_escalations_refunds.vendor_refund_summary | view vendor refund summary | myeventlane_escalations_refunds | ✅ Yes (vendor sidebar) | N/A | FIXED |
| myeventlane_escalations_capacity.dashboard | view escalation capacity dashboard | myeventlane_escalations_capacity | ✅ Yes | ✅ Escalation sidebar link | OK |
| myeventlane_escalations_policy.report | view escalation policy report | myeventlane_escalations_policy | ✅ Yes | N/A | OK |
| myeventlane_escalations_portal.customer_support | view own escalation | myeventlane_escalations_portal | ✅ account menu | N/A | OK |
| myeventlane_escalations_portal.customer_add | create escalation | myeventlane_escalations_portal | Via /my/support page | N/A | OK |
| myeventlane_escalations_portal.vendor_list | view vendor escalations | myeventlane_escalations_portal | ✅ vendor sidebar | N/A | OK |
| myeventlane_help_centre.public_index | _access TRUE | myeventlane_help_centre | ✅ footer menu | N/A | FIXED |
| myeventlane_help_centre.vendor_index | view vendor help centre | myeventlane_help_centre | ✅ vendor sidebar + account | N/A | OK |
| myeventlane_help_centre_ai.ask | _access TRUE | myeventlane_help_centre_ai | ✅ Linked from /help (ask_url) | N/A | OK |
| myeventlane_staff_playbooks.governance_dashboard | administer escalations | myeventlane_staff_playbooks | ✅ Yes | N/A | OK |
| myeventlane_staff_playbooks_ai.summary | generate playbook ai summaries | myeventlane_staff_playbooks_ai | N/A (node-scoped action) | N/A | OK |
| myeventlane_public_trust.page | _access TRUE | myeventlane_public_trust | ✅ footer menu (added) | N/A | FIXED |
| entity.escalation.collection | access administration pages | myeventlane_escalations | ✅ system.admin_config | N/A | OK |

**Note:** myeventlane_vendor_nudges has no routes — it provides Twig-rendered nudge blocks only.

---

## STEP 2 — Staff Routes (Admin Toolbar)

**Parent:** entity.escalation.collection (Escalations, under Configuration)

**Children (weight order):**
- Escalation Dashboard (SLA) — weight 0 ✅ ADDED
- Escalation Analytics — weight 5
- Escalation Capacity — weight 10
- Escalation Policy Report — weight 15
- Add playbook — weight 20
- Internal Governance — weight 25

All require appropriate staff permissions. No duplicate parents.

---

## STEP 3 — Escalation Admin Page Links

On `entity.escalation.canonical`:
- **AI Draft (queue):** ✅ In myeventlane_ai_insights panel (generate_url)
- **Instant AI Draft:** ✅ In mel_ai_draft_root (Draft response button)
- **Refund context:** ✅ In sidebar when order linked (fragment #refund-context-panel)
- **Vendor analytics:** No staff route for per-escalation vendor analytics (vendor dashboard is vendor-facing)
- **Capacity dashboard:** ✅ ADDED contextual link in sidebar for staff with permission

---

## STEP 4 — Vendor Routes (Sidebar)

**Vendor sidebar (custom Twig) now includes:**
- Support ✅
- Refund Summary ✅ ADDED
- Support Analytics ✅ ADDED
- Audience, Donations, Payouts, BAS Report
- Help Centre ✅ (Settings & Help section)

**Note:** Vendor sidebar is custom-rendered in `sidebar.html.twig` — not Drupal menu. Added items to existing `mgmt_items` array. active_section mappings added in theme and VendorThemePagePreprocess.

---

## STEP 5 — Customer Routes

- `/my/support` → Account menu (Support) ✅
- `/my/support/escalations/add` → Linked from /my/support page ✅

---

## STEP 6 — Public Routes

- `/help` → Footer menu (Help) ✅ ADDED
- `/help/ask` → Linked from /help index (ask_url preprocess) ✅
- `/trust` → Footer menu (Trust & transparency) ✅ ADDED

Footer menu created via `config/install/system.menu.footer.yml` and `hook_update_10002` for existing sites. Site admins must place the "Footer" menu block in `footer_support_legal` region for links to appear.

---

## STEP 7 — Governance Dashboard

`/admin/myeventlane/governance` visible under Escalations parent ✅  
Permission: administer escalations (staff-only). Not visible to vendor or public.

---

## STEP 8 — Fail Safely

**Vendor sidebar:** Custom Twig-rendered — no Drupal menu. Added to existing structure per task instructions.

**Admin parent:** Single parent `entity.escalation.collection`; no conflicts.

**Duplicate links:** None. Separate menus (account vs footer) for customer vs public routes.

---

## YAML Files Created or Modified

| File | Action |
|------|--------|
| myeventlane_escalations_sla/myeventlane_escalations_sla.links.menu.yml | CREATED |
| myeventlane_help_centre/myeventlane_help_centre.links.menu.yml | MODIFIED (added public_help) |
| myeventlane_help_centre/config/install/system.menu.footer.yml | CREATED |
| myeventlane_help_centre/myeventlane_help_centre.install | MODIFIED (update 10002) |
| myeventlane_public_trust/myeventlane_public_trust.links.menu.yml | CREATED |
| myeventlane_escalations_portal/myeventlane_escalations_portal.services.yml | MODIFIED |
| myeventlane_escalations_portal/src/Service/AdminCaseConsoleBuilder.php | MODIFIED |
| myeventlane_vendor_theme/templates/includes/sidebar.html.twig | MODIFIED |
| myeventlane_vendor_theme/myeventlane_vendor_theme.theme | MODIFIED |
| myeventlane_vendor/src/Service/VendorThemePagePreprocess.php | MODIFIED |

---

## Permission Verification

- Staff routes (admin/*): require manage escalations sla, view escalation analytics, view escalation capacity dashboard, view escalation policy report, administer escalations
- Vendor routes (/vendor/*): require view vendor escalations, view vendor refund summary, view vendor help centre
- Customer routes (/my/support): require view own escalation, create escalation
- Public routes (/help, /trust): _access TRUE

No staff permissions exposed to vendor or customer.

---

## Final Navigation Map

### Public
- `/help` — Footer menu (when Footer block placed in footer_support_legal)
- `/help/ask` — Linked from /help page
- `/trust` — Footer menu

### Vendor
- Dashboard, Events, Analytics (primary)
- Management: Support, Refund Summary, Support Analytics, Audience, Donations, Payouts, BAS Report
- Growth: Boost
- Settings, Help centre

### Customer
- Account menu → Support → /my/support
- From /my/support → Submit escalation, Help Centre, Ask a question

### Staff
- Admin toolbar → Configuration → Escalations (parent)
  - Escalation Dashboard
  - Escalations (list)
  - Escalation Analytics
  - Escalation Capacity
  - Escalation Policy Report
  - Add playbook
  - Internal Governance
