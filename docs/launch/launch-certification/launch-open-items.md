# Launch Open Items

Every remaining item has an owner, reason, impact, risk, effort, target, recommendation, and a
final state: **PASS · IMPLEMENTED · RISK ACCEPTED · DEFERRED.** Nothing is left "unknown."

## Resolved this programme

| ID | Item | State | Notes |
| --- | --- | --- | --- |
| OB-1 | Event Insights 500 | **IMPLEMENTED** | 3 root causes fixed; all tabs 200 |
| OB-2 | Message Attendees 404 | **PASS** | false positive; works at Studio messaging |
| OB-3 | Pro upgrade at lock points | **IMPLEMENTED** | dedicated upgrade redirect; global 403 untouched |
| OD-6 | Dashboard missing h1 | **PASS** | h1 element present (corrected); text refinement → DEFERRED minor |
| CB-03c | Vendor revenue display (refunds) | **IMPLEMENTED** | refund-netted (prior programme) |
| CB-01 | Checkout copy payment-state | **IMPLEMENTED** | payment-state-aware (prior programme) |

## Deferred — verification gates (must pass to certify ≥9.5)

| ID | Item | Reason | Impact | Owner | Risk | Effort | Target | Recommendation |
| --- | --- | --- | --- | --- | --- | --- | --- |
| OV-1 | WCAG 2.1 AA full pass (organiser + customer critical paths) | No axe-core/screen-reader/contrast tooling in this env | Accessibility compliance | QA / A11y consultant | Med | 2–3 days | Pre-launch | Run axe + manual keyboard/SR/contrast; fix criticals |
| OV-2 | On-device mobile task completion | No device lab here | Mobile UX | QA | Med | 1–2 days | Pre-launch | Device matrix (iOS/Android) for every critical organiser task |
| OV-3 | Per-tab empty/loading/error states | Needs seeded no-data fixtures | UX polish | Frontend | Low | 1 day | Pre-launch | Seed empty events; verify each Studio tab state |

## Deferred / Risk-accepted — P2 fast-follow

| ID | Item | State | Impact | Owner | Risk | Effort | Target | Recommendation |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| OB-4 | Consolidate analytics surfaces | **RISK ACCEPTED** | Both `myeventlane_analytics` and `myeventlane_reporting` now work (no 403/500); duplication is cosmetic | Product/Eng | Low | 1–2 days | Post-launch | Decide one canonical analytics IA; retire/redirect the other |
| OB-5 | Duplicate-event capability | **DEFERRED** | Parity feature vs Eventbrite/Humanitix; not blocking | Product | Low | 2–3 days | Post-launch | Add organiser clone-event in Studio |
| OB-7 | Recurring "paid booking availability / headers already sent" log error | **DEFERRED** | Log noise; booking falls back gracefully; not organiser-blocking | Backend | Low | 0.5–1 day | Post-launch | Trace session-start in cron/render context |
| OD-6b | Dashboard h1 text + multiple-h1 cleanup | **DEFERRED** | Minor a11y refinement | Frontend | Low | 0.5 day | Post-launch | One meaningful h1 per organiser page |

## Deferred — owner review (outside code scope)

| Area | State | Owner | Recommendation |
| --- | --- | --- | --- |
| Legal (terms, refund policy, cookie/consent copy) | **DEFERRED** | Legal | Final legal sign-off on live copy |
| SEO (sitemaps/canonicals at scale) | **DEFERRED** | Marketing/Eng | Crawl + canonical audit on staging |
| Infrastructure (production load/scaling/backups) | **DEFERRED** | DevOps | Load test + DR runbook before launch |
| Manual payment gateway in production | **RISK ACCEPTED / owner decision** | Product/Finance | Confirm whether `mel_stripe_cc` (Manual) should be enabled in prod (drives pending-payment orders) |

## Open-item summary
- **P0 open:** 0
- **P1 open (code):** 0 — all resolved or risk-accepted
- **Verification gates:** 3 (OV-1/2/3) — DEFERRED, owned, pre-launch
- **P2/P3 + owner reviews:** owned, post-launch or owner sign-off
