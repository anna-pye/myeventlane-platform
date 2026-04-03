# MEL Site Search Contract (`/search`)

**Status:** Code + config audit (repository snapshot).  
**Primary implementation:** `web/modules/custom/myeventlane_search/src/Controller/SearchController.php`  
**Index:** `config/sync/search_api.index.mel_content.yml` (`mel_content`)

---

## Current behaviour

### Product intent (as implemented)

The controller docblock states:

> Events are exclusive when any match: only Events group is shown. Non-event groups (Vendors, Venues, Pages, Categories) are shown only when Events has zero results. Past events are never returned.

So the **UX contract** is:

1. **If at least one upcoming event** appears in the **Events** group → show **only** the Events group from the main content index path (pages and venue-style lines from `mel_content` are cleared; vendors/categories still follow separate rules below).
2. **If no events** in that group → show **Pages / Blog** (actually only `article` + `page` bundles), **Venues** (derived from a second query on events + venue fields), **Vendors** (`mel_vendors`), and **Categories** (`mel_categories`).
3. **Past events** are excluded by query: upcoming only via `field_event_start >= now` for event branch of the OR.

### Event-first logic (step-by-step)

**Source:** `SearchController::build()` and `runContentQuery()`.

| Step | Behaviour |
|------|-----------|
| Empty query | Empty groups; `#empty` TRUE. |
| Main content query | `runContentQuery($mel_content, $q)` with fulltext on `title`, `field_venue_name`, `field_venue_address`, `body`, `rendered_item`. |
| OR filter | `(type ≠ 'event') OR (field_event_start >= now)` — includes **all non-event bundles in the index** that are published/indexed, plus **upcoming** events. |
| Result cap | `range(0, LIMIT_PER_GROUP * 3)` → **15** hits from Search API (mixed relevance order). |
| Split | Walk results: `event` → **events** list; `article` or `page` → **pages** list; **other bundles ignored** (see edge cases). |
| Slice | Each list capped at **5** (`LIMIT_PER_GROUP`). |
| If `count(events) >= 1` | Attach **`event_card`** render to each event row; set `pages` and `venues` (from first block) to **[]**; **do not** run venue list from the first pass (venues cleared). |
| If `count(events) < 1` | Use `pages` from split; build **venues** via `buildVenueItems()` (events only, venue fields, upcoming). |
| Vendors | `runVendorsQuery` only if **`count(events) < 1`**. |
| Categories | `runCategoriesQuery` only if **`count(events) < 1`**. |

**Ordering note:** “Events first” applies to **which groups are shown**, not to “events always rank above pages in the raw Search API list.” The API returns a **single blended** ranking; the controller **partitions** hits into events vs pages.

### Test scenarios (expected behaviour)

| Query (illustrative) | What typically happens | Matches contract? |
|----------------------|-------------------------|-------------------|
| **“music”** | If upcoming events match title/body/teaser/venue → **Events** group only (up to 5). If no event hits → pages, venues, vendors, categories as available. | **Yes** for grouping; **risk** if events exist but rank outside top 15 mixed hits (see edge cases). |
| **“free”** | Same; depends on “free” in indexed fields. RSVP/free is **not** a dedicated indexed facet on `/search`. | **Partial** — discovery intent for “free” depends on copy in body/title, not `field_event_type`. |
| **Venue name** | Venue strings are in **fulltext fields**; events at that venue can surface. If ≥1 event → events-only UI. Venue group uses **separate** query when no events. | **Yes** when events match; venue name search is explicitly supported in comments. |
| **Category name** | Event **teaser** is in `rendered_item` (indexed for anonymous teaser); category labels may appear there → can match **events**. If no events, **Categories** group from `mel_categories` may list the term. | **Yes** with nuance: category match may hit events via teaser or categories index on fallback. |

---

## Edge cases

### 1. `help_article` and `help_landing_page` in `mel_content` but not in UI

Indexed bundles include `help_article` and `help_landing_page`, and they satisfy `(type ≠ 'event')` so they **can appear in Search API results** for the main query.

The controller **only** pushes **`article`** and **`page`** into the pages list. **`help_article`** and **`help_landing_page`** hits are **skipped** in the `foreach` — they **never appear** on `/search`.

**Impact:** They still **consume slots** in the top **15** results. Highly relevant help or landing pages can **push events out** of the window, yielding **fewer than five** (or **zero**) events even when more upcoming events match the query later in the index. This **weakens** strict “event discovery wins” in ranking terms.

### 2. Ranking vs grouping

Contract is **“any event in the extracted events list → suppress secondary groups.”** It is **not** “search API must rank every matching event above every non-event.”

### 3. Vendors and categories when events show

When `events >= 1`, **vendors** and **categories** are **not** populated (`count(events) < 1` guard). So **event-exclusive** mode hides **all** non-event groups from the secondary indexes too, not only pages/venues from the first query.

### 4. “See more” links

Template sends users to `view.upcoming_events.page_events` with `q` for events and categories when a group has ≥5 items; other groups link back to `/search`. Behaviour is **intentional** but worth QA for query passthrough on the events view.

### 5. Database backend and boosts

Code comments note the DB backend may not apply per-field weights as documented in config; treat **boost numbers as intent**, validate in staging.

---

## Risks

| Risk | Severity | Notes |
|------|----------|--------|
| **Silent help/landing hits eating top-N** | **High** | Help bundles in same query, not displayed, can displace events from the 15-hit window. |
| **Generic queries** | **Medium** | Dense help or static page body text could outrank thin event titles in blended relevance (field boosts + fulltext overlap). |
| **No `field_event_type` / price in query** | **Medium** | “Free” / paid intent not first-class on `/search`. |
| **Stale index** | **Low** | Standard Search API operational risk. |

---

## Relevance audit (`mel_content`)

### Indexed text fields and boosts (config)

| Field | Boost (config) | In `/search` main fulltext set? |
|-------|----------------|----------------------------------|
| `title` | **8.0** | Yes |
| `body` | **2.0** | Yes |
| `field_help_summary` | **5.0** | **No** (not in `CONTENT_MAIN_FULLTEXT_FIELDS`) |
| `field_help_keywords` | **5.0** | **No** |
| `field_venue_name` | *(none in field_settings)* | Yes |
| `field_venue_address` | *(none in field_settings)* | Yes |
| `rendered_item` | *(no field-level boost in snippet)* | Yes; html_filter applies to multiple fields including `title` |

**Processors (selected):**

- **`html_filter`:** strips/boosts HTML tags on `body`, `field_venue_address`, `field_venue_name`, `rendered_item`, `title` (e.g. `h1` weight 5.0 inside processor).
- **`content_access`**, **`entity_status`**, **`ignorecase`**, **`add_url`**, etc. — standard pipeline.

### Event vs help in practice

- **Shared query fields:** Events and help both contribute to **`title`** (highest configured boost), **`body`**, **`rendered_item`** (teaser for article/event/page per index config — **help uses teaser** in `rendered_item` config for `entity:node` types listed).
- **Help-only high boosts** (`field_help_summary`, `field_help_keywords`) **do not** participate in `/search` because **`SearchController` does not add them** to `setFulltextFields()`. So **on this page**, help does **not** get those 5.0 boosts.
- **Residual competition:** Help still competes on **title + body + rendered_item** with events on the same fields. A long help body or keyword-heavy teaser could still score strongly.

### Answers (audit questions)

1. **Do help fields have higher boosts than event fields in the index config?**  
   **Yes** for `field_help_summary` / `field_help_keywords` vs plain `body` (2.0) — **but** those help fields are **excluded** from the `/search` fulltext field list, so they **do not** directly affect `/search` ranking.

2. **Could help articles outrank events for generic queries?**  
   **Yes, in principle**, via **title/body/rendered_item** in the **shared** 15-hit window, and **help hits can block slots** without being shown (see edge case).

3. **Are field weights balanced for “discovery first”?**  
   **Partially.** UX is **event-first by group suppression**, not by a dedicated event-only index or query. **Recommendation (no code):** consider excluding `help_article` / `help_landing_page` from this query via Search API filter, **or** a separate `mel_events` index for the main discovery query, **or** raising event-only recall (larger range + filter) after product review.

---

## Autocomplete behaviour

**Source:** `web/modules/custom/myeventlane_search/src/Controller/SearchAutocompleteController.php`

### Current behaviour

| Source | Conditions | Fulltext fields | Max | Output shape |
|--------|------------|-----------------|-----|--------------|
| Events | `type = event`, `field_event_start >= now` | **`title` only** | 5 | `{ type: event, label, value }` |
| Venues | Same event filters | `field_venue_name`, `field_venue_address` | 20 scanned → **5 unique** venue names | `{ type: venue, label, value }` |
| Categories | `mel_categories` index | `name` | 5 | `{ type: category, label, value }` |

- **Not included:** vendors, pages, blog, help (matches file docblock).
- **Order:** Events, then venues, then categories (concatenated arrays).

### Issues / noise

| Topic | Assessment |
|-------|------------|
| **Categories** | Always queried when index exists — **not** suppressed when many event matches (unlike full `/search` page). Can intermix with event/venue rows. |
| **Venues** | Dedup by **venue name** only; first five unique names from up to 20 hits — **useful** for location hints; partial address matches may pull odd rows. |
| **Events** | Title-only — **low noise**, may **miss** events that match only body/venue. |
| **Vendors** | Omitted by design — users typing organiser names get **no** vendor autocomplete (consistent with “lightweight” scope). |

### Recommendations (no code)

1. Document that autocomplete is **discovery-oriented** (events + places + categories), not site-wide search.  
2. If category suggestions feel noisy, consider **capping** or **ordering** (e.g. events first, categories only if `<3` event hits).  
3. Align product expectation: **“free”** or **help** intents are **out of scope** for this endpoint.

---

## Future index improvements (backlog only)

Fields below are **not** requested as implementation in this audit; **priority** is for roadmap planning. Verify field machine names on `event` (and related entities) before any change.

| Item | Priority | Rationale |
|------|----------|-----------|
| **`field_event_type`** (RSVP / paid / both / external) | **High** | Enables “free”, RSVP, and paid intent filters and better ranking signals for discovery. |
| **Price signal** (lowest ticket / free flag from product model) | **High** | “Cheap” / “free” queries; may require aggregated or denormalised field for Search API. |
| **Geo** (`field_location_*`, lat/long, or normalised “suburb/city”) | **High** | Location-first discovery; combine with map UX later. |
| **Vendor / store** (`field_event_vendor`, `field_event_store`, or Commerce store ref) | **Medium** | “Events at this organiser” / store-scoped search. |
| **Accessibility** (`field_accessibility` or summary facet) | **Medium** | Inclusion-focused filters; may be list or text — define facet vs fulltext. |
| **Capacity / remaining tickets** | **Low** | Dynamic; often better outside index (API) unless snapshot acceptable. |
| **Series / template flags** | **Low** | Niche filtering. |

**Cross-cutting:** exclude or split **help** bundles from **event discovery** queries to avoid silent competition (ties to relevance audit).

---

## UI consistency

### Search events group

- **Controller:** `SearchController::build()` uses `getViewBuilder('node')->view($node, 'event_card')` for each event in the events group — **explicitly** commented as same as discovery.  
- **Listing reference:** `config/sync/views.view.upcoming_events.yml` uses **`view_mode: event_card`** on event row displays (multiple displays).

### Template chain

- **Search results wrapper:** `web/modules/custom/myeventlane_search/templates/myeventlane-search-results.html.twig` — events group renders `item.rendered` inside `mel-event-grid`.  
- **View mode display:** `config/sync/core.entity_view_display.node.event.event_card.yml` defines **`event_card`** field layout (image, dates, venue, category, ticket/accessibility-related components per config).  
- **Theme:** `web/themes/custom/myeventlane_theme/templates/node--event--event-card.html.twig` (and related partials) implement the card.

### Comparison to `/events`

- **Same view mode:** **`event_card`** — aligns with `upcoming_events` view.  
- **Not using** `event_card_compact` / `event_card_poster` on search — those are used elsewhere (e.g. home sections); **search matches main listing card**, which is appropriate.

### Title / date / location / price / CTA

- **Controlled by** `event_card` display config — not duplicated in `SearchController`. Any drift between `/events` and `/search` event cards is a **single display config** issue, not a search-specific teaser vs card split.  
- **Legacy note:** `web/themes/custom/myeventlane_theme/templates/search/search-result.html.twig` builds a card from `mel_event_card` variables — that path is for **Drupal core search**, not the custom `myeventlane_search_results` theme used by `SearchController`. **Custom `/search` uses `event_card` render arrays directly** — **no** “teaser vs card” inconsistency on the custom results template for events.

### Success criteria check

- **Search event results** use **`event_card`** — **yes**, consistent with primary events listing view mode.  
- **Non-event groups** use simple **title + link** lists — intentional; not comparable to event cards.

---

## Document history

| Section | Source |
|---------|--------|
| Full contract + sections | Prompts 81001–81006 |
