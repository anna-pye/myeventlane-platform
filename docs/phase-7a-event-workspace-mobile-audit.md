# Phase 7A — Event workspace mobile audit

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-24  
**Scope:** Operations hub (`/vendor/events/{event}/operations`), Door Mode, workspace shell chrome.

---

## 1. Audit method

Repository SCSS and Twig were reviewed for layout contracts at 320, 375, 768, 1024, and 1280px. **Runtime verification is required** — items marked **[needs runtime]** cannot be confirmed from static analysis alone.

---

## 2. Breakpoint evidence (SCSS)

| Breakpoint | Source | Behaviour |
|------------|--------|-----------|
| ≤767px (sm max) | `_live-operations.scss` | Quick bar horizontal scroll; actions `min-height: 2.75rem`; hero padding reduced; door sticky CTA `min-height: 2.75rem` |
| 768px+ (md) | `_live-operations.scss` | Quick inner grid wraps to multi-column (`grid-auto-flow: row`, `auto-fit minmax(9.5rem)`) |
| 1024px+ (lg) | `_live-operations.scss` | Metric board 3 columns |
| 1280px+ (xl) | `_live-operations.scss` | Metric board 6 columns; split layout for roster |

Workspace tabs: `_workspace.scss` — horizontal scroll, `min-height: 2.75rem`, `overflow-x: auto`.

---

## 3. Slice verification checklist

### Toolbar (operations mission bar)

| Check | SCSS evidence | Runtime |
|-------|---------------|---------|
| 44px touch targets | `.mel-live-ops__action` `min-height: 2.75rem` at sm max | **[needs runtime]** |
| Primary Door Mode first | `.mel-live-ops__action--featured { order: -1 }` + Twig icon order | **[needs runtime]** |
| Horizontal scroll at 320/375 | `.mel-live-ops__quick-inner` `overflow-x: auto` | **[needs runtime]** |
| Wrap at 768+ | `@include mel-break(md)` grid row flow | **[needs runtime]** |
| No duplicate Door CTA | Hero partial has no door link; toolbar only | Confirmed in Twig |

### Event identity hero

| Check | Evidence | Runtime |
|-------|----------|---------|
| Single identity surface | `suppress_workspace_header` for live ops themes | Confirmed in `.theme` |
| Title overflow | `clamp()` on `.mel-live-ops__hero-title` | **[needs runtime]** |
| Hero height on 320 | Reduced padding on sm max | **[needs runtime]** |
| Venue/date visible | Hero partial `start_line`, `venue_line` from view model | Confirmed in builder |

### Health strip

| Check | Evidence | Runtime |
|-------|----------|---------|
| Chips wrap | `.mel-live-ops__chip-row` `flex-wrap: wrap` | **[needs runtime]** |
| Check-in rate chip | Twig `@pct% checked in` from existing metrics | Confirmed |
| No invented signals | Chips from `buildHeroStatusChips()` only | Confirmed in PHP |

### Layout hierarchy

| Check | Evidence | Runtime |
|-------|----------|---------|
| Reduced shadow depth | Stat cards, panels, search card `box-shadow: none` | **[needs runtime]** |
| Card-in-card reduced | Search card padding/border flattened | **[needs runtime]** |

### Accessibility

| Check | Evidence | Runtime |
|-------|----------|---------|
| No invalid tab roles | `workspace-tabs.html.twig` — nav links only | Confirmed |
| Progressbar on check-in ring | Hero partial `role="progressbar"` | Confirmed |
| Focus order toolbar → hero → metrics | DOM order in `mel-venue-operations.html.twig` | **[needs runtime]** |
| Door one-handed use | Sticky `Scan QR` bottom bar + mega CTA | **[needs runtime]** |

---

## 4. Assumptions

1. Orders toolbar link uses existing route `myeventlane_vendor.console.event_orders` via `safeRouteUrl()` — same pattern as tabs service; access enforced by route requirements.
2. Door settings action remains in `operational_actions` but is excluded from the mission bar (not in `ops_toolbar_icons`).
3. Mission-control overview (`mel-event-workspace.html.twig` staff model) is unchanged — Phase 7A targets operations surfaces.

---

## 5. Screenshots required (manual)

Capture at **320, 375, 768, 1024, 1280** for:

1. `/vendor/events/{event}/operations` — full page above fold
2. Same — toolbar scrolled/wrapped state
3. Door Mode — hero + scanner + sticky CTA
4. Health strip with capacity / live chips visible

---

## Related docs

- [workspace-tab-governance.md](workspace-tab-governance.md)
- [vendor-experience-v3-audit-2026-06-23.md](vendor-experience-v3-audit-2026-06-23.md)
