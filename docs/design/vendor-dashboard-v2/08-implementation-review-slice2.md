# Vendor Dashboard v2 — Implementation Review (Slice 2)

**Status:** Implementation complete — awaiting review (not committed)  
**Branch:** `feature/vendor-dashboard-v2-slice2`  
**Date:** 2026-07-25  
**Authority:** Vendor Studio PDS v1.0.1 · [07-runtime-discovery-slice2.md](07-runtime-discovery-slice2.md)

---

## Objectives achieved

| Objective | Result |
| --- | --- |
| Reduce organiser anxiety / operational awareness | Today’s Event elevated; Daily Brief when factual; doors timing surfaced |
| Do not increase complexity | No new architecture, libraries, routes, or AI |
| Today’s Event panel | Shown only for upcoming / in-session; Workspace + Door Mode CTAs |
| Business health ≤4, lean | Label → value → optional trend; card chrome removed |
| Upcoming / Activity hierarchy | Spacing + typography + grouping only |
| Daily Brief | From runtime only; hidden when no factual lines |
| Skeleton loading | Reuses `.mel-skeleton`; `prefers-reduced-motion`; no fake stats |
| Performance (safe) | Cache tags + `timezone` context; no payload behaviour change beyond doors label |

---

## Architecture decisions

1. **Branch from Slice 1 WIP** — Slice 1 was not on `main`; carried uncommitted Slice 1 Twig/SCSS into `feature/vendor-dashboard-v2-slice2`.  
2. **Thin builder exposure** — `doors_open_label` / `start_timestamp` / `end_timestamp` surface already-read `field_event_start` / `field_event_end`. Not invented metrics.  
3. **Omit unread messages** — no dashboard unread payload exists; panel does not show a fabricated count.  
4. **Daily Brief in theme preprocess** — assembles greeting + factual lines from view model + activity timestamps; returns `NULL` when empty.  
5. **Activity grouping in preprocess** — consecutive identical message+type rows collapse with count.  
6. **Skeleton SSR-honest** — include ships `hidden`; `aria-busy="false"` on SSR; progressive loaders may reveal later.  
7. **No DDR** — no new component family or IA change; omissions documented instead of inventing Messages.

---

## Components reused

- Action Queue / Action Cards (Slice 1)  
- `.mel-btn` primary / secondary  
- `.mel-empty-state` caught-up  
- `.mel-skeleton` / `.mel-loading` (`_empty-states.scss`)  
- `mel-event-card-thumb`  
- `stripe-panel`  
- Existing `VendorDashboardViewModelBuilder`, `VendorActionQueueBuilder`, controller activity builder  
- Shell / libraries unchanged  

**Not invented:** parallel dashboard, new KPI card system, AI brief, unread inbox widget.

---

## Accessibility review

| Check | Status |
| --- | --- |
| WCAG AA contrast intent | Lean text hierarchy; status not colour-only (Live text + status label) |
| Visible focus | KPI links, activity links, CTAs retain focus outlines |
| Keyboard | Native links/buttons only |
| 44px targets | Today’s Event / Create / Door Mode / Open Workspace |
| Screen reader | Daily Brief `role="status"`; skeleton visually-hidden loading copy; status `visually-hidden` prefixes |
| Skeletons + reduced motion | Animation disabled under `prefers-reduced-motion` |
| `aria-busy` | Explicit `false` on SSR; skeleton region `hidden` |

---

## Performance improvements

| Change | Behavioural impact |
| --- | --- |
| Cache contexts: `user`, `user.roles`, **`timezone`** | Correct variation for local “today” / doors labels |
| Cache tags: `node_list`, `commerce_order_list`, `myeventlane_vendor:{id}`, per-event `node:{nid}` | Safer invalidation |
| No second view-model build | Unchanged — still one `build()` call |

**Not done (debt):** controller still loads events separately from the view model for activity/notifications — refactor deferred to avoid behavioural risk.

---

## Lessons learned

- Slice 1 was design-approved in WIP but not merged; Slice 2 must carry that foundation explicitly.  
- Aspirational brief fields (unread messages, overnight bookings API) must lose to repository-first rules.  
- PDS cites **ADR-0002** but the file is missing in-repo — governance debt.  
- KPI `trend` keys exist but remain `NULL` — UI ready, data not.

---

## Recommendations for Slice 3

1. Merge / commit Slice 1 + Slice 2 as a coherent PR stack (or single PR with clear sectioning).  
2. Wire real organiser **unread message** count into the view model (access-checked) if Messages hub owns it — then show on Today’s Event.  
3. Optional live doors countdown via small JS using `start_timestamp` (respect reduced motion).  
4. Collapse duplicate controller event loading vs view model (request-scoped memo).  
5. Add Stylelint coverage for vendor theme dashboard SCSS in `mel:lint`.  
6. Restore / add **ADR-0002** file to match README citations.  
7. Product decision: sticky mobile Create event (still open from Sprint 1A plan).

---

## Potential DDRs

| Candidate | Needed now? |
| --- | --- |
| Unread messages on Dashboard | **No** — wait until payload exists |
| Client-side live countdown | Optional later; not a philosophy change |
| Daily Brief as first-class component | **No** — presentational aside only |

**No DDR filed for Slice 2.**

---

## Validation run

| Command | Result |
| --- | --- |
| `npm run mel:lint` | Pass |
| `npm run mel:build` | Pass (public + vendor themes) |
| `ddev drush cr` | Success |
| `ddev drush status` | Bootstrap successful |
| Config changes | **None** |

---

## Suggested PR title

`feat(vendor-dashboard): Slice 2 operational awareness (Today’s Event, Daily Brief, lean KPIs)`

## Suggested commit message

```
feat(vendor-dashboard): add Slice 2 operational awareness

Elevate Today's Event, add a factual Daily Brief, calm KPI/upcoming/activity
presentation, skeleton chrome, and safe dashboard cache metadata — without
inventing unread counts or new architecture.
```
