# Vendor Studio — Product Success Metrics

**Version:** RC1  
**Status:** Design authority (documentation only)

## Purpose

Define how we know Vendor Studio is succeeding for organisers, operations, and the business.

This is **not** an analytics implementation spec. It is the product measurement philosophy every future feature should reference.

## Scope

Organiser success, operational success, and business success metrics; how features declare impact. Instrumentation details belong in delivery tickets, not here.

## Audience

Product Owner, design, engineering leads, anyone writing phase briefs or PRs.

## Related documents

- [01-vendor-studio-vision.md](01-vendor-studio-vision.md) — behavioural intent
- [12-dashboard-philosophy.md](12-dashboard-philosophy.md)
- [13-event-workspace-philosophy.md](13-event-workspace-philosophy.md)
- [16-design-review-checklist.md](16-design-review-checklist.md) — DOC4
- [17-design-maturity-model.md](17-design-maturity-model.md)

---

## Why metrics before features

Features without a success metric become aesthetic churn. Every Vendor Studio initiative should name **which metrics it improves** and **which it must not harm** (especially money trust and accessibility).

---

## Organiser success

Measures of time-to-value and confidence.

| Metric | Intent |
| --- | --- |
| **Time to first published event** | New organisers reach publish without staff rescue |
| **Time to connect Stripe** | Payout path is findable and honest |
| **Time to create first ticket** | Ticket setup is progressive and clear |
| **Time to first booking** | Public + Studio path yields a real order when demand exists |

**Signals:** funnel timings, qualitative onboarding sessions, support “where do I…?” deflection on these jobs.

---

## Operational success

Measures of daily operability.

| Metric | Intent |
| --- | --- |
| **Dashboard clarity** | Top Action Queue item identified ≤5 seconds (Golden Rule) |
| **Workspace completion** | Readiness blockers understood; sections finished without dual-nav confusion |
| **Mobile usability** | Door Mode and today’s attention succeed on phone |
| **Accessibility** | Primary tasks completable by keyboard / AT; AA maintained |

**Signals:** task success tests, Door Mode field feedback, a11y audits, checklist compliance rate.

---

## Business success

Measures of healthy platform growth (product outcomes, not vanity charts).

| Metric | Intent |
| --- | --- |
| **Organiser retention** | Organisers return to run another event |
| **Repeat event creation** | Studio is a habit, not a one-off struggle |
| **Feature adoption** | Payments hub, Messages, Marketing used when relevant — not ignored due to findability |
| **Support reduction** | Fewer tickets about navigation, publish confusion, payout location |

**Signals:** retention cohorts, event creation frequency, feature use with honest denominators, support taxonomy tags.

---

## Feature impact statement (required pattern)

For each material feature or phase:

```text
Improves: [metric names from this doc]
Must not harm: [e.g. money state honesty, Door Mode speed, AA]
Maturity: from Level X → Level Y ([17](17-design-maturity-model.md))
OS refs: [documents cited]
```

---

## Anti-vanity rules

- Do not invent Dashboard charts solely to “have metrics”
- Do not celebrate adoption of a confusing feature
- Money metrics must reconcile with Commerce/Stripe truth
- Empty states are allowed; fake success is not  

See [19-anti-patterns.md](19-anti-patterns.md).

---

## Design implications

- Vision ([01](01-vendor-studio-vision.md)) no longer owns metric definitions — it points here  
- PRs name impacted metrics ([16](16-design-review-checklist.md) DOC4)  
- Roadmap phases ([10](10-roadmap.md)) should declare metric targets at planning time  

## Future considerations

- Baseline measurement once instrumentation exists  
- North-star selection is a Product Owner decision; this pack provides the menu  
- Predictive metrics belong with Level 4–5 ([17](17-design-maturity-model.md), [20](20-vendor-studio-v2-vision.md))  

## Related references

- [01](01-vendor-studio-vision.md) · [12](12-dashboard-philosophy.md) · [13](13-event-workspace-philosophy.md) · [17](17-design-maturity-model.md) · [10](10-roadmap.md)
