# MEL Design Debt

**Status:** Active register  
**Date:** 2026-07-24  
**Type:** Documentation only  
**Rule:** Genuine issues only — no speculative polish lists

Priorities: **Critical** · **High** · **Medium** · **Low**

---

## Critical

| ID | Issue | Evidence | Product impact |
| --- | --- | --- | --- |
| D-C01 | Permission drift on check-in / ticket invite-into paths (C-01 / C-02) still partially deferred | Convergence roadmap; implementation plan | Organiser 403 / trust break |
| D-C02 | Door Mode + Attendees manual a11y/QA not signed off in sprint docs | Sprint 4 QA checklist open | Live-ops failure at the door |

---

## High

| ID | Issue | Evidence | Product impact |
| --- | --- | --- | --- |
| D-H01 | Analytics Pro depth / Audience segments / Boost merge still partial after VX2-08 hub | Hub + redirects shipped; P3 depth open | Support may still ask for “full reports” |
| D-H02 | Parallel interaction shells (modal/drawer/loading owners) | `mel-interaction-authority-audit.md` | Inconsistent focus/confirm behaviour |
| D-H03 | Global Orders hub not shipped (event-scoped only) | Roadmap C-17; shell Orders disabled without context | Cross-event money findability |
| D-H04 | Instrumentation pipelines deferred (hubs + attendees + tickets hooks) | VX2-04/05/06/07 notes | Cannot prove success metrics |
| D-H05 | Residual Settings payment / stored-status jargon strings | VX2-07 remaining roadmap | Trust leak near money |
| D-H06 | Parallel refund route ownership still under review | VX2-07 remaining | Refund dead-ends / duplication |

---

## Medium

| ID | Issue | Evidence | Product impact |
| --- | --- | --- | --- |
| D-M01 | Studio module CSS widths outside vendor layout tokens | VX2-02A backlog | Ultra-wide / hierarchy drift |
| D-M02 | Card header treatments vary (live-ops vs `.mel-card`) | VX2-02A backlog | Visual inconsistency |
| D-M03 | Home vs Overview naming drift in Convergence docs | Event Home redesign follow-ups | Doc/IA confusion |
| D-M04 | Schedule / Venue still share information form | Sprint 2 deferred | Feels unfinished / form-admin |
| D-M05 | Messages audience filters + schedule edit/cancel/retry incomplete | VX2-06 remaining | Partial Messages product |
| D-M06 | Activity timeline missing messages / system changes | Event Home redesign | Home feels thin over time |
| D-M07 | Empty-state governance gaps on lower-traffic lists | Wave B report scope limits | Uneven empties |
| D-M08 | Competing `aria-live` channels | Interaction audit | SR noise / missed updates |
| D-M09 | Onboarding celebration / Stripe “get paid” polish incomplete vs epic VX2-01 | Roadmap | Activation friction |
| D-M10 | Marketing / Messages layout scan scores 8.4 (below 8.5 target) | VX2-02A scores | Hierarchy polish |

---

## Low

| ID | Issue | Evidence | Product impact |
| --- | --- | --- | --- |
| D-L01 | Help `/help/vendors` → organisers rename | Language Guide checklist | Vocabulary purity |
| D-L02 | Wizard multi-step chrome: workspace frame vs form fields | VX2-02A backlog | Minor layout feel |
| D-L03 | Soften residual admin messaging jargon outside organiser UI | VX2-06 | Staff/organiser bleed |
| D-L04 | Optional embed live payout history in Payments hub | VX2-07 | Convenience |
| D-L05 | Retire Pro MessageAttendeesForm stub after traffic dies | VX2-06 | Dead code/UX shadow |
| D-L06 | Visual QA screenshot set desktop / tablet / 390px | Event Home follow-ups | Regression detection |
| D-L07 | Singular legacy URL hit monitoring | Success metrics | Cleanup verification |

---

## Explicitly not debt

- `/vendor/*` URL namespace (machine) while UI says Organiser — **policy**, not debt.  
- Commerce in code/config — **required**, must stay hidden in UI.  
- Staff Control Centre technical language — **out of organiser scope**.  
- Public hero lock (single featured-style) — **intentional constraint**.  
- Pro feature gating with value story — **intentional**, not incomplete UX, when upgrade path is clear.

---

## Suggested burn-down order

1. D-C01 · D-C02  
2. D-H01 · D-H03 · D-H06 · D-H05  
3. D-H02 · D-H04  
4. D-M01–M06  
5. Low items opportunistically with related epics  

---

## Counts

| Priority | Count |
| --- | --- |
| Critical | 2 |
| High | 6 |
| Medium | 10 |
| Low | 7 |
| **Total** | **25** |
