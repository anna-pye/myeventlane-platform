# MEL stabilisation slice — template parity, empty states, vocabulary, interaction ownership

**Date:** 2026-05-07  
**Scope:** Governance-safe parity tooling, `mel_empty_state` convergence for high-traffic operational empties, operational vocabulary via `MelReadinessHelper`, thin theme delegates, targeted tests. No new orchestration or component framework.

---

## 1. Template parity audit (paired templates)

| Pair | Canonical owner | Override owner | Parity mode | Notes |
|------|-----------------|----------------|-------------|-------|
| Order detail | `myeventlane_checkout_flow` | `myeventlane_theme` | `theme_delegates_include` | Theme file includes module template only. |
| My Tickets | `myeventlane_checkout_flow` | `myeventlane_theme` | `theme_delegates_include` | Previously full duplicate; now thin delegate. |
| Vendor attendees dashboard | `myeventlane_checkout_flow` | `myeventlane_theme` | `theme_delegates_include` | Same. |
| RSVP thank-you | `myeventlane_rsvp` | `myeventlane_theme` | `theme_delegates_include` | Previously near-duplicate; now thin delegate. |

**Intentional divergence:** Register in `mel-template-parity.json` (`intentional_divergence: true`) or switch pair `mode` to `parallel_markup` if a theme must fork markup after product review.

---

## 2. Template parity protection (tooling + CI)

| Artifact | Purpose |
|----------|---------|
| `mel-template-parity.json` | Registry of pairs, canonical paths, include targets, ownership notes. |
| `scripts/governance/template-parity-audit.php` | Validates `theme_delegates_include` overrides; exits non-zero on drift. |
| `composer.json` → `governance:audit` | Runs architecture + surface + **template parity** audits in CI. |

Legitimate Drupal overrides remain supported: a theme may replace an override with full markup by updating the registry (explicit divergence) or restoring `parallel_markup` mode.

---

## 3. Empty-state convergence report

Operational empties migrated to **`#theme` => `mel_empty_state`** (via `GovernedOperationalTemplates` + preprocess):

| Surface | Location | Mechanism |
|---------|----------|-----------|
| Customer | My Tickets (no orders) | `myeventlane_surface_preprocess_myeventlane_my_tickets` → `mel_my_tickets_empty` |
| Customer | My Categories (no follows) | `myeventlane_surface_preprocess_myeventlane_my_categories` → `mel_category_follow_empty` |
| Customer | My Account dashboard (tickets / RSVPs / past preview) | `myeventlane_surface_preprocess_myeventlane_my_account_dashboard` |
| Customer | My Account past events page | `myeventlane_surface_preprocess_myeventlane_my_account_past_events` |
| Vendor | Attendees & Sales dashboard (no events) | `myeventlane_surface_preprocess_myeventlane_vendor_attendees_dashboard` → `mel_vendor_attendees_dashboard_empty` |
| Vendor | Analytics event list empty | `myeventlane_surface_preprocess_myeventlane_analytics_dashboard` → `mel_analytics_events_empty` |

**Module dependency:** `myeventlane_checkout_flow` now lists `myeventlane_surface` so `mel_empty_state` is always available where checkout templates render it.

**Wave 2 (not in this slice):** vendor boost/payout empties, support history / escalation / onboarding-help empties — follow the same pattern (`GovernedOperationalTemplates` + registry slots in `MelReadinessHelper`).

---

## 4. Operational vocabulary alignment

| Concern | Owner |
|---------|--------|
| Customer empty-state headings and explanatory slots | `MelReadinessHelper` (`MelReadinessHelper.php`) — new `customer*` slot methods. |
| Vendor “no events” / dashboard action copy | Existing `vendorActionNoEventsStrings()`; **Vendor analytics** `buildEmptyState()` now reuses it via injected `MelReadinessHelper`. |
| Composed render arrays | `GovernedOperationalTemplates` (`GovernedOperationalTemplates.php`) — service id `myeventlane_surface.governed_operational_templates`. |

Per-template operational sentences for the migrated screens were removed from Twig in favour of these contracts.

---

## 5. Interaction ownership map (high level)

| Concern | Canonical owner | Allowed variants | Notes |
|---------|-----------------|------------------|------|
| `mel-modal` and MEL interaction theme hooks | `myeventlane_surface` (`MelInteractionSystem` / interaction preprocess) | Severity variants from registry | Do not fork modal DOM in feature modules. |
| Native `<dialog>` / browser confirm | Core + minimal feature JS | Theme may style; avoid duplicate modal stacks for the same user decision | Prefer one pattern per flow; migration only when product requires. |
| Mobile nav / shell drawers | `mel_shell_*` / `mel_mobile_shell_nav` components | Vendor shell may extend CSS | Server-side access unchanged. |
| Loading / skeleton / `aria-live` | `mel_loading_state`, interaction accessibility helpers | `mel_empty_state` for empty, not duplicate spinners | Async copy should stay non-sensitive. |

---

## 6. Accessibility parity

- **`mel_empty_state`** uses a configurable `heading_element` (`h2` / `h3`) so in-account sections avoid duplicate `h1`/`h2` landmarks under existing section titles.
- **Order detail / My Tickets** order-state labels still use `myeventlane_surface_preprocess_myeventlane_order_detail` / `myeventlane_my_tickets` for governed Commerce state wording.
- **RSVP thank-you** markup remains in the module template; theme delegates preserve override path without forking ARIA structure.

---

## 7. Performance and cacheability

- Empty states are **render arrays** nested in page builds; cache contexts/tags remain on parent controllers.
- No additional per-request queries; preprocess only inspects variables already on the theme hook.
- **Vendor analytics** view model still sets `empty_state` in PHP; Twig renders a single nested `mel_empty_state` instead of duplicating wrapper markup.

---

## 8. Observability and safety

- No new JSON or debug payloads attached to public routes.
- `GovernedOperationalTemplates` only composes public-safe copy already defined in `MelReadinessHelper` / vendor action strings.

---

## 9. Stale legacy cleanup

- Removed **duplicate** theme Twig bodies for My Tickets, vendor attendees dashboard, and RSVP thank-you in favour of **include-only** overrides (canonical markup in modules).
- Removed ad-hoc empty-state card markup from the migrated templates listed in section 3.

**Not removed:** Fallback legacy `events` branch in vendor attendees template (still required when `mel_cards` is cold).

---

## 10. File-by-file implementation summary

| Path | Change |
|------|--------|
| `mel-template-parity.json` | New registry. |
| `scripts/governance/template-parity-audit.php` | New audit script. |
| `composer.json` | `governance:audit` runs template parity. |
| `web/modules/custom/myeventlane_core/src/GovernedOperationalTemplates.php` | New — builds `mel_empty_state` render arrays. |
| `web/modules/custom/myeventlane_core/src/MelReadinessHelper.php` | Customer empty + browse CTA vocabulary. |
| `web/modules/custom/myeventlane_surface/myeventlane_surface.services.yml` | Registers `governed_operational_templates`. |
| `web/modules/custom/myeventlane_surface/myeventlane_surface.module` | Preprocess: my tickets, categories, analytics, vendor attendees, account dashboard, past events. |
| `web/modules/custom/myeventlane_checkout_flow/myeventlane_checkout_flow.info.yml` | Depends on `myeventlane_surface`. |
| `web/modules/custom/myeventlane_checkout_flow/myeventlane_checkout_flow.module` | Theme variables for empty render children. |
| `web/modules/custom/myeventlane_checkout_flow/templates/myeventlane-my-tickets.html.twig` | Renders `mel_my_tickets_empty`. |
| `web/modules/custom/myeventlane_checkout_flow/templates/myeventlane-vendor-attendees-dashboard.html.twig` | Renders `mel_vendor_attendees_dashboard_empty`. |
| `web/themes/custom/myeventlane_theme/templates/myeventlane-my-tickets.html.twig` | Include-only delegate. |
| `web/themes/custom/myeventlane_theme/templates/myeventlane-vendor-attendees-dashboard.html.twig` | Include-only delegate. |
| `web/themes/custom/myeventlane_theme/templates/rsvp/mel-rsvp-thankyou.html.twig` | Include-only delegate. |
| `web/modules/custom/myeventlane_rsvp/templates/mel-rsvp-thankyou.html.twig` | Docblock only. |
| `web/modules/custom/myeventlane_core/myeventlane_core.module` | Theme variable `mel_category_follow_empty`. |
| `web/modules/custom/myeventlane_core/templates/myeventlane-my-categories.html.twig` | Uses governed empty. |
| `web/modules/custom/myeventlane_account/myeventlane_account.module` | Theme variables for governed empties. |
| `web/modules/custom/myeventlane_account/templates/myeventlane-my-account-dashboard.html.twig` | Uses governed empties. |
| `web/modules/custom/myeventlane_account/templates/myeventlane-my-account-past-events.html.twig` | Uses governed empty. |
| `web/modules/custom/myeventlane_analytics/myeventlane_analytics.info.yml` | Depends on `myeventlane_surface`. |
| `web/modules/custom/myeventlane_analytics/myeventlane_analytics.module` | Theme variable `mel_analytics_events_empty`. |
| `web/modules/custom/myeventlane_analytics/myeventlane_analytics.services.yml` | Injects `MelReadinessHelper` into view model builder. |
| `web/modules/custom/myeventlane_analytics/src/Service/VendorAnalyticsViewModelBuilder.php` | Empty-state copy from readiness helper. |
| `web/modules/custom/myeventlane_analytics/templates/analytics-dashboard.html.twig` | Renders `mel_analytics_events_empty`. |
| `web/modules/custom/myeventlane_surface/tests/src/Unit/MelReadinessHelperCustomerTest.php` | Slot vocabulary test. |
| `web/modules/custom/myeventlane_surface/tests/modules/mel_kernel_route_fixtures/*` | Kernel-only stub route `commerce_checkout.review` for PathMatcher / Url in governance tests. |
| `web/modules/custom/myeventlane_surface/tests/src/Kernel/MelSurfaceGovernanceKernelTestBase.php` | Enables `mel_kernel_route_fixtures`. |

---

## 11. Validation commands (executed)

| Command | Result |
|---------|--------|
| `php -l` (changed PHP) | OK |
| `composer validate` | OK |
| `composer run-script governance:audit` | OK (includes template parity) |
| `../vendor/bin/phpunit -c core/phpunit.xml.dist modules/custom/myeventlane_surface/tests/src/Unit/MelReadinessHelperCustomerTest.php` | OK |
| `composer run-script governance:test` | **OK** (16 tests) — `mel_kernel_route_fixtures` test module registers `commerce_checkout.review` for kernel `Url::fromRouteMatch` + PathMatcher. |
| `npm run mel:lint` | OK |
| `npm run mel:build` | OK |
| `ddev drush cr` | Run locally if `ddev` is available (not required for static verification). |

---

## 12. Manual smoke checklist

**Customer:** My Tickets empty, My Categories empty, My Account dashboard empty sections, Past events empty — copy consistent, single primary CTA where applicable, headings not skipping levels incorrectly.

**Vendor:** Attendees dashboard with zero events, Analytics with zero events — copy matches vendor “no events” vocabulary; CTA still respects route access.

**Staff / public:** No change to observability payloads; confirm analytics page still respects Pro lock overlay.

---

## 13. Residual risk

1. **Category / account pages** require `myeventlane_surface` enabled (standard for MEL); there is no non-surface fallback Twig for category empty (acceptable product assumption).  
2. **Guest analytics model** copy still localised in `VendorAnalyticsViewModelBuilder::emptyGuestModel()` — could align to helper in a follow-up.  
3. **`mel_kernel_route_fixtures`** only exists for PHPUnit; production Commerce still owns the real `commerce_checkout.review` route when checkout is enabled.
