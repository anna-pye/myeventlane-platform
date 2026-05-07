# MEL governance rationalisation — consolidated reports (Steps 3–10, 17)

This file satisfies **deliverables 3–10** from the rationalisation brief as **planning / status reports**. Implementation PRs should reference the global audit and priority map.

**Prerequisite reading**

- [mel-governance-rationalisation-global-audit.md](mel-governance-rationalisation-global-audit.md)
- [mel-governance-adoption-priority-map.md](mel-governance-adoption-priority-map.md)

---

## 3. Legacy orchestration removal report (Step 7 — planned)

**Status:** No removals executed in this phase entry; audit identifies candidates.

| Candidate | Condition before delete |
|-----------|-------------------------|
| `onboarding_panel` build in `VendorDashboardController` | **Partial:** omitted when `willRenderPrimaryWorkflowRegion()` is true (surface optional). Badge / `next_onboarding_route` retained for shell. |
| Duplicate readiness helpers in `VendorActionQueueBuilder` | `mel_state` summaries cover vendor dashboard KPI narrative |
| Static customer onboarding CTAs | Customer workflows + completion routes cover same journeys |
| Event Studio client readiness merge | Server-side `EventStudioGovernanceComponentBuilder` payload complete for all card states |

---

## 4. Vendor onboarding consolidation report (Step 3)

**Goal:** One server-authoritative continuation story on vendor shell routes.

**Implemented (dashboard route)**

- `VendorDashboardController` skips rendering `myeventlane_vendor_onboarding_panel` when `MelWorkflowManager::willRenderPrimaryWorkflowRegion()` is TRUE, so **`mel_workflow_region_primary`** (delegated onboarding URL via `MelWorkflowCTAHelper`) is not duplicated with the same body CTA.

**Remaining (follow-up PRs)**

- **`CreateEventGatewayController`** / **`VendorSettingsForm`**: still embed `myeventlane_vendor_onboarding_panel`; align with governance stack or the same preview guard when UX allows.
- Optional: collapse panel to explain-only fragments (stage/flags) if product wants detail without repeating links.
- `MelOperationalPolicyManager` remains the marketing suppression gate on dashboard (prior slice).

---

## 5. Customer dashboard consolidation report (Step 4)

**Critical gap:** `/my-account` does not use `page--account`, so **`mel_workflow_region_primary` / progress are not rendered** on the hub while they are on `/my-events`, tickets, orders.

**Recommended fix direction**

- Unify layout so hub exposes the same governed regions **once**, without duplicate sidebars (either extend `page__account` to dashboard **or** inject workflow regions into the dashboard Twig).

**Secondary**

- Align “My Events” data lists with MELDataPresentationSystem when touching those templates (optional second PR).

---

## 6. Checkout / RSVP consolidation report (Steps 5–6)

- **Checkout:** Keep suppression in `MelWorkflowCTAHelper` / state deferral; completion remains on order-detail workflows per registry.
- **RSVP / ticket:** Confirm thank-you / confirmation templates consume `mel_workflow` variables if adding continuation UI; avoid parallel CTA bands.

---

## 7. Interaction consolidation report (Step 10)

**Current:** `myeventlane_surface` registers modal, toast, disclosure, processing themes + `MelInteractionManager` context.

**Future work:** Inventory contrib/theme one-off modals; migrate to `mel_modal` / interaction preprocess gradually. No second interaction registry.

---

## 8. JS authority reduction report (Step 8)

**Finding:** Event Studio JS still merges readiness from several sources — highest-risk non-governance-native behaviour.

**Direction:** Prefer server-rendered governance JSON + DOM hydration for display order only; JS sorts/filters **without** introducing new business rules.

---

## 9. Security / privacy validation report (Step 12)

- Vendor isolation: unchanged; dashboard controller already scoped by vendor context.
- Observability: staff permission + surface gate (see observability architecture).
- Help assistant: context sanitised before model — keep escalation paths out of governance traces.

---

## 10. File-by-file implementation summary

| File | Change |
|------|--------|
| `docs/adoption/mel-governance-rationalisation-global-audit.md` | Step 1 audit — checklist bridges + dashboard dedupe note |
| `docs/adoption/mel-governance-adoption-priority-map.md` | Step 2 (unchanged in this slice) |
| `docs/adoption/mel-governance-rationalisation-phase-reports.md` | Deliverables 3–10 + Step 3 status |
| `web/modules/custom/myeventlane_surface/src/MelWorkflowManager.php` | `willRenderPrimaryWorkflowRegion()` |
| `web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php` | Dedupe `onboarding_panel` vs governed primary |
| `web/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml` | Optional `workflow_manager` argument |
| `web/modules/custom/myeventlane_surface/tests/src/Kernel/MelExperienceContinuityKernelTest.php` | Parity test for preview vs attachments primary |

---

## Validation (Step 15)

Run after subsequent implementation PRs:

- `php -l` on touched PHP
- `composer validate`
- Targeted PHPUnit / governance snapshots (extend only where gaps exist)
- `npm run mel:lint` and `npm run mel:build` when themes/JS change
- `ddev drush cr` when services or libraries change

## Manual smoke (Step 16)

Use priority map order: vendor onboarding → customer hub → checkout completion → RSVP confirmation → staff diagnostics visibility.
