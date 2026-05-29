# MELWorkflowSystem (behavioural orchestration)

Drupal owns infrastructure, permissions, Commerce checkout, and onboarding entities. **MELWorkflowSystem** centralises **UX orchestration**: which guided regions render, how completion reassurance is framed, and how primary CTAs are sequenced—without replacing backend workflows.

## 1. Architecture map

| Piece | Responsibility |
| --- | --- |
| `MelWorkflowRegistry` | Canonical workflow contracts (`WorkflowDefinition`). |
| `MelWorkflowResolver` | Builds `MelWorkflowContext` (surface, route, signals) and resolves active workflows. |
| `MelWorkflowManager` | Composes render arrays, picks completion vs progressive paths, merges cache metadata. |
| `MelWorkflowCTAHelper` | Enforces **one primary CTA**; delegates vendor sequencing to `OnboardingManager` when flagged. |
| `MelWorkflowProgressHelper` | Optional governed checklist/progress render arrays (scoped hubs). |
| `MelWorkflowAccessibilityHelper` | Landmark / region semantics for workflow regions. |
| `SurfaceNegotiator` | Publishes `mel_workflow*` variables to pages and merges bubbleable cache metadata. |

## 2. Workflow registry map

All IDs are defined in `MelWorkflowRegistry::all()`:

| Domain | IDs |
| --- | --- |
| Customer | `account_created`, `first_login`, `first_rsvp`, `first_ticket_purchase`, `first_saved_event`, `calendar_connected`, `profile_completed` |
| Vendor | `vendor_account_created`, `vendor_profile_completed`, `stripe_connected`, `first_event_created`, `first_event_published`, `first_ticket_sold`, `attendees_managed`, `boost_offer_presented` |
| Checkout | `ticket_selected`, `attendee_info_complete`, `checkout_started`, `payment_complete`, `order_confirmed`, `calendar_prompt`, `social_share_prompt` |
| Support | `support_contact_started`, `refund_request_started`, `escalation_detected`, `issue_resolved` |

## 3. Workflow ownership map (surfaces)

| Surface | Behaviour tone |
| --- | --- |
| AuthSurface | Minimal, trust-first transitions (`account_created`, `first_login`). |
| CustomerShell | Friendly progression, retention prompts (`profile_completed`, `calendar_prompt`, …). |
| VendorShell | Operational onboarding + revenue sequencing (delegates next route to `OnboardingManager` when flagged). |
| Checkout (route-sensitive) | Distraction-minimal: **no** workflow banner inside active `commerce_checkout.*` routes; reassurance on order detail. |
| StaffSurface | Support workflows only when signals are injected (see §9). |

## 4. CTA sequencing map

| Rule | Implementation |
| --- | --- |
| One action per moment | `MelWorkflowCTAHelper` emits a single `mel_next_step_panel` or nothing. |
| Vendor operational flow | When an active vendor workflow sets `delegateVendorNextStepToOnboardingManager` and `vendor.guidance.needs_resume`, CTA URL/title come from `OnboardingManager::getNextActionForAuthenticatedVendor()` (reuse backend sequencing). |
| Registry fallback | Highest `ctaPriority` wins among workflows that expose `primaryCtaRouteName`. |
| Checkout | Progressive CTAs suppressed during `commerce_checkout.*`; completion panels allowed on order routes via completion detection. |

## 5. Completion governance map

| Trigger | Behaviour |
| --- | --- |
| `completionExactRoutes` | Immediate completion UX (e.g. order detail). |
| `completionSignals` | All listed signals must be true (e.g. RSVP thank-you route flag). |
| Render | `mel_success_panel` via `MelWorkflowManager::buildCompletionRegion()` — polite reassurance, no raw messages. |

## 6. Progression governance map

| Artifact | When |
| --- | --- |
| `mel_checklist_panel` | Authenticated customer viewing `myeventlane_account.dashboard`, listing **incomplete** customer workflows only. |
| Progress semantics | Checklist inherits onboarding component preprocess (`mel_checklist_panel`). |

## 7. Empty-state orchestration map

| Mechanism | Detail |
| --- | --- |
| Component contracts | Empty states remain `mel_empty_state` + optional `mel_next_step_panel` + `mel_cta_band` composed by features. |
| Hints variable | `mel_workflow.empty_state_hints` exposes non-sensitive guidance strings keyed by workflow ID for templates that choose to consume them. |
| Integration rule | Do **not** duplicate empty-state markup—compose via MEL components. |

## 8. Duplication cleanup report

| Finding | Action |
| --- | --- |
| Vendor dashboard onboarding panel | Not removed in this slice; vendor theme still renders legacy `onboarding_panel`. Follow-up: pipe dashboard hero CTAs through `MelWorkflowManager` once vendor templates adopt `mel_workflow` variables. |
| Customer onboarding controllers | Unchanged—business progression stays in `OnboardingManager` / subscribers. |
| Checkout panes | Untouched—workflow layer explicitly skips inline checkout banners. |

## 9. Accessibility report

| Topic | Mitigation |
| --- | --- |
| Landmarks | `MelWorkflowAccessibilityHelper::applyWorkflowRegionLandmark()` adds `role="region"` + `aria-label`. |
| Success panels | `mel_success_panel` uses heading IDs compatible with component preprocess (`aria-labelledby`). |
| Checkout | No supplemental noisy regions inside checkout routes—reduces confusing announcements. |
| WCAG target | AA patterns mirror `MelComponentAccessibilityHelper` conventions. |

## 10. Security & privacy notes

- Signals are **boolean/route derived** or vendor onboarding flags loaded only for the **current user** on VendorShell.
- No cross-account leakage: resolver never accepts arbitrary UIDs.
- `customer.flags.has_orders` runs only on narrowed routes/surfaces to avoid excessive queries (still respects Commerce order storage permissions via existing manager behaviour).
- Escalation/support flows remain inert until `hook_mel_workflow_signals_alter()` supplies privileged signals.

## 11. Extension API

Canonical hook contracts: `web/modules/custom/myeventlane_surface/myeventlane_surface.api.php`.

Drupal 11 `ModuleHandler::alter()` delivers at most **three** arguments to hook implementations (`$data`, `$context1`, `$context2`). Route and path are passed in a context bag on the second context argument:

`hook_mel_workflow_signals_alter(array &$signals, AccountInterface $account, array &$workflow_context)` where `$workflow_context` contains `route_name` and `path` keys (see `MelWorkflowResolver::buildContext()`).

## 12. File-by-file summary

| File | Purpose |
| --- | --- |
| `src/WorkflowDefinition.php` | Immutable workflow contract. |
| `src/MelWorkflowContext.php` | Request-scoped behavioural snapshot. |
| `src/MelWorkflowRegistry.php` | Central catalogue of workflow IDs & metadata. |
| `src/MelWorkflowResolver.php` | Signal assembly + activation/completion logic. |
| `src/MelWorkflowManager.php` | Orchestration entry + render composition. |
| `src/MelWorkflowCTAHelper.php` | Primary CTA builder. |
| `src/MelWorkflowProgressHelper.php` | Optional checklist builder. |
| `src/MelWorkflowAccessibilityHelper.php` | Region semantics helper. |
| `src/SurfaceNegotiator.php` | Exposes workflow attachments + cache merge. |
| `myeventlane_surface.services.yml` | Service wiring. |
| `myeventlane_surface.info.yml` | Declares dependency on `myeventlane_core` for onboarding signals. |
| `templates/page--account.html.twig` | Renders workflow regions in customer shell. |
| `src/scss/components/_mel-*.scss` | Token-only spacing for workflow regions. |

## Validation commands

```bash
find web/modules/custom/myeventlane_surface/src -name '*.php' -print0 | xargs -0 php -l
composer validate --no-check-publish
npm run mel:lint
npm run mel:build
ddev drush cr
```

## Residual risk

- Vendor invite-ready preview mirrors `OnboardingManager::isInviteReady()` **without** calling `refreshFlags()` to avoid write amplification; flags may be stale until another subsystem refreshes state.
- Customer checklist verbosity depends on how many customer workflows activate simultaneously—tighten filters per product feedback.
- Workflow regions render on all pages via `SurfaceNegotiator` but themed placement currently ships only on `page--account`; adopt additional shells incrementally.
