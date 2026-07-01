# Workspace tab governance — Phase 6B

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-24  
**Scope:** Event workspace horizontal section navigation (`workspace-tabs.html.twig`).

---

## 1. Tab ownership

| Source | Status | Role |
|--------|--------|------|
| `VendorEventTabsService` | **Canonical** | Builds tab rows from routes, mode gates (RSVP/tickets), and `AccessManager` checks. Exposes `getTabs()` (legacy string URLs for controllers) and `buildWorkspaceTabs()` (Url objects + `available` for mission-control model). |
| `VendorEventWorkspaceViewModelBuilder` | Consumer | Calls `buildWorkspaceTabs()` for `model.tabs` on mission-control overview. |
| Event workspace controllers | Consumers | Inject `VendorEventTabsService::getTabs($event, $activeKey)` and pass `#tabs` to `mel_event_workspace`. |
| `VendorConsolePagePreprocess` | **Legacy** | Hard-coded tab list on `myeventlane_vendor_console_page` without mode gates or access checks. Used only by `vendor-console-page.html.twig` (staff console shell). Do not extend. |
| `myeventlane_vendor.links.task.yml` | **Disabled at runtime** | Local task definitions exist for documentation and staff/admin themes; vendor theme blocks are `status: false` in config sync. |
| `EventStudioSectionManager` | Separate system | Event Studio sidebar/sections — not merged with workspace tabs (out of scope). |

---

## 2. ARIA implementation (after Phase 6B)

| Element | Before | After |
|---------|--------|-------|
| Wrapper | `<nav role="tablist" aria-label="Event sections">` | `<nav aria-label="Event sections">` |
| Active link | `role="tab"` + `aria-selected="true"` + `aria-current="page"` | `aria-current="page"` only |
| Inactive link | `role="tab"` + `aria-selected="false"` | Plain `<a>` (no tab roles) |
| Disabled item | `role="tab"` + `aria-disabled="true"` | `<span aria-disabled="true">` (retained) |

**Rationale:** Items navigate to distinct routes (full page loads). No `role="tabpanel"` regions and no JavaScript tab switching exist in the workspace shell — WAI-ARIA tab widget semantics were invalid.

---

## 3. Consumers of workspace tabs

| Template / surface | Tab data source | Visible to |
|--------------------|-----------------|------------|
| `mel-event-workspace.html.twig` (mission control) | `vendor_event_workspace_model.tabs` via `buildWorkspaceTabs()` | Staff only (`show_workspace_tabs`) |
| `mel-event-workspace.html.twig` (legacy shell) | `#tabs` from controllers via `getTabs()` | Staff only |
| `vendor-console-page.html.twig` | `VendorConsolePagePreprocess` workspace.tabs | Staff console pages |

**Staff gating:** `myeventlane_vendor_theme_preprocess_mel_event_workspace()` sets `show_workspace_tabs` from `_myeventlane_vendor_theme_is_vendor_workspace_staff()` (`administer nodes` or uid 1). Vendors use Event Studio navigation instead; tab variables are cleared for non-staff.

**Controllers using `VendorEventTabsService::getTabs()`:** overview, tickets, RSVPs, attendees, operations, orders, add-on orders, refund requests, analytics, settings, boost (module).

---

## 4. Local task usage

File: `web/modules/custom/myeventlane_vendor/myeventlane_vendor.links.task.yml`

Defines event workspace local tasks (Manage event, Tickets, Orders, RSVPs, Analytics, Settings, etc.) with `base_route: myeventlane_vendor.console.event_workspace`.

**Vendor theme rendering:** Disabled in config sync:

- `block.block.myeventlane_vendor_theme_primary_local_tasks.yml` — `status: false`
- `block.block.myeventlane_vendor_theme_secondary_local_tasks.yml` — `status: false`

Workspace navigation is rendered exclusively by the Twig partial + `VendorEventTabsService`, not Drupal local tasks blocks.

---

## 5. Staff mission control — future opportunities (document only)

Audited surfaces in `mel-event-workspace.html.twig` (staff-only mission control):

| Surface | Current state | Future opportunity |
|---------|---------------|-------------------|
| Mission-control hero | Event identity, readiness chip, primary CTAs | Align heading hierarchy with live-ops hero partial when staff shell converges |
| Sales snapshot | Metric cards with optional links | Ensure `aria-label` on linked vs static cards is distinct for screen readers |
| Action grid (Sales / Setup / Growth) | Tile links without explicit list semantics | Consider `<ul role="list">` for grouped shortcuts if tile count grows |
| Workspace header (non-model fallback) | `workspace-header.html.twig` with `role="region"` | Consolidate with mission-control hero to reduce duplicate event identity |
| Lifecycle guidance / readiness panels | Collapsible `<details>` | Keyboard and `aria-expanded` already native; review live region for dynamic readiness updates |
| Horizontal tab bar | Fixed in Phase 6B | Monitor overflow at 320px with 10+ tabs; SCSS already provides horizontal scroll + 44px targets |

No implementation in Phase 6B — operational and dashboard work remain out of scope.

---

## 6. Mobile verification (SCSS evidence)

From `web/themes/custom/myeventlane_vendor_theme/src/scss/components/_workspace.scss`:

- `.mel-tabs--horizontal`: `overflow-x: auto`, `-webkit-overflow-scrolling: touch`, `flex-wrap: nowrap`
- `.mel-tab`: `min-height: 2.75rem` (44px), `padding: $spacing-2 $spacing-4`
- `:focus-visible`: 3px focus ring on `$mel-ws-purple`
- `prefers-reduced-motion`: transition duration collapsed

Breakpoints inherit vendor theme `respond-to(md)` elsewhere; horizontal scroll handles 320–375px overflow without wrapping.

---

## Related docs

- [workspace-ownership-map.md](audits/workspace-ownership-map.md) — vendor workspace vs Event Studio ownership
- [vendor-console-v2-route-map.md](vendor-console-v2-route-map.md) — route and template map
