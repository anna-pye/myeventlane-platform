# MEL governance adoption — priority map (Step 2)

Priorities rank **orchestration complexity**, **operational sensitivity**, **user impact**, and **duplication / drift risk**. This is the suggested merge order for rationalisation PRs (each PR should remain reviewable).

| Rank | Surface | Rationale | Primary systems to enforce | Main drift to remove |
|------|---------|-----------|----------------------------|----------------------|
| 1 | **Vendor onboarding** | Touches Stripe Connect, payout readiness, publish gates; highest sensitivity | MELWorkflowSystem, MELExperienceSystem, MELStateSystem, MELOperationalPolicySystem | Duplicate onboarding panel vs workflow primary; `VendorActionQueueBuilder` readiness vs `mel_state` summaries |
| 2 | **Customer dashboard hub (`/my-account`)** | Entry point for retention; currently **layout gap** vs other account pages | Same four + MELComponentSystem for presentation | Missing workflow regions vs `page--account`; ad hoc dashboard vs governed checklist |
| 3 | **Checkout completion** | Payment trust; distraction minimisation already codified | MELStateSystem, MELExperienceSystem, MELOperationalPolicySystem | Theme-level trust CTAs vs workflow completion hierarchy (verify, don’t rewrite Commerce) |
| 4 | **RSVP / ticket confirmation** | Signals already in resolver (`route.rsvp_thankyou`, tickets) | Workflow continuation + experience | Any confirmation Twig that invents its own “next steps” list |
| 5 | **Analytics / reports (vendor)** | Read-heavy; lower real-time risk | MELDataPresentationSystem, MELComponentSystem | Duplicate metric/dashboard cards vs `mel_metric_card` / analytics frame contracts |
| 6 | **Support / escalation surfaces** | Staff/customer boundary | MELOperationalPolicySystem, MELObservabilitySystem (staff) | Help assistant actions vs workflow IDs (optional convergence later) |

## Suggested PR slicing

1. **Vendor onboarding dedupe** — Single visible primary next-step on dashboard + gateway; retire redundant panel **only** if parity proven for stage/flag explainability.
2. **Customer hub alignment** — Either add `myeventlane_account.dashboard` to `page__account` **or** render `mel_workflow_region_*` inside `myeventlane-my-account-dashboard.html.twig` with matching landmarks (prefer one approach repo-wide).
3. **Checkout / confirmation pass** — Read-only audit of theme templates for extra CTA bands; attach governed components before removing legacy markup.
4. **Analytics presentation** — Migrate cards to data presentation hooks incrementally per screen.
5. **Help** — Last: lowest duplication risk if help remains retrieval-isolated.

## Explicit non-goals

- New orchestration managers, workflow registries, or governance interpreters.
- Rewriting Commerce checkout plugins, RSVP core flows, or onboarding entity model.
