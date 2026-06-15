# Badge Ownership Map

**Audit date:** 2026-06-15  
**Task:** CF-3F — final badge ownership consolidation (CF-3A through CF-3E follow-up)  
**Method:** Repository-wide trace of badge producers, consumers, and render paths. No behaviour changes in this task.

---

## Executive summary

Public event **image badges** and **body merchandising signals** on discovery cards are owned by a single PHP presenter and rendered through a single Twig component. CF-3F removes the last duplicate ViewModel mirror (`badges[]`, `is_featured`) that had no active consumers.

**Community Favourite image badges remain disabled.** Community Favourites surfaces use **discovery-reason copy** only (`mel_source` attribution in Twig), not `EventMerchandisingPresenter` image badges.

---

## Canonical owners

| Layer | Owner | File | Method / output |
|-------|-------|------|-----------------|
| **PHP image badge + body signal** | `EventMerchandisingPresenter` | `web/modules/custom/myeventlane_event/src/EventCard/EventMerchandisingPresenter.php` | `present()` → `image_badge_label`, `image_badge_modifier`, `primary`, `hero_proof`, ticket pill flags |
| **Card variable injection** | `EventCardViewModel` | `web/modules/custom/myeventlane_event/src/EventCard/EventCardViewModel.php` | `build()` → `merchandising`; `applyToNodeVariables()` → `card_merchandising` |
| **Public card render** | `mel-event-card.html.twig` | `web/themes/custom/myeventlane_theme/templates/components/event-card/mel-event-card.html.twig` | Reads `card_merchandising` (`_merch`) for image badge + body signal |
| **Event full-page hero badge** | `myeventlane_event_apply_full_page_discovery_badge()` | `web/modules/custom/myeventlane_event/myeventlane_event.module` | Calls presenter → `mel_hero_discovery_badge` |
| **Full-page hero render** | `node--event--full.html.twig` | `web/themes/custom/myeventlane_theme/templates/node/node--event--full.html.twig` | Renders `mel_hero_discovery_badge` on hero image |

### Image badge priority (`EventMerchandisingPresenter`)

1. Sold out  
2. Spotlight (promoted / boosted)  
3. Hidden Gem  

One image badge per card. No Community Favourite, Editor's Pick, Trending Tonight, or Nearby image badges are emitted by the presenter today.

### Body signal priority (`EventMerchandisingPresenter`)

Sold out → Going (attendance) → Tonight urgency → Selling fast → Limited spots. Price may appear as a ticket pill on discovery layouts when no body signal competes.

---

## Fallback owners (Twig only — not presenter)

| Signal | Owner | File | When active |
|--------|-------|------|-------------|
| Sold out image badge | `event_ui.is_sold_out` | `mel-event-card.html.twig` | When `card_merchandising` has no `image_badge_label` but `event_ui` reports sold out |
| Category image badge | Category label on card | `mel-event-card.html.twig` | Non-discovery mode only; when merchandising and sold-out fallbacks do not apply |
| Discovery reason (not image badge) | `mel_source` rail attribution | `mel-event-card.html.twig` | Discovery/spotlight layouts; copy such as "Popular with the community" for `homepage_community_favourites` / `browse_community_favourites` |

These fallbacks are presentation-layer only. They do not duplicate `EventMerchandisingPresenter` image badge types except sold-out (safety net when bridge paths lack merchandising).

---

## Active consumers

| Consumer | Path | `card_merchandising` | `event_ui` | Status |
|----------|------|----------------------|------------|--------|
| Node card view modes | `node--event--*.html.twig` → `mel-event-card.html.twig` | Yes — `EventCardViewModel::applyToNodeVariables()` | Yes — `myeventlane_event_preprocess_node()` | **Active** |
| Views `entity:node` rows | `upcoming_events`, `mel_home_events`, `mel_saved_events`, `all_events`, etc. (`row.type: entity:node`, `view_mode: compact_commerce` / `list_card`) | Yes — same node pipeline | Yes | **Active** |
| Search `/search` events group | `SearchController::renderEventItems()` → `compact_commerce` node render | Yes — same node pipeline | Yes | **Active** |
| Event full page | `myeventlane_event_apply_full_page_discovery_badge()` → `mel_hero_discovery_badge` | Via direct presenter call (hero layout) | Yes — sold-out input | **Active** |

### Active card rendering paths (production)

```
myeventlane_event_preprocess_node()
  → event_ui, domain state, CTA
myeventlane_theme_preprocess_node()
  → EventCardViewModel::applyToNodeVariables()
    → EventMerchandisingPresenter::present()
    → card_merchandising
node--event--{view_mode}.html.twig
  → mel-event-card.html.twig
```

Search and Views entity rows enter this path via `$view_builder->view($node, 'compact_commerce')` or equivalent node view modes.

---

## Dormant consumers

| Consumer | Path | `card_merchandising` | Status | Evidence |
|----------|------|----------------------|--------|----------|
| Views fields bridge | `views-view-fields.html.twig` → `event/event-card.html.twig` → `mel-event-card.html.twig` | **No** | **Dormant** | No public event View in `config/sync` uses `row.type: fields` for card grids; bridge references computed fields (`field_date_day`, `field_ticket_label`) absent from Views config (CF-3E) |
| Search bridge | `search-result.html.twig` → `event/event-card.html.twig` | **No** | **Dormant** | `mel_is_event_search_result` / `mel_event_card` never set; active search is `myeventlane_search` with node renders (CF-3E) |

Preprocess on the fields bridge (`myeventlane_theme_preprocess_views_view_fields()`) sets URL, `event_id`, and `mel_source` only — not merchandising.

---

## Removed duplicate model (CF-3F Phase 1)

| Field | Former owner | Consumers found | Action |
|-------|--------------|-----------------|--------|
| `badges[]` | `EventCardViewModel::build()` return + `event_card` variable | **None** (`rg 'event_card\.badges'` → no matches) | **Removed** |
| `is_featured` | `EventCardViewModel::build()` return + `event_card` variable | **None** (`rg 'is_featured' web` → definition only) | **Removed** |

Image badge data now flows only through `merchandising` / `card_merchandising`. Category and merchandising were previously duplicated into `badges[]`; Twig never read that array.

---

## Community Favourite image badge — disabled (governance)

**Do not add** a Community Favourite image badge in `EventMerchandisingPresenter` until product and brand criteria are complete.

### Current behaviour

- **Homepage / browse Community Favourites rails** rank events via `PopularEventsService` (`myeventlane_analytics.popular_events`) — see `docs/audits/popularity-ownership-map.md`.
- **Attribution** uses `DiscoveryAttributionSources` keys `homepage_community_favourites` / `browse_community_favourites`.
- **Presentation** is a **discovery reason** string in `mel-event-card.html.twig` ("Popular with the community"), not an image badge from the presenter.

### Why image badge remains disabled

| Reason | Evidence |
|--------|----------|
| Duplicate presentation on CF surfaces | Rail already shows section title + discovery reason; adding a presenter image badge would stack two "community" signals on the same card |
| No single eligibility boolean | `PopularEventsService` returns ranked nids; there is no `field_community_favourite` or per-card eligibility flag for the presenter to read |
| Governance criteria incomplete | `docs/audits/brand-rollout/community-favourites-audit.md` (CF-2C community-favourites audit): badge requires documented eligibility before enablement; `docs/brand/event-card-system.md` requires evidence-based criteria |
| Brand constraint | Community Favourite must not represent paid/boosted placement (`field_promoted`); presenter today uses Spotlight for promoted events — a CF image badge needs a distinct, non-colliding signal key |

### Explicit forbidden changes (until a future CF task)

- Do not inject `PopularEventsService` into `EventMerchandisingPresenter`
- Do not add Community Favourite to image badge priority chain
- Do not add new badge services, preprocess hooks, or eligibility APIs in presenter-adjacent code

Reference audits: `docs/audits/brand-rollout/community-favourites-audit.md`, `docs/audits/popularity-ownership-map.md`, `docs/audits/brand-rollout/brand-gap-analysis.md` (badge criteria gap).

---

## Current state vs future state

| Area | Current state | Future state (not in CF-3F) |
|------|---------------|------------------------------|
| Image badges on cards | Presenter: Sold out, Spotlight, Hidden Gem | Community Favourite image badge only after eligibility doc + single boolean + brand sign-off |
| Community Favourites rails | Engine ranking + discovery reason copy | Unchanged; no new badge type |
| ViewModel `event_card` model | No `badges[]` / `is_featured` mirror | Keep merchandising as sole badge payload |
| Dormant bridges | No merchandising if ever activated | Optional CF follow-up: enrich `preprocess_views_view_fields()` via existing `EventCardViewModel` (CF-3E noted) |

---

## Known debt

1. **Dormant bridge templates** — `views-view-fields.html.twig` and `search-result.html.twig` remain in theme; activating them without ViewModel enrichment would show category/sold-out fallbacks only.
2. **Category Twig fallback** — non-discovery cards can still show category as image badge when merchandising is empty (`mel-event-card.html.twig`); intentional per CF-3E.
3. **Account / vendor / checkout cards** — separate templates (`mel-account-event-card.html.twig`, etc.); out of public discovery badge system scope.
4. **`event_ui.status_label` Spotlight** — CTA/status strip can still show "Spotlight" when `show_cta` is false; distinct from image badge stack but related copy surface.

---

## Out of scope (CF-3F)

- Twig card rendering changes
- Bridge template changes
- New services or preprocess hooks
- `PopularEventsService`, `DiscoveryAttributionSources`, or analytics changes
- Views config, routes, discovery attribution, or Community Favourites ranking
- Community Favourite image badge implementation

---

## Validation references

After CF-3F code change:

```bash
rg "badges\[" web/modules/custom/myeventlane_event/src/EventCard/
rg "is_featured" web/modules/custom/myeventlane_event/
php -l web/modules/custom/myeventlane_event/src/EventCard/EventCardViewModel.php
```

`EventMerchandisingPresenterTest` remains the unit test owner for badge precedence.
