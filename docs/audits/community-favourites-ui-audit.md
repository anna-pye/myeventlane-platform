# Community Favourites UI Audit (Phase 9)

**Date:** 2026-06-17  
**Scope:** Homepage Community Favourites rail only — audit and redesign options; **no runtime changes in this phase**  
**Branch:** `feature/homepage-copy-phase-1a`  
**Method:** Repository evidence only

---

## Executive summary

Community Favourites is the **only homepage discovery rail** that bypasses the Views + unformatted Twig grid pipeline. It is rendered by a **custom block plugin** (`PopularEventsBlock`) that builds a PHP render array, wraps each card in a bespoke container, and applies a **different grid class** than every other rail. The section shell (title, subtitle, CTA) is owned by `page--front.html.twig`; card chrome is shared via `compact_commerce` view mode → `mel-event-card.html.twig`; layout and social-proof treatment are **not** aligned with standard MEL discovery grids.

**Recommendation for a future phase:** Re-route Community Favourites through the same `mel-grid mel-grid--events` wrapper used by Tonight, Hidden Gems, Discover, and Easy Ways To Join In — either by emitting that markup from the block or by introducing a thin Twig theme hook — and integrate the “X going” label into the card view model rather than a sibling render element.

---

## 1. Render architecture

### 1.1 Full render path

| Layer | Owner | Evidence |
|---|---|---|
| Page template order | `page--front.html.twig` | `mel_home_show_community_favourites` gate; section shell via `mel-section-shell.html.twig` |
| Visibility gate | `myeventlane_theme.theme` preprocess + `HomepageSectionVisibility::hasCommunityFavouritesEvents()` | Post-dedup pool via `HomepageMerchandising::getCommunityFavouritesEventIds()` |
| Block placement | `config/sync/block.block.myeventlane_theme_homepage_community_favourites.yml` | Region `homepage_community_favourites`; plugin `myeventlane_popular_events_block`; `<front>` only |
| Data source | `PopularEventsService` (7-day engagement score) | Injected in `PopularEventsBlock.php` |
| Merchandising / dedup | `PopularEventsBlock::applyHomepageMerchandising()` + `HomepageRailDiversityFilter` | Excludes NIDs from higher-priority rails |
| Card build | `EntityViewBuilder::view($node, 'compact_commerce')` | Same view mode as View-based rails |
| Attribution | `#mel_discovery_source = homepage_community_favourites` | `DiscoveryAttributionSources::SOURCE_HOMEPAGE_COMMUNITY_FAVOURITES` |
| Grid wrapper | **Block-owned** `.mel-popular-events-block__grid.mel-event-grid` | **Not** `.mel-grid.mel-grid--events` |

### 1.2 Why it renders differently from other rails

| Aspect | Standard rails (Tonight, Hidden Gems, Discover, Free RSVP) | Community Favourites |
|---|---|---|
| Query / data | Views display (`views.view.*.yml`) | `PopularEventsService` + PHP block |
| Row wrapper | Views unformatted Twig (e.g. `views-view-unformatted--mel-home-events.html.twig`) | PHP `#type => container` per card |
| Grid class | `.mel-event-grid.mel-grid.mel-grid--events` | `.mel-popular-events-block__grid.mel-event-grid` |
| Homepage 4-col grid SCSS | `_front-page.scss` lines 520–544 target `.mel-grid.mel-grid--events` | **Excluded** — uses `_event-cards-festival.scss` 2-col at `md` only |
| Social proof | Card badges / `EventCardViewModel` | Sibling `.mel-popular-event__going` div **below** card |
| Section title | `mel-section-shell.html.twig` (theme) | Block `title` config is empty (`title: ''` in block config); shell title used |
| Empty handling | View empty plugin or governed empty include | Block returns `#markup => ''` — shell hidden by visibility service |

---

## 2. Template and card ownership

### 2.1 Block plugin (canonical inner content owner)

- **File:** `web/modules/custom/myeventlane_front/src/Plugin/Block/PopularEventsBlock.php`
- **Plugin ID:** `myeventlane_popular_events_block`
- **No dedicated Twig template** — output is a render array only
- **Default limit:** 8 (`defaultConfiguration()` + block config `limit: 8`)
- **View mode:** `compact_commerce` (falls back to `teaser` if missing)

### 2.2 Section shell (canonical section chrome owner)

- **File:** `web/themes/custom/myeventlane_theme/templates/page--front.html.twig`
- **Classes:** `mel-section--community-favourites mel-section--discover`
- **Copy:** “Community Favourites” / “Experiences people are joining — from real tickets and RSVPs this week.”
- **CTA:** `view.upcoming_events.page_popular`

### 2.3 Card template (shared with other rails)

- **File:** `web/themes/custom/myeventlane_theme/templates/components/event-card/mel-event-card.html.twig`
- **View mode:** `compact_commerce` → poster layout via `EventCardViewModel`
- **No Community Favourites–specific variant** in Twig; cards are visually identical to Discover/Tonight cards at the node layer

### 2.4 SCSS ownership split

| File | Rules | Impact |
|---|---|---|
| `_front-page.scss` | `.mel-section--community-favourites .mel-grid.mel-grid--events` (4-col responsive) | **Does not apply** — block does not emit `.mel-grid--events` |
| `_event-cards-festival.scss` | `.mel-popular-events-block__grid` (1-col mobile, 2-col `md`) | **Actual grid** — denser on desktop than intended 4-col homepage rails |
| `_front-page.scss` | `.mel-section--community-favourites` spacing, title colour | Section chrome only |

---

## 3. Screenshots referenced

Pre-change homepage captures live under `docs/audits/brand-rollout/before/`:

| Screenshot | Relevance |
|---|---|
| `Screenshot 2026-06-14 at 1.23.12 pm.png` | Full homepage scroll — rail density comparison |
| `Screenshot 2026-06-14 at 1.23.18 pm.png` | Discovery rails mid-page |
| `Screenshot 2026-06-14 at 1.23.24 pm.png` | Card grid alignment |

*Note: Phase 9 reorders Community Spotlight and increases rail counts; screenshot paths are retained as baseline references. Post-implementation QA should add dated captures to the same folder.*

---

## 4. Causes of poor visual hierarchy

1. **Grid system bypass** — Block emits `.mel-popular-events-block__grid` instead of `.mel-grid.mel-grid--events`, so homepage 4-column discovery grid rules in `_front-page.scss` never apply. Desktop shows a 2-column layout while neighbouring rails show 4 columns.

2. **Social proof outside card chrome** — “X going” is a sibling element (`.mel-popular-event__going`) below the card, not integrated into `mel-event-card` badge or meta row. This breaks vertical rhythm and card height alignment in the grid.

3. **Dual title risk (latent)** — Block supports an internal `h2.mel-popular-events-block__title`; block config sets `title: ''`. If an editor enables a block title in admin, the section would show duplicate headings (shell + block).

4. **No skeleton / empty parity** — View rails use `mel-event-skeleton-block.html.twig` (Tonight) or governed empty states; the block returns empty markup silently. Visibility gating prevents empty shells today, but the inner UX patterns differ.

5. **Render-array nesting** — Each card is wrapped in `.mel-popular-event` with `data-nid` / `data-score` attributes. Other rails output flat card nodes inside the grid. Extra wrapper depth can affect flex/grid stretch behaviour.

---

## 5. Reuse opportunities

| Opportunity | Approach | Risk |
|---|---|---|
| **Unify grid wrapper** | Change block list container class to `mel-event-grid mel-grid mel-grid--events` (match `views-view-unformatted--mel-home-events.html.twig`) | Low — SCSS already defines 4-col rules for `.mel-section--community-favourites .mel-grid.mel-grid--events` |
| **Move “going” into card VM** | Extend `EventCardViewModel` / `compact_commerce` preprocess with optional `card_going_count`; render in `mel-event-card.html.twig` meta row | Medium — must respect `myeventlane_event_should_show_block_going()` |
| **Twig theme hook** | Add `popular-events-block.html.twig` in theme; block returns `#theme => 'popular_events_block'` | Low — improves maintainability without changing data layer |
| **Views display (longer term)** | Custom Views style or `EntityField` row plugin backed by `PopularEventsService` | Higher — would align merchandising hooks but touches query layer (out of Phase 9 scope) |

**Do not reuse:** Community Spotlight editorial layout (`teaser_featured`, `mel-spotlight-rotator`) — different product intent and card variant.

---

## 6. Recommended future implementation

### Phase 10+ (presentation only — no scoring changes)

1. **Grid alignment (P0)**  
   - Update `PopularEventsBlock` list `#attributes['class']` to include `mel-grid mel-grid--events`.  
   - Remove or deprecate `.mel-popular-events-block__grid` rules once parity confirmed.  
   - Validate 8-card row at 1280px, 1024px, 640px, 390px.

2. **Social proof in card (P1)**  
   - Pass `going` count into card preprocess when source is `homepage_community_favourites`.  
   - Render as muted meta line inside `.mel-event-card__meta` (brand: calm, no FOMO).  
   - Drop sibling `.mel-popular-event__going`.

3. **Twig extraction (P2)**  
   - Introduce `templates/block/popular-events-block.html.twig` mirroring Views unformatted structure.  
   - Keeps PHP block focused on data; theme owns markup.

4. **Accessibility pass (P2)**  
   - Ensure grid uses same landmark structure as other rails (`mel-section__discover-view` child).  
   - Verify keyboard focus order with 8 cards.

### Explicitly out of scope

- `PopularEventsService` scoring weights  
- `HomepageMerchandising` dedup cascade  
- Browse route `/events/popular` ranking (separate governance track — see `docs/audits/brand-rollout/community-favourites-audit.md`)

---

## 7. Related audits

- `docs/audits/brand-rollout/homepage-rail-architecture-audit.md` — master ownership map  
- `docs/audits/brand-rollout/community-favourites-audit.md` — browse route / ranking governance  
- `docs/audits/community-favourites-ranking-ownership.md` — scoring ownership  
- `docs/audits/governance/community-favourites-governance.md` — product rules

---

## 8. Validation checklist (future redesign)

- [ ] Desktop: 4-column grid matches Tonight / Hidden Gems  
- [ ] Mobile: single column, no horizontal overflow  
- [ ] “X going” inside card meta, not below card  
- [ ] No duplicate section titles  
- [ ] `audit-homepage-gate.drush.php` — Community Favourites count > 0, no duplicate NIDs vs higher rails  
- [ ] `npm run mel:lint` / `npm run mel:build` if SCSS touched
