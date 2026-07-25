# Vendor Studio — Design Review Checklist

**Version:** RC1.1  
**Status:** Design authority (documentation only)

## Purpose

Mandatory **detailed** checklist for every Vendor Studio pull request that changes organiser-facing experience or this Design Operating System.

## Scope

YES / NO verification with rationale. Gate list ownership: [21-definition-of-done.md](21-definition-of-done.md). This file owns the expandable checklist. Not a substitute for code review, security review, or Commerce risk review.

## Audience

Authors and reviewers of Vendor Studio PRs (product, design, theme, module).

## Related documents

- [21-definition-of-done.md](21-definition-of-done.md) — mandatory gates (complete both)
- [ADR-0001](decisions/ADR-0001-design-authority.md) — precedence
- [README.md](README.md) — governance
- [01](01-vendor-studio-vision.md) · [02](02-information-architecture.md) · [11](11-design-tokens.md) · [15](15-copywriting-guide.md) · [18](18-product-success-metrics.md) · [19](19-anti-patterns.md)
- [09-drupal-mapping.md](09-drupal-mapping.md)

### How to use

1. Satisfy applicable gates in [21-definition-of-done.md](21-definition-of-done.md).  
2. Copy this checklist into the PR. Mark **YES** or **NO**. Every **NO** requires a rationale and, if shipping anyway, Product Owner + Design Authority acknowledgement. **N/A** allowed only with rationale (e.g. docs-only PR).  
3. Cite OS documents touched or followed.

---

## Zone Gate (Workspace PRs — first)

**Authority:** [Workspace Zones](../vendor-studio-visual/07-workspace-zones.md) · PO decision 2026-07-25  

Every new **Event Workspace** PR must begin with a zone map **before any screenshots**. If the author cannot produce the map, the page has not been designed yet.

```text
Zone map
Identity
Guidance
Work
Outcome
```

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| ZG1 | Zone map present at top of PR (before screenshots) | | |
| ZG2 | Identity answers “Where am I?” (Hero / chrome only — no work) | | |
| ZG3 | Guidance answers “What should I do next?” with **at most one** recommendation (or explicit omission) | | |
| ZG4 | Work answers “How do I do it?” as the dominant section purpose | | |
| ZG5 | Outcome answers “What happened?” only when appropriate (success / error / empty / aftercare) | | |
| ZG6 | No component competition across zones (e.g. no second Publish in Work) | | |
| ZG7 | Page does not read as equal Card · Card · Card · Card | | |

N/A only for PRs with **zero** Event Workspace UI impact (rationale required).

---

## Information Architecture

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| IA1 | Fits the single global shell model (no Studio/Manager fork) | | |
| IA2 | Labels use organiser jobs, not Drupal concepts | | |
| IA3 | Event-scoped tools live in Event Workspace, not new global peers without DDR | | |
| IA4 | Create event remains chrome primary, not a competing confusing destination | | |

## Accessibility

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| A11Y1 | WCAG AA contrast for text and essential UI | | |
| A11Y2 | Visible focus on all interactive elements | | |
| A11Y3 | Keyboard path for primary task | | |
| A11Y4 | Severity not colour-only | | |
| A11Y5 | `prefers-reduced-motion` honoured | | |
| A11Y6 | Form labels and errors programmatically associated | | |

## Mobile

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| M1 | Usable at ~390px baseline | | |
| M2 | One primary job preserved in first viewport | | |
| M3 | Touch targets ≥44×44px | | |
| M4 | No hover-only essential actions | | |
| M5 | Table pattern chosen (card rows or x-scroll) and consistent | | |

## Navigation

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| N1 | “Where am I?” is clear | | |
| N2 | No duplicate nav systems on one surface | | |
| N3 | Active state not colour-only | | |

## Hierarchy

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| H1 | Passes Three Question Framework | | |
| H2 | Passes Golden Rule (next step ≤5 seconds) | | |
| H3 | Attention outranks vanity content | | |

## Typography

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| T1 | One H1 | | |
| T2 | Body ≥16px on mobile | | |
| T3 | Sentence case UI labels | | |

## Spacing

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| S1 | Uses spacing scale (4px base) — no ad-hoc systems | | |
| S2 | Layout intent class used; no hardcoded content max-width in Twig | | |

## Components

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| C1 | Extends existing MEL/vendor components before inventing new | | |
| C2 | Cards earn their size; no card-in-card nesting | | |
| C3 | One primary button per region | | |

## Motion

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| MO1 | Motion explains state; not decorative chrome loops | | |
| MO2 | Dialogs ≤400ms enter; no layout thrash required for meaning | | |

## Performance

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| P1 | No heavy uncached queries introduced for vanity widgets | | |
| P2 | Heavy JS attached only on routes that need it | | |

## Loading

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| L1 | Skeleton/loading does not show fake metrics | | |
| L2 | Busy/disabled submit prevents double-submit where needed | | |

## Errors

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| E1 | Errors explain recovery | | |
| E2 | No dead ends | | |

## Success

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| SU1 | Success states include next step | | |

## Help

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| HE1 | Help is organiser-audience only | | |
| HE2 | Staff-only content does not leak | | |

## Drupal

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| D1 | Business logic not in Twig | | |
| D2 | Cache contexts/tags considered for personalised/attention data | | |
| D3 | Mapping consistent with [09](09-drupal-mapping.md) | | |

## Commerce

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| CO1 | Order/payment/ticket states not invented in UI | | |
| CO2 | Refunds/payouts use deliberate confirmation patterns | | |
| CO3 | Risk called out if touching checkout, payments, capacity, ownership | | |

## Security

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| SE1 | Access enforced server-side (UI hide ≠ security) | | |
| SE2 | No secrets, dumps, or PII leakage in UI/logs from this change | | |

## Copywriting

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| CW1 | Follows [15](15-copywriting-guide.md) | | |
| CW2 | No CMS/Commerce jargon in organiser UI | | |
| CW3 | Australian English | | |

## Documentation

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| DOC1 | PR cites relevant Design OS documents | | |
| DOC2 | DDR added/updated if foundational decision changed | | |
| DOC3 | Anti-patterns checked ([19](19-anti-patterns.md)) | | |
| DOC4 | Success metrics impacted named ([18](18-product-success-metrics.md)) | | |

---

## Sign-off

| Role | Name | Date |
| --- | --- | --- |
| Author | | |
| Design Authority (if required) | | |
| Technical Authority (if Drupal/Commerce risk) | | |

---

## Design implications

PRs that skip this checklist are incomplete for Vendor Studio surfaces.

## Future considerations

- Automate citation hints in PR templates after v1.0 freeze  
- Add phase-specific annexes (e.g. Payments) without forking this core list  

## Related references

- [README.md](README.md) · [19](19-anti-patterns.md) · [18](18-product-success-metrics.md) · [09](09-drupal-mapping.md)
