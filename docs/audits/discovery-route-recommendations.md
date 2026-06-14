# Discovery Route Recommendations

**Audit date:** 2026-06-14  
**Branch:** `feature/discovery-route-audit`  
**Scope:** Recommendations only — **no implementation** in this workstream.

Classification: **Keep** | **Improve** | **Merge** | **Remove** | **Needs Investigation**

---

## Executive summary

MEL’s discovery spine is **`upcoming_events` Views displays** at six active `/events/*` paths. Two heavily referenced paths (`/events/nearby`, `/events/online`) have **no route owner** in the repository and should be treated as **broken IA** until built or unlinked.

---

## Route recommendations

### `/events` — `page_events`

| Verdict | **Keep** |
|---------|----------|
| **Evidence** | Canonical browse route; richest template stack; highest cross-surface linking |
| **Suggested follow-up (not in scope)** | None required for route existence |

---

### `/events/popular` — `page_popular`

| Verdict | **Improve** |
|---------|-------------|
| **Evidence** | Active route with promoted filter; footer + main menu placement; **not** in homepage rails or browse quicklinks; overlaps `front_featured_events` (both use `field_promoted`) |
| **Suggested follow-up** | Clarify product distinction: "Community spotlight" (homepage editorial) vs "Popular" (full listing page); align navigation (add to chips or demote menu); add to `$listing_routes` / browse shell for UX parity |

---

### `/events/this-weekend` — `page_this_weekend`

| Verdict | **Keep** |
|---------|----------|
| **Evidence** | Strong footer/chip/homepage linkage; distinct weekend date filter |
| **Suggested follow-up** | Add dedicated page shell or inherit browse header for mobile parity with `/events` |

---

### `/events/today` — `page_today`

| Verdict | **Keep** |
|---------|----------|
| **Evidence** | Homepage "Happening tonight" CTA; distinct today date filter; AJAX chip reuse |
| **Duplication note** | **Not** duplicate of `this-weekend` — different filter windows (evidence: View YAML) |
| **Suggested follow-up** | Consider copy alignment: homepage says "tonight" but route is `/events/today` |

---

### `/events/nearby`

| Verdict | **Needs Investigation** → then **Improve** or **Remove** links |
|---------|----------------------------------------------------------------|
| **Evidence** | No route in `config/sync`; links in empty states (`page-events`, `page-category`, `mel-view-empty-events`); `mel_home_events:near_you` block exists but **not geo-filtered** and **not placed** |
| **Options (product decision)** | (A) Add `page_nearby` View display + geo filter using `field_location_*` / user `field_city`; (B) Remove hardcoded links until built; (C) Redirect to `/events` with location exposed filter |
| **Risk** | Empty-state recovery links currently point to likely **404** |

---

### `/events/online`

| Verdict | **Needs Investigation** → then **Improve** or **Remove** links |
|---------|----------------------------------------------------------------|
| **Evidence** | No route; no `online` in `field_event_type`; homepage section inactive (no block); deferred in `docs/product-reset-phase-1-deferred.md` |
| **Options** | (A) Add `page_online` display with documented filter criterion (field TBD — **cannot confirm filter field from repository**); (B) Remove homepage/CTA references; (C) Use `/events?event_type=…` only if a suitable field value exists — **none evidenced** |
| **Risk** | Product promise ("Join from wherever you are") without backend route |

---

### `/events/free` — `page_free`

| Verdict | **Keep** |
|---------|----------|
| **Evidence** | Footer, chips, homepage section; query alter enforces free/RSVP hygiene |
| **Note** | Chip label "Free & RSVP" vs footer "Free events" — copy inconsistency only |

---

### `/events/category/{slug}` — `page_category`

| Verdict | **Keep** |
|---------|----------|
| **Evidence** | Canonical category discovery; redirect subscriber; footer category links |
| **Suggested follow-up** | Add `nearby`/`online` to `RESERVED_EVENT_PATHS` if those routes are built (prevent slug collision) |

---

## Duplication audit

| Pair | Duplicate? | Evidence | Recommendation |
|------|------------|----------|----------------|
| **today vs this-weekend** | **No** | `page_today`: start `between today/tomorrow`; `page_this_weekend`: `saturday this week`–`sunday this week` | **Keep** both |
| **popular vs Community spotlight** | **Partial overlap** | Both filter/sort on `field_promoted`; homepage uses `front_featured_events` block; `/events/popular` is full paginated listing | **Improve** — clarify roles or **Merge** marketing copy |
| **online vs category route** | **N/A** | No online route | **Needs Investigation** before merge decision |
| **free vs homepage under_20** | **Related, not duplicate** | `page_free` = route; `mel_home_events:under_20` = homepage block with separate display | **Keep** both; ensure CTAs stay consistent |
| **page_events vs search** | **No** | Search is keyword index; `/events` is faceted browse | **Keep** both |
| **homepage tonight block vs page_today** | **Complementary** | Block embed vs full listing page; homepage CTA → `page_today` | **Keep** |

---

## Empty state audit

| Route / surface | Empty state | Owner | Status |
|-----------------|-------------|-------|--------|
| `page_events` | Custom in view template (`mel-empty--browse` + recovery links) | `views-view--upcoming-events--page-events.html.twig` L41–53 | **Exists** — includes **broken** `/events/nearby` link |
| `page_events` (View config) | Minimal HTML area | `views.view.upcoming_events.yml` L1662–1674 | **Exists** — superseded by template when no results |
| `page_popular` | Governed empty with CTA → `/events` | View YAML L2338–2350 | **Exists** |
| `page_today`, `page_this_weekend` | Inherits default display empty | View YAML L98–109 (default) | **Inherited** — generic governed message |
| `page_free` | Inherits default (no display-specific empty in YAML) | default display | **Inherited** |
| `page_category` | Custom in template (not View empty area) | `views-view--upcoming-events--page-category.html.twig` L76–88 | **Exists** — includes **broken** `/events/nearby` link |
| Search (no results) | `mel-empty-state--listing` | `myeventlane-search-results.html.twig` L18–28 | **Exists** — links to `page_events` + front |
| Homepage tonight block | `mel-view-empty-events.html.twig` | unformatted/tonight templates | **Exists** |
| `/events/nearby`, `/events/online` | N/A | — | **Missing** (no route) |

---

## Navigation hygiene recommendations

| Issue | Verdict | Evidence |
|-------|---------|----------|
| Hardcoded `/events/nearby` and `/events/online` in Twig | **Remove** or replace with `path()` to real routes once built | Multiple templates bypass route system |
| `page_popular` absent from discovery filter chips | **Improve** — add chip or remove footer/menu prominence | `mel-events-discovery-filters.html.twig` omits popular |
| Inconsistent page shells across date routes | **Improve** — extend browse shell or add `page--view--*` overrides | Only `page_events` has browse shell |
| Duplicate footer IA (builder + static menu links) | **Keep** (both deployable) — document single source | `PublicFooterNavigationBuilder` + `myeventlane_core.links.menu.yml` |
| Unused homepage regions `homepage_nearby` / `homepage_online` | **Improve** — place blocks or remove sections from `page--front.html.twig` | No block config in sync |

---

## Security recommendations (audit only)

| Item | Verdict |
|------|---------|
| Public discovery access via `access content` | **Keep** — standard Drupal public content |
| `/search` `_access: TRUE` | **Keep** — intentional public search |
| Category redirect subscriber | **Keep** — 301 to canonical; preserves access checks on term resolution |
| Reserve slug list when adding routes | **Improve** — extend `RESERVED_EVENT_PATHS` for `nearby`, `online` when implemented |

---

## Priority order (if implementing later)

1. **Resolve `/events/nearby` and `/events/online`** — highest broken-link risk (empty-state recovery paths).
2. **UX parity** — browse shell / hero headers for `page_today`, `page_this_weekend`, `page_free`, `page_popular`.
3. **Clarify popular vs spotlight** — product copy and navigation alignment.
4. **Extend reserved paths** — when new displays ship.

---

## Explicitly deferred (per audit scope)

- View config changes
- Route registration
- Twig/navigation edits
- Analytics
- Geo-filter implementation
- Online event field modelling
