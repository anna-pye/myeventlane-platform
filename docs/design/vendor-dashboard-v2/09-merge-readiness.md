# Vendor Dashboard v2 Foundation — Merge Readiness Package

**Status:** Ready for human review → commit → PR → merge  
**Branch:** `feature/vendor-dashboard-v2-slice2`  
**Base:** `origin/main` @ `e450a5e96`  
**Date:** 2026-07-25  
**Scope:** Dashboard Slices 1 + 2 (foundation only)  
**Stop:** Before commit (this package does not create a commit)

---

## 1. What this package is

Production-merge preparation for Vendor Dashboard v2 Foundation:

| Slice | Intent |
| --- | --- |
| **1** | Action-Queue-first hierarchy, calm caught-up empty state, ≤4 KPI strip, demote Tools/Pro |
| **2** | Today’s focus panel, Daily Brief (factual only), lean KPIs, activity grouping, skeleton chrome, cache hardening |

**Not in scope:** Event Workspace, Events list redesign, AI, unread inventing, config/sync, new routes.

---

## 2. Files in the change set

### Runtime

| Path | Role |
| --- | --- |
| `web/themes/.../templates/dashboard/dashboard.html.twig` | Composition (Slices 1–2) |
| `web/themes/.../templates/includes/dashboard-skeleton.html.twig` | Skeleton include |
| `web/themes/.../src/scss/pages/_dashboard-live-ops.scss` | Dashboard chrome |
| `web/themes/.../src/scss/components/_empty-states.scss` | Reduced-motion skeletons |
| `web/themes/.../myeventlane_vendor_theme.theme` | Daily Brief + activity groups |
| `web/modules/.../VendorDashboardViewModelBuilder.php` | `doors_open_label` / timestamps |
| `web/modules/.../VendorDashboardController.php` | Cache contexts/tags/`max-age` |

### Design / merge docs (`docs/design/vendor-dashboard-v2/`)

`00`–`08` design + discovery + Slice 2 review  
`09` this merge readiness index  
`10` feature implementation record  
`11` implementation checklist  
`12` design review checklist (filled)  
`13` Definition of Done gates (filled)  
`14` PDS references (PR-ready)  
`15` PR draft body  

---

## 3. Merge-prep hardening applied (defect fixes only)

| Defect | Fix |
| --- | --- |
| “Today’s event” for distant upcoming | Panel only when live / doors open / opens within &lt;1 day; heading `Today's event` or `Next up` |
| Stale relative-time copy | `#cache][max-age] = 300` unless Pro welcome already set `0` |
| Hierarchy vs PDS 12 | Order: Hero → Daily Brief (identity-adjacent) → Queue → Today’s focus → **Upcoming** → **Business health** → Activity → secondary/Tools |
| Skeleton SR contradiction | Removed hidden loading text inside `aria-hidden` region |
| View events target | `min-height/width: 44px` on section link |

**No new features. No redesign. No unrelated refactor.**

---

## 4. PDS / DoD status

| Artifact | Location | Result |
| --- | --- | --- |
| Feature implementation record | [10](10-feature-implementation.md) | Complete |
| Implementation checklist | [11](11-implementation-checklist.md) | Complete |
| Design review checklist (16) | [12](12-design-review-checklist.md) | Complete — see residual NOs |
| Definition of Done (21) | [13](13-definition-of-done-gates.md) | Complete — open human sign-offs |
| PDS references | [14](14-pds-references.md) | PR-ready |
| Final review | [15](15-pr-draft.md) + this file | Complete |

**PDS template files missing from frozen pack** (governance debt, not invented here):

- `FEATURE_IMPLEMENTATION_TEMPLATE.md`
- `IMPLEMENTATION_CHECKLIST.md`
- `IMPLEMENTATION_WORKFLOW.md`
- `PDS_REFERENCE_TEMPLATE.md`
- `decisions/ADR-0002-implementation-follows-pds.md`

Filled equivalents for this PR live in this folder. ADR-0002 is cited as **binding intent** from README/CONTRIBUTING.

---

## 5. Accessibility verification (code review)

| Gate | Verdict | Evidence |
| --- | --- | --- |
| Severity not colour-only | PASS | Action card severity label + text |
| Live status not colour-only | PASS | “Live” text + `visually-hidden` prefix |
| Focus visible | PASS | KPI / activity / action-card focus styles |
| 44px targets (primary CTAs) | PASS | Create, Workspace, Door Mode, section link |
| Keyboard | PASS | Native links/buttons |
| Reduced motion | PASS | `_empty-states.scss` disables skeleton pulse |
| Skeleton honesty | PASS (SSR) | Hidden; no fake metrics; no false loading announcement |
| Forms | N/A | No new forms |

**Residual:** Progressive skeleton reveal still needs JS contract (documented; not required for SSR merge).

---

## 6. Cache verification

| Metadata | Value | Notes |
| --- | --- | --- |
| Contexts | `user`, `user.roles`, `timezone` | Correct for personalised + local-day copy |
| Tags | `node_list`, `commerce_order_list`, `myeventlane_vendor:{id}`, `node:{nid}` for controller event IDs | Directionally correct |
| Max-age | `300` (or `0` for Pro welcome) | Required for doors/brief relative copy |

**Residual risk:** View model may resolve managed events independently of `$userEvents` tags — see [11](11-implementation-checklist.md).

---

## 7. Performance verification

| Check | Verdict |
| --- | --- |
| New DB queries in Slice 2 | None — doors label reuses existing timestamps; brief/group iterate existing activity |
| New libraries / JS | None |
| Heavy vanity widgets | None |
| Duplicate controller vs view-model event work | Pre-existing debt — not expanded; defer post-merge |

---

## 8. Validation already run (prior session)

| Command | Result |
| --- | --- |
| `npm run mel:lint` | Pass |
| `npm run mel:build` | Pass |
| `ddev drush cr` | Success |
| `ddev drush status` | Bootstrap OK |
| `config/sync` | No changes |

**Before commit:** re-run lint/build/cr after merge-prep hardening.

---

## 9. Human gates still required

1. Design Authority review against [12](../vendor-studio/12-dashboard-philosophy.md)  
2. Technical Authority glance at cache `max-age` 300  
3. Author commit (when instructed)  
4. PR with label `design-system`  
5. Manual smoke at `/vendor/dashboard` (empty, caught-up, live-today, distant-upcoming)

---

## 10. Suggested next commands (when approved)

```bash
# Re-validate after hardening
npm run mel:lint
npm run mel:build
ddev drush cr

# Then (only when you ask to commit)
git add <paths>
git commit   # use message from 15-pr-draft.md
git push -u origin HEAD
gh pr create  # body from 15-pr-draft.md
```

**STOP — await commit approval.**
