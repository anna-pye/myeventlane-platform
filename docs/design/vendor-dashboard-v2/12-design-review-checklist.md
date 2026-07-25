# Design Review Checklist — Vendor Dashboard v2 Foundation

Filled against [16-design-review-checklist.md](../vendor-studio/16-design-review-checklist.md).  
**PR:** Vendor Dashboard v2 Foundation (Slices 1 + 2)

## Information Architecture

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| IA1 | Fits the single global shell model | YES | No Studio/Manager fork |
| IA2 | Labels use organiser jobs | YES | Needs attention, Business health, Door Mode, Open Workspace |
| IA3 | Event-scoped tools stay in Workspace | YES | Dashboard links into Workspace; no new global peers |
| IA4 | Create event remains chrome primary | YES | Hero primary CTA |

## Accessibility

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| A11Y1 | WCAG AA contrast intent | YES | Lean text on light surfaces; no new low-contrast chips |
| A11Y2 | Visible focus | YES | KPI/activity/action-card focus styles |
| A11Y3 | Keyboard path for primary task | YES | Native links |
| A11Y4 | Severity not colour-only | YES | Severity labels + Live text |
| A11Y5 | `prefers-reduced-motion` | YES | Skeleton/spinner |
| A11Y6 | Form labels associated | N/A | No new forms |

## Mobile

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| M1 | Usable ~390px | YES | Single-column stack |
| M2 | One primary job in first viewport | YES | Queue after identity |
| M3 | Touch targets ≥44×44px | YES | Primary CTAs + section link |
| M4 | No hover-only essential actions | YES | |
| M5 | Table pattern | N/A | No tables added |

## Navigation

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| N1 | Where am I clear | YES | H1 + shell |
| N2 | No duplicate nav systems | YES | |
| N3 | Active state not colour-only | N/A | Shell unchanged |

## Hierarchy

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| H1 | Three Question Framework | YES | |
| H2 | Golden Rule ≤5s | YES | Queue first after identity |
| H3 | Attention outranks vanity | YES | Tools/Pro demoted |

## Typography

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| T1 | One H1 | YES | Organiser name / welcome |
| T2 | Body ≥16px mobile | YES | Base tokens |
| T3 | Sentence case UI labels | YES | |

## Spacing

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| S1 | Spacing scale | YES | Token spacing |
| S2 | Layout intent class | YES | `mel-layout--dashboard` |

## Components

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| C1 | Extends existing before inventing | YES | |
| C2 | Cards earn size | YES | Lean KPIs; flat upcoming |
| C3 | One primary button per region | YES | |

## Motion

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| MO1 | Motion explains state | YES | Skeleton only; reduced-motion off |
| MO2 | Dialogs ≤400ms | N/A | No new dialogs |

## Performance

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| P1 | No heavy uncached vanity queries | YES | No new queries; max-age 300 |
| P2 | Heavy JS only where needed | YES | No new JS |

## Loading

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| L1 | Skeleton no fake metrics | YES | |
| L2 | Busy/disabled submit | N/A | No submits |

## Errors

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| E1 | Errors explain recovery | N/A | No new error UI; queue items keep existing CTAs |
| E2 | No dead ends | YES | Caught-up has Create / View events |

## Success

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| SU1 | Success includes next step | YES | Caught-up actions; Pro welcome link |

## Help

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| HE1 | Help organiser-audience | YES | Existing support paths unchanged |
| HE2 | Staff-only no leak | YES | No staff content added |

## Drupal

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| D1 | Logic not in Twig | YES | Brief/group in preprocess; doors in builder |
| D2 | Cache considered | YES | Contexts/tags/max-age |
| D3 | Mapping per 09 | YES | Extends existing dashboard mapping |

## Commerce

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| CO1 | States not invented | YES | Existing completed orders / RSVPs |
| CO2 | Refunds/payouts confirmation | N/A | Not touched |
| CO3 | Risk called out | YES | Cache/KPI only; no checkout/payment mutation |

## Security

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| SE1 | Access server-side | YES | Unchanged routes; Door Mode URL access-checked |
| SE2 | No secrets/PII dumps | YES | |

## Copywriting

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| CW1 | Follows 15 | YES | |
| CW2 | No CMS jargon | YES | |
| CW3 | Australian English | YES | |

## Documentation

| # | Check | YES / NO | Rationale |
| --- | --- | --- | --- |
| DOC1 | PR cites OS docs | YES | [14](14-pds-references.md) |
| DOC2 | DDR if foundational change | YES | No DDR required |
| DOC3 | Anti-patterns checked | YES | [19](../vendor-studio/19-anti-patterns.md) |
| DOC4 | Success metrics named | YES | Anxiety ↓ / time-to-know (18) |

## Sign-off

| Role | Name | Date |
| --- | --- | --- |
| Author | Agent (merge prep) | 2026-07-25 |
| Design Authority | _open_ | |
| Technical Authority | _open_ | |
