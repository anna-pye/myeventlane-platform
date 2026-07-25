# Vendor Studio Design Operating System — Roadmap

**Version:** RC1  
**Status:** Design authority (documentation only)

## Purpose

Sequence how this Design Operating System is **applied** in implementation phases — without absorbing v2 vision scope.

## Scope

Phased rollout, dependencies, Drupal impact, risk, effort. **Not** a backlog of parked ideas ([20](20-vendor-studio-v2-vision.md), [A03](appendices/A03-future-ideas-parking-lot.md)). Metric targets should reference [18](18-product-success-metrics.md). Maturity targets: [17](17-design-maturity-model.md).

## Audience

Product Owner, Design Authority, Technical Authority, phase leads.

## Related documents

- [01-vendor-studio-vision.md](01-vendor-studio-vision.md)
- [09-drupal-mapping.md](09-drupal-mapping.md)
- [17-design-maturity-model.md](17-design-maturity-model.md)
- [18-product-success-metrics.md](18-product-success-metrics.md)
- [20-vendor-studio-v2-vision.md](20-vendor-studio-v2-vision.md)
- [`docs/vendor-experience-convergence-roadmap.md`](../../vendor-experience-convergence-roadmap.md)
- [README.md](README.md) — governance / versioning

---

## Why phased application

Shipping everything at once recreates drift. One phase at a time keeps diffs reviewable and protects Commerce/access surfaces.

**Phase exit bar:** lift touched surfaces to at least **maturity Level 2** ([17](17-design-maturity-model.md)) unless the brief states otherwise.

```text
Phase 1 Design system
    → 2 Dashboard → 3 Events → 4 Workspace
    → 5 Orders → 6 Attendees → 7 Messages → 8 Payments
    → 9 Analytics → 10 Marketing → 11 Settings → 12 Dark mode
```

---

## Phase 1 — Design system

| | |
| --- | --- |
| **Objectives** | Adopt this OS as authority; align tokens, layout intents, component contracts; remove competing max-width patterns from new work |
| **Dependencies** | VX2-02A layout tokens; brand tokens docs; this documentation pack (RC1 → v1.0 freeze) |
| **Drupal impact** | Theme token/SCSS hygiene only when coding begins; no Commerce changes |
| **Risk** | Low — documentation and token alignment; medium if partial restyles fork components |
| **Metrics** | Consistency; checklist citation rate ([18](18-product-success-metrics.md)) |
| **Estimated effort** | M |

---

## Phase 2 — Dashboard

| | |
| --- | --- |
| **Objectives** | Action-queue-led Dashboard pattern; identity + today + upcoming composition |
| **Dependencies** | Phase 1; existing `VendorActionQueueBuilder` / dashboard view models; [12](12-dashboard-philosophy.md) |
| **Drupal impact** | Primarily `myeventlane_vendor_theme` Twig/SCSS; possible preprocess; prefer theme-only if payloads exist |
| **Risk** | Medium — easy to restyle without fixing priority; must not invent new payment states |
| **Metrics** | Dashboard clarity ([18](18-product-success-metrics.md)) |
| **Estimated effort** | M |

---

## Phase 3 — Events

| | |
| --- | --- |
| **Objectives** | Events list clarity; create gateway; obvious entry to Workspace |
| **Dependencies** | Phase 1; Events routes `/vendor/events` |
| **Drupal impact** | Events list templates, nav labels, empty states |
| **Risk** | Low–medium — create/draft flows already sensitive; do not alter publish rules casually |
| **Metrics** | Time to first published event (path clarity) |
| **Estimated effort** | M |

---

## Phase 4 — Workspace

| | |
| --- | --- |
| **Objectives** | Event Workspace shell + Overview pattern consistency; section nav clarity |
| **Dependencies** | Phases 1–3; VX2 One Event Workspace runtime; [13](13-event-workspace-philosophy.md) |
| **Drupal impact** | Workspace Twig/SCSS/JS libraries; section headers; readiness presentation |
| **Risk** | High — autosave, draft lifecycle, publish readiness; regress carefully |
| **Metrics** | Workspace completion; time to first ticket |
| **Estimated effort** | XL |

---

## Phase 5 — Orders

| | |
| --- | --- |
| **Objectives** | Global + event order list/detail patterns; deliberate refund entry UX |
| **Dependencies** | Phases 1, 4 (event-scoped); Commerce order access rules understood |
| **Drupal impact** | Order views/templates; vendor order view SCSS; **no** payment logic changes without review |
| **Risk** | High — PII, ownership, refunds |
| **Metrics** | Support reduction on order findability; money trust |
| **Estimated effort** | L |

---

## Phase 6 — Attendees

| | |
| --- | --- |
| **Objectives** | Attendee workspace + Door Mode mobile excellence |
| **Dependencies** | Phase 4; VX2 Attendee Workspace; [08](08-mobile-guidelines.md) |
| **Drupal impact** | Attendee templates, check-in UI, filters; access isolation |
| **Risk** | High — door stress, offline edge cases, personal data |
| **Metrics** | Mobile usability; Door Mode reliability |
| **Estimated effort** | L |

---

## Phase 7 — Messages

| | |
| --- | --- |
| **Objectives** | Hub for brand/templates/history + clear send path; event-scoped messaging alignment |
| **Dependencies** | Phase 1; messaging modules/routes |
| **Drupal impact** | Messaging forms/templates; ensure brand settings ≠ send confusion |
| **Risk** | Medium — accidental sends; audience selection clarity |
| **Metrics** | Feature adoption (send path); support reduction |
| **Estimated effort** | L |

---

## Phase 8 — Payments

| | |
| --- | --- |
| **Objectives** | Payments hub pattern unifying Stripe, payouts, refunds, tax entry points |
| **Dependencies** | Phase 1; Stripe Connect safety rules |
| **Drupal impact** | Hub Twig/nav; deep links to existing Stripe/payout UIs; **no** gateway secret or state invention |
| **Risk** | Critical — money, Connect onboarding, payout readiness honesty |
| **Metrics** | Time to connect Stripe; money trust |
| **Estimated effort** | L |

---

## Phase 9 — Analytics

| | |
| --- | --- |
| **Objectives** | Analytics hub pattern: KPIs + charts + text alternatives + empty honesty |
| **Dependencies** | Phase 1; analytics services/data availability |
| **Drupal impact** | Analytics templates, `chartjs` attachment scope, exports |
| **Risk** | Medium — misleading charts; performance of heavy queries |
| **Metrics** | Operational clarity without decorative metrics ([19](19-anti-patterns.md)) |
| **Estimated effort** | L |

---

## Phase 10 — Marketing

| | |
| --- | --- |
| **Objectives** | Marketing/Boost hub at wide layout intent; clear spend/state before purchase |
| **Dependencies** | Phase 1; Boost product rules |
| **Drupal impact** | Marketing templates/SCSS; Boost CTAs; do not weaken entitlements |
| **Risk** | Medium — commercial actions; placement complexity |
| **Metrics** | Feature adoption (when relevant); must not harm publish/check-in |
| **Estimated effort** | L |

---

## Phase 11 — Settings

| | |
| --- | --- |
| **Objectives** | Settings form pattern consistency; sectioned calm forms; payout-impact copy |
| **Dependencies** | Phase 1; organiser settings forms; [15](15-copywriting-guide.md) |
| **Drupal impact** | Settings Twig/SCSS/form sectioning; schema only if config changes are intentional |
| **Risk** | Medium — team access, business identity fields |
| **Metrics** | Support reduction; settings completion without staff rescue |
| **Estimated effort** | M |

---

## Phase 12 — Dark mode

| | |
| --- | --- |
| **Objectives** | Token-level dark theme under `.mel-vendor`; QA across tables, severity, money |
| **Dependencies** | Phases 1–11 substantially complete on light theme; [11](11-design-tokens.md) dark strategy |
| **Drupal impact** | Token maps / SCSS; no structural PHP required |
| **Risk** | Medium–high — contrast regressions; partial adoption worse than none |
| **Metrics** | Accessibility maintained under dark remap |
| **Estimated effort** | L |

---

## Cross-phase rules

1. **One phase ships at a time** for reviewable diffs.
2. **Documentation-only phases** do not modify Twig/SCSS/PHP/JS/YAML.
3. **Commerce / access / payments** changes require explicit risk callouts and human approval.
4. Prefer convergence with VX2 epics over parallel redesigns.
5. Validate theme work with `npm run mel:lint` / `npm run mel:build` and Drupal cache rebuild when code lands.
6. Cite Design OS docs + complete [16](16-design-review-checklist.md) on every PR.
7. Do not absorb [20](20-vendor-studio-v2-vision.md) ideas without Product Owner promotion.

---

## Design implications

- Roadmap reordering is explicit (CHANGELOG + Product Owner) — never silent in a feature PR
- v1.0 freeze of this OS precedes or accompanies Phase 1 coding authority

## Future considerations

- Support polish can track with Phase 11 or a small companion slice; it is not a blocker for Dashboard/Workspace
- Level 4–5 programmes are separate initiatives after core Level 2–3 excellence
- After v1.0 freeze: tag, cite in every implementation PR ([README](README.md))

## Related references

- [09](09-drupal-mapping.md) · [12](12-dashboard-philosophy.md) · [13](13-event-workspace-philosophy.md) · [17](17-design-maturity-model.md) · [18](18-product-success-metrics.md) · [20](20-vendor-studio-v2-vision.md) · [A03](appendices/A03-future-ideas-parking-lot.md)
