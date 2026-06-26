# Launch Certification — Sign-off

**Product:** MyEventLane (MEL) · **Date:** 2026-06-26 · **Environment:** DDEV live (Drupal 11 /
Commerce 3) · **Programme:** Final Remediation & Certification.

## Certification statement

Based on four sequential evidence-based programmes (Customer Acceptance, Customer Verification,
Organiser Acceptance, and this Remediation & Certification), MyEventLane is certified
**launch-ready with conditions**. All verified P0 and P1 issues are resolved or explicitly
risk-accepted with named owners. Remaining work is bounded to owned verification gates and
post-launch enhancements.

## Backlog disposition (every item accounted for)

| State | Count | Items |
| --- | :-: | --- |
| IMPLEMENTED | 5 | OB-1, OB-3, CB-01, CB-03c, (prior TASK A/B) |
| PASS | 3 | OB-2, OD-6, payment/refund/webhook integrity (prior) |
| RISK ACCEPTED | 2 | OB-4 (analytics duplication), Manual-gateway-in-prod (owner) |
| DEFERRED (owned) | 6 | OV-1, OV-2, OV-3, OB-5, OB-7, legal/SEO/infra reviews |
| UNKNOWN | 0 | — |

## Scores at certification

| Metric | Score |
| --- | :-: |
| Overall launch readiness | **8.5 / 10** |
| Organiser experience | 8.8 |
| Customer experience | 8.4 |
| Commerce / Payments | 8.7 / 9.0 |
| Security | 8.8 |
| Performance | 8.5 |
| Accessibility (primitives; full AA gated) | 7.5* |
| Mobile (responsive; device gated) | 7.3* |
| Projected post-gates | ≈ 9.3–9.5 |

## Code changes certified (this programme)
- `myeventlane_reporting.routing.yml` — access notation fix (11 routes).
- `EventInsightsController.php` — attendee-source via `instanceof` (2 sites + import).
- `config/sync/user.role.mel_pro.yml` — insights/export permissions for Pro.
- `myeventlane_pro.services.yml` + new `ProUpgradeRedirectSubscriber.php` — Pro upgrade redirect.
- Drupal 11 / Commerce 3 safe · config-aware · no architecture change · no duplicated logic.
- Validated: composer valid · config in sync · tests OK · phpcs clean on new code · no regressions.

## Final recommendation

> **GO WITH CONDITIONS** — ship on completion of WCAG AA + on-device mobile verification gates and
> owner sign-off (legal, infrastructure, Manual-gateway decision). See `launch-go-no-go.md`.

## Sign-off matrix (to be completed by owners at release)

| Role | Name | Date | Sign |
| --- | --- | --- | --- |
| Lead Product Owner | | | ☐ |
| Drupal 11 Architect | | | ☐ |
| Commerce 3 Architect | | | ☐ |
| QA Lead | | | ☐ |
| Accessibility Consultant (OV-1) | | | ☐ |
| Security Engineer | | | ☐ |
| DevOps / Infrastructure | | | ☐ |
| Legal | | | ☐ |
| Launch Readiness Manager | | | ☐ |

> Discipline note: this certification reflects repository + live-runtime evidence captured in the
> DDEV environment. Items requiring tooling/environments not present here (axe-core, device lab,
> production infrastructure, legal review) are marked DEFERRED with owners — not asserted as passed.
