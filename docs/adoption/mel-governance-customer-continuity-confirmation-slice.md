# MEL governance slice: customer continuity + confirmation consolidation

Date: 2026-05-07  
Scope: consume existing MELWorkflowSystem, MELExperienceSystem, MELStateSystem, MELDataPresentationSystem; no new orchestration layer.

---

## 1. Customer continuity audit (pre-change baseline)

### Checkout / orders

- **Order detail** (`myeventlane_checkout_flow` + `myeventlane-order-detail.html.twig`): Raw Commerce order state labels in UI; local CTA stack (back, PDF, per-event `.ics` via `ics_download`, refunds).
- **My Tickets** cards: Duplicate “View details + Download Calendar” per event row; status from `buildOrderData()['state']` (Commerce label).

### RSVP

- **Thank-you controller**: Donation edge cases updated entity + mail; **status strings were computed but never passed to Twig** (dead `$message` variable).
- **Templates**: Module + theme copies diverged; hard-coded CTA order (View event → Add to calendar → Cancel); calendar link used a path `/calendar/download/{id}` inconsistent with canonical route `myeventlane_rsvp.ics_download`.
- **MELWorkflowSystem**: `first_rsvp` completion on `myeventlane_rsvp.thankyou` produced a **second** `mel_success_panel` (“You’re up to date”) on top of the page hero → duplicate completion UX.

### Customer hub

- Prior slice registered dashboard/past-events with account shell workflow regions ([mel-governance-customer-vendor-continuity-slice.md](./mel-governance-customer-vendor-continuity-slice.md)).

### Duplicates identified

| Area | Duplication |
|------|-------------|
| RSVP thank-you | Workflow completion panel + local confirmation hero; local CTA ordering |
| Order / tickets | Commerce state labels vs product vocabulary (“Confirmed”, etc.) |
| Calendar | Template path vs routed ICS download |

---

## 2. Customer continuity ownership map

| Concern | Owner |
|---------|--------|
| Next-action / CTA sequence (RSVP thank-you) | `MelCustomerContinuityPresenter` + `MelWorkflowRegistry` / `MelExperienceRegistry` (documented sequencing notes) |
| Readiness / confirmation copy | `MelReadinessHelper` (MELStateSystem vocabulary) |
| Duplicate workflow completion on RSVP page | `MelWorkflowManager` (suppress `first_rsvp` completion panel when `route.rsvp_thankyou`) |
| Order state wording | `MelReadinessHelper::customerCommerceOrderStateLabel()` via preprocess |
| Presentation structure | `MelDataPresentationSystem` preprocess hooks on customer order templates |

---

## 3. Checkout completion consolidation

- **Orders / tickets**: Governed **status presentation** only (no checkout flow rewrite). Templates use `state_customer_presentation` when preprocess runs.

---

## 4. RSVP + ticket consolidation

- **RSVP**: `RsvpThankYouController` builds `mel_continuity` from `MelCustomerContinuityPresenter`; Twig renders `continuity_actions` and donation bands from the same payload.
- **Tickets**: Order detail + My Tickets use governed status label; ticket line items unchanged (Commerce authority preserved).

---

## 5. Calendar continuation

- RSVP thank-you **Add to calendar** uses `Url::fromRoute('myeventlane_rsvp.ics_download', ['node' => …])` — aligned with My Tickets / order detail `ics_url` generation.

---

## 6. Customer CTA alignment

- **RSVP**: Primary CTA when optional donation checkout is pending remains **inside the donation band**; remaining actions follow presenter order: view event → calendar → optional contribution → cancel.
- **Workflow**: Generic completion success panel suppressed on RSVP thank-you so shell workflow region does not repeat confirmation.

---

## 7. Readiness vocabulary consolidation

- New `MelReadinessHelper` methods for RSVP headlines, donation copy, continuity CTAs, and `customerCommerceOrderStateLabel()` for order states.

---

## 8. Accessibility

- Confirmation card `role="region"` + labelled heading; donation-pending status line uses `aria-live="polite"`; calendar link retains `download` where applicable; destructive cancel remains last in the action list.

---

## 9. Security / privacy

- No new data exposed; attendee email still only on user’s own thank-you context; no observability/trace leakage.

---

## 10. File-by-file implementation summary

| Path | Change |
|------|--------|
| `web/modules/custom/myeventlane_core/src/MelReadinessHelper.php` | Customer RSVP + order-state vocabulary |
| `web/modules/custom/myeventlane_surface/src/MelCustomerContinuityPresenter.php` | **New** — RSVP thank-you continuity payload |
| `web/modules/custom/myeventlane_surface/src/MelWorkflowManager.php` | Suppress duplicate `first_rsvp` completion on thank-you route; align `willRenderPrimaryWorkflowRegion()` |
| `web/modules/custom/myeventlane_surface/myeventlane_surface.services.yml` | Register `customer_continuity_presenter` |
| `web/modules/custom/myeventlane_surface/myeventlane_surface.module` | Preprocess `myeventlane_order_detail`, `myeventlane_my_tickets` for governed order status |
| `web/modules/custom/myeventlane_rsvp/src/Controller/RsvpThankYouController.php` | Inject presenter; pass `mel_continuity`; remove dead `$message` |
| `web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.info.yml` | Depend on `myeventlane_surface` |
| `web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.services.yml` | `thank_you_controller` receives `customer_continuity_presenter` |
| `web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.theme` | Theme variable `mel_continuity` |
| `web/modules/custom/myeventlane_rsvp/templates/mel-rsvp-thankyou.html.twig` | Governed markup (mirrors theme) |
| `web/themes/custom/myeventlane_theme/templates/rsvp/mel-rsvp-thankyou.html.twig` | Governed RSVP thank-you |
| `web/modules/custom/myeventlane_checkout_flow/templates/myeventlane-order-detail.html.twig` | Status uses `state_customer_presentation` |
| `web/modules/custom/myeventlane_checkout_flow/templates/myeventlane-my-tickets.html.twig` | Same |
| `web/themes/custom/myeventlane_theme/templates/myeventlane-my-tickets.html.twig` | Same |
| `web/modules/custom/myeventlane_surface/tests/src/Unit/MelReadinessHelperCustomerTest.php` | **New** unit test |

---

## Validation (this slice)

Commands run locally:

- `php -l` on touched PHP files
- `composer validate`
- `cd web && ../vendor/bin/phpunit -c core/phpunit.xml.dist modules/custom/myeventlane_surface/tests/src/Unit/MelReadinessHelperCustomerTest.php`
- `npm run mel:lint` and `npm run mel:build`
- `ddev drush cr` (when DDEV available)

---

## Residual risk

- `MelCustomerContinuityPresenter` builds URLs with `Url::fromRoute` (requires valid route and access at runtime).
- Theme + module RSVP templates are duplicated (same markup); future edits should keep both in sync or consolidate via a single default template.
- Governed order-state mapping may need expansion for additional Commerce order states used only on edge bundles.

---

## Manual smoke tests

**Customer:** RSVP thank-you (free, donation pending, donation complete); order detail + My Tickets status labels; calendar download; single workflow primary region (no duplicate “You’re up to date” on RSVP).

**Vendor / staff:** unchanged boundaries; no customer PII in new strings beyond existing thank-you fields.
