# Vendor Dashboard v2 — Implementation Plan (Sprint 1A)

**Status:** Design package complete — **await approval before any code**  
**Authority:** [ADR-0002](../vendor-studio/decisions/ADR-0002-implementation-follows-pds.md) · [IMPLEMENTATION_WORKFLOW.md](../vendor-studio/IMPLEMENTATION_WORKFLOW.md) · [12-dashboard-philosophy.md](../vendor-studio/12-dashboard-philosophy.md)  
**This document does not authorise implementation.**

---

## 1. Goal (when approved)

Ship an Action-Queue-led organiser Dashboard on `/vendor/dashboard` that matches PDS hierarchy, reuses existing builders/theme homes, and passes Golden Rule (top action ≤5 seconds).

---

## 2. Pre-code gates (mandatory)

Complete before branching for code:

1. Product Owner accepts this design package (`01`–`06`).  
2. Resolve open product questions (below).  
3. Complete [IMPLEMENTATION_CHECKLIST.md](../vendor-studio/IMPLEMENTATION_CHECKLIST.md).  
4. Fill [FEATURE_IMPLEMENTATION_TEMPLATE.md](../vendor-studio/FEATURE_IMPLEMENTATION_TEMPLATE.md) for Dashboard.  
5. Gate B wireframe review signed ([02](02-dashboard-wireframes.md)).  
6. Branch from latest `origin/main` (e.g. `feature/mel-vendor-dashboard-v2`).  
7. Upcoming PR label: `design-system` + [PDS_REFERENCE_TEMPLATE.md](../vendor-studio/PDS_REFERENCE_TEMPLATE.md) citations.

---

## 3. Open product questions (block coding if unanswered)

| # | Question | Why |
| --- | --- | --- |
| 1 | Exact ≤4 KPI keys for v1? | Prevents dual strips and vanity metrics |
| 2 | Sprint 1A scope: hierarchy + queue + KPIs only, or also Activity + Celebrations? | Controls diff size |
| 3 | Mobile sticky Create event: yes/no? | [12](../vendor-studio/12-dashboard-philosophy.md) / [08](../vendor-studio/08-mobile-guidelines.md) |
| 4 | Empty-state / caught-up copy owner? | Design vs Product |
| 5 | Does every Action Queue item already expose `reason`? If not, allow thin builder change? | Action Card contract |
| 6 | Config export expected, or theme/preprocess-only? | Deployment |

Do not guess Commerce fields, Stripe states, or access rules.

---

## 4. Suggested implementation slices (sequential)

| Slice | Outcome | Likely touch |
| --- | --- | --- |
| A | Reorder first paint to PDS hierarchy; demote Pro/Tools/duplicate metrics | `dashboard.html.twig`, `_dashboard-live-ops.scss` |
| B | Empty Action Queue → calm caught-up landmark | Twig + empty-state SCSS; optional view model copy keys |
| C | Action Cards anatomy (severity + title + reason + CTA) | Twig; `VendorActionQueueBuilder` only if reason missing |
| D | Single ≤4 Metric Card strip (reuse `vendor-kpi-strip` / KPI SCSS) | Twig + product KPI allow-list in builder/controller |
| E | Loading skeleton + `aria-busy` honesty | Twig/SCSS; no fake revenue |
| F | Mobile stack verification (390px) | SCSS responsive; sticky Create only if approved |
| G | Cache tags/contexts hardening | Controller / view model |
| H | Docs: update [09](../vendor-studio/09-drupal-mapping.md) implemented-mapping note if paths corrected | Docs only |

**Out of Sprint 1A unless explicitly pulled in:** Event Workspace redesign, Payments hub, Marketing hub, dark mode, AI panel, customisable widgets, nav IA rewrite beyond Dashboard composition.

---

## 5. Files likely to change

### High probability

- `web/themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig`  
- `web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_dashboard-live-ops.scss`  
- `web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_dashboard.scss` (convergence only)  
- `web/themes/custom/myeventlane_vendor_theme/src/scss/components/_kpi-cards.scss`  
- `web/themes/custom/myeventlane_vendor_theme/src/scss/components/_empty-states.scss`  
- `web/themes/custom/myeventlane_vendor_theme/templates/components/vendor-kpi-strip.html.twig` (wire-in)

### Medium probability (payload / attach)

- `web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php`  
- `web/modules/custom/myeventlane_vendor/src/Service/VendorDashboardViewModelBuilder.php`  
- `web/modules/custom/myeventlane_vendor/src/Service/VendorActionQueueBuilder.php`  
- Theme preprocess for `myeventlane_vendor_dashboard`

### Low probability / avoid unless required

- Routing YAML / permissions YAML  
- `VendorConsoleAccess`  
- Growth/Boost modules  
- Event Studio templates  
- `config/sync` exports  
- Public `myeventlane_theme`  

Compiled assets under theme `dist/` may change when build runs — review in PR.

---

## 6. Dependencies

| Dependency | Status |
| --- | --- |
| PDS Phase 1 / frozen philosophy | Available (v1.0.1) |
| `VendorActionQueueBuilder` + view model | Present |
| Vendor shell + layout token | Present |
| Product KPI decision | **Blocking** |
| Stripe connect existing routes | Present — reuse only |
| Analytics/Marketing hubs | Optional link targets; not required to redesign |

---

## 7. Risks

| Risk | Mitigation |
| --- | --- |
| Priority inversion returns via “pretty Pro” | Checklist: queue above celebrations ([16](../vendor-studio/16-design-review-checklist.md)) |
| Fake/optimistic money in loading or KPIs | Skeleton honesty; Commerce state only |
| Stripe CTA invents success | Link to existing connect; no new states |
| Dual nav / Tools mini-hub | Demote; link to global hubs |
| Parallel `dashboard-v2-*` components | Extend [05](../vendor-studio/05-component-library.md) contracts only |
| Heavy uncached queries | Cache contexts + entity tags; avoid expanding homepage analytics on paint |
| Cross-vendor data leak | Keep user/vendor scoping; access checks server-side |
| Scope creep into Workspace | Open event only |
| Governance/policy stack regressions | Keep collapsed; regression-test Pro/free paths |

---

## 8. Validation commands (when implementing)

```bash
# Environment
ddev drush status
ddev drush cr

# PHP / composer (if PHP touched)
ddev composer validate

# Theme assets (if Twig/SCSS/JS touched)
npm run mel:lint
npm run mel:build

# Config (only if config changed)
ddev drush config:status
ddev drush cim --preview

# Diff hygiene
git status
git diff
```

Manual URL: `/vendor/dashboard` as a vendor user (and Pro / free / empty / queued fixtures).

**Do not** run destructive Drush (`sql-drop`, entity mass delete, etc.).

---

## 9. Accessibility testing

| Check | Pass bar |
| --- | --- |
| One H1 | Organiser identity |
| Keyboard | Create event + top queue CTA reachable; no trap |
| Focus visible | All interactive controls |
| Severity | Not colour-only on Action Cards |
| Empty queue | Landmark/status present (“caught up”) |
| Loading | `aria-busy`; no fake metrics |
| Contrast | WCAG AA |
| Reduced motion | Skeletons/toasts respect preference |
| Screen reader | Queue item names include actionable context |
| Mobile 390px | Touch 44×44; queue first after hero |

Align to [21-definition-of-done.md](../vendor-studio/21-definition-of-done.md) and [16](../vendor-studio/16-design-review-checklist.md).

---

## 10. Rollback strategy

| Layer | Rollback |
| --- | --- |
| Theme-only PR | Revert commit / deploy previous theme release; `ddev drush cr` |
| Builder field additions | Keep additive & backward compatible; revert theme first if needed |
| Cache metadata changes | Revert + cache rebuild |
| Config (if any) | `drush cim` previous export — only if config was exported intentionally |
| Feature flag | Not required by PDS; do not invent a flag framework for 1A unless already present in repo |

Prefer small reviewable PR; avoid mixing Workspace/Payments in the same revert unit.

---

## 11. Deployment strategy

1. Feature branch → PR with PDS citations + DoD checklist.  
2. Review: Design Authority (hierarchy) + Technical Authority (access/cache/Commerce).  
3. CI green (lint/build as applicable).  
4. Deploy with normal MEL theme/module release process.  
5. Post-deploy: `drush cr` on target; spot-check vendor fixtures (empty, queued, Pro, Stripe incomplete, door-today).  
6. Monitor support tags for “what do I do next?” / payout findability.  
7. Update [09](../vendor-studio/09-drupal-mapping.md) implemented note if runtime paths corrected.  
8. Measure Dashboard clarity signal ([18](../vendor-studio/18-product-success-metrics.md)).

No direct push to `main`. No force-push to shared branches.

---

## 12. Success criteria (implementation exit)

1. Top needed action identifiable within five seconds.  
2. Action Queue visible without expanders on first paint.  
3. Empty queue shows calm caught-up — not a void.  
4. ≤4 KPIs; metrics never outrank blockers.  
5. Mobile ~390px operable for today’s event.  
6. No parallel component or nav system.  
7. No undocumented Commerce/access behaviour changes.  
8. PDS citations + DoD + design checklist complete.

---

## 13. Strengths · Risks · Recommendations

### Strengths

- PDS already fully specifies Dashboard; no composition DDR required.  
- Runtime already has Action Queue, focus, upcoming, activity, and KPI data paths.  
- Vendor theme + route + access boundaries are clear and reusable.  
- Sprint scope can stay theme-led with thin builder fixes.

### Risks

- Priority inversion and Pro/marketing weight are culturally sticky in the current Twig.  
- Dual data paths (controller extras vs view model) invite inconsistent metrics.  
- Cache tagging gap and heavy Tools payloads can hurt performance and trust.  
- Stripe/money CTAs remain high-risk if honesty slips.

### Recommendations

1. **Approve this design package**, then resolve the six product questions before code.  
2. Implement slices **A→D first** (hierarchy, caught-up, Action Card anatomy, ≤4 KPIs).  
3. Treat Pro confirmation and growth boards as **celebration/secondary** — never above the queue.  
4. Prefer wiring `vendor-kpi-strip` and empty-state components over new partials.  
5. If `reason` is missing on queue items, extend `VendorActionQueueBuilder` — do not fake reasons in Twig.  
6. Reject any PR that introduces widget soup, Dashboard-local nav, or fake loading revenue.  
7. **STOP here** — no Twig/SCSS/PHP until explicit implementation approval.

---

## 14. Explicit non-goals of this package

- No PHP, Twig, SCSS, JS, YAML, or config authored in Sprint 1A design  
- No runtime changes  
- No Event Workspace / Payments / Marketing redesign  

---

## Package index

| Doc | Contents |
| --- | --- |
| [01-dashboard-research.md](01-dashboard-research.md) | Governing docs · current analysis |
| [02-dashboard-wireframes.md](02-dashboard-wireframes.md) | Desktop · tablet · mobile composition |
| [03-dashboard-interactions.md](03-dashboard-interactions.md) | Loading · empty · success · error · a11y behaviours |
| [04-dashboard-mobile.md](04-dashboard-mobile.md) | 390px ops rules |
| [05-dashboard-drupal-mapping.md](05-dashboard-drupal-mapping.md) | Builders · Twig · libraries · SCSS reuse |
| [06-dashboard-implementation-plan.md](06-dashboard-implementation-plan.md) | This plan |

**Await approval before implementation.**
