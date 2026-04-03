# MEL Discovery v1 — audit and register (2026-03-27)

Internal engineering register. Not customer-facing.

---

## 1. Discovery ownership register

| Route / surface | Owning View display | Data source |
|-----------------|---------------------|-------------|
| `/events` | `view.upcoming_events.page_events` | `node` (event), `node__field_event_start`, filters on published + start ≥ now |
| `/events/category/{term}` | `view.upcoming_events.page_category` | Same + contextual `field_category` (taxonomy) |
| `/events/today` | `view.upcoming_events.page_today` | Same + start between site-local `today` and `tomorrow` |
| `/events/this-weekend` | `view.upcoming_events.page_this_weekend` | Same + start between `saturday this week` and `sunday this week 23:59:59` (PHP strtotime, site TZ) |
| `/events/free` | `view.upcoming_events.page_free` | Same + `field_event_type` in {`rsvp`, `both`} (no Commerce price; see assumptions) |
| Sidebar / theme “upcoming” blocks | `view.upcoming_events.block_upcoming` | Same base filters, 4 items |
| Homepage discover (legacy block) | `view.front_discover_events` | Events, `event_card`, end ≥ now |
| Homepage MEL v2 rails | `view.mel_home_events` (discover, tonight, etc.) | Various row modes (`event_card`, `event_card_poster`, …) |
| `/search` | `mel_search.view` → `SearchController` | Search API `mel_content` (+ `mel_vendors`, `mel_categories` when no event hits) |
| Search autocomplete JSON | `SearchAutocompleteController` | `mel_content` (events, venues) + `mel_categories` |

**Deprecated / do not extend for discovery**

- `view.all_events` — description marked `[DEPRECATED]`; use `upcoming_events`.
- `TrendingEventsController` — no route registration; marked `@deprecated` in code.

**Taxonomy term canonical pages**

- `view.taxonomy_term` (core) — still used for `/taxonomy/term/{id}` if enabled; **not** the same as `/events/category/*`. Do not conflate.

---

## 2. Search contract v1 (`/search`)

1. **Indexes**: Primary content query uses Search API index **`mel_content`** (database backend). Vendors: `mel_vendors`. Categories: `mel_categories`.
2. **Event-first**: If the content query returns **at least one** upcoming **event** hit, the UI shows **only** the Events group. Pages, venue-derived rows, vendors, and categories are **suppressed** for that request.
3. **Upcoming events only**: Event rows require `field_event_start >= request time` (Unix timestamp comparison in the query OR-branch).
4. **Non-event content**: `article` and `page` bundles can appear in the content query when **no** event matched in the split logic; `help_article` and other bundles are not surfaced in the grouped UI (no branch adds them).
5. **Rendering**: Event hits are enriched with a full **node view mode `event_card`** render build (aligned with `/events`).
6. **Unpublished**: Relies on indexed content (processors / normal indexing of published nodes). No extra `status` condition in `SearchController` PHP; confirm index filters if tightening is required later.

---

## 3. Search API `mel_content` — event-related fields

**Present**: `title`, `body`, `rendered_item` (teaser render), `field_category`, `field_event_start`, `field_event_end`, `field_venue_name`, `field_venue_address`, `type`, `status`, …

**Documented gaps (do not add in this pass)**

- Geo / spatial
- Price / ticket amounts
- Vendor dimension as a dedicated searchable field

---

## 4. Autocomplete (stability)

- **Events**: `title` fulltext only; `type = event`; `field_event_start >= now`.
- **Venues**: `field_venue_name`, `field_venue_address`; deduped venue names.
- **Categories**: `mel_categories` terms (categories vocabulary).
- **Excluded by design**: pages, vendors, arbitrary entity types.

---

## 5. Venue / location rule (documentation)

- **Venue entity** (when used) is the long-term **canonical** location record.
- **Geo** on event is **future** proximity search (not in Search API v1).
- **Text fields** (`field_venue_name`, `field_venue_address`, legacy `field_location`) remain **legacy / display** inputs where present.

---

## 6. Help system — audience fields

- **Canonical filter field for Search + Assistant**: `field_audience` (indexed as “Help audience (canonical)” on `mel_content`).
- **`field_help_audience`**: Still on several bundles and in index; used historically / sync (see `HelpContentSeeder` comments). **Migration plan (do not run yet)**:
  1. Audit content: list nodes where `field_help_audience` differs from `field_audience`.
  2. Batch copy or map values → `field_audience` with revision safety.
  3. Update forms/displays to hide or make `field_help_audience` read-only.
  4. Remove `field_help_audience` from `mel_content` field_settings when no longer needed; reindex.
  5. Uninstall or repurpose storage after a release boundary.

---

## 7. Help retrieval — Assistant vs Vendor AI

| Aspect | Help Assistant (`HelpRetriever`) | Vendor AI (`HelpArticleRetriever`) |
|--------|----------------------------------|--------------------------------------|
| Engine | Search API `mel_content`, fulltext + relevance | Entity field query (`node`), **no** Search API |
| Ordering | `search_api_relevance` | `field_priority`, then title |
| Access | `field_audience` OR group (public / vendor); node access + AI allow-list | `field_audience` conditions; **`accessCheck(FALSE)`** on query — **risk**: must stay limited to trusted vendor-only code paths |
| Bundle lock | `help_article` | `help_article` |

**Risk**: Divergent ranking and coverage; Vendor path bypasses search index freshness and relevance tuning.

---

## 8. Config drift — `field_mel_register_id`

- **Expected in code**: `myeventlane_docs_importer` ships `field.storage.node.field_mel_register_id` and per-bundle field configs.
- **Observed**: No `field_mel_register_id` in **`config/sync`** at time of audit — **critical drift** if production relies on stable doc IDs. **Action**: export and commit field config, or confirm single-env install only.

---

## 9. Search upgrade trigger (Solr / OpenSearch)

Consider upgrading beyond DB backend when **any** holds:

- Order of **~10k+** active indexed events (or overall mel_content documents) with latency or timeout pain.
- Sustained **relevance** complaints after tuning boosts / fields.
- **Geo** or complex proximity requirements.

---

## 10. Discovery v1 acceptance checklist

- [ ] `/events`, `/events/category/…`, `/events/today`, `/events/this-weekend`, `/events/free` return 200 and paginate.
- [ ] Listing displays use **`event_card`** (and show image, title, when, location, price badge).
- [ ] No second parallel “all events” page built on `all_events` for marketing links.
- [ ] Search: event-first suppression behaves as contract v1.
- [ ] No unpublished leakage on indexed search (smoke-test).
- [ ] Mobile: one column ≤640px on `.mel-event-grid` (existing CSS).

---

## 11. Assumptions logged

- **`/events/free`**: There is no `field_is_free`. Filter uses **`field_event_type` ∈ {rsvp, both}** (matches `mel_home_events` “Free & Under $20” block pattern). Paid-only and external-link events are excluded even if a $0 offer exists in Commerce — **future** improvement would join ticket price without changing RSVP/ticket **logic**.

- **Weekend window**: Uses **“saturday this week” … “sunday this week 23:59:59”** so Saturday/Sunday visitors still see the current weekend; Monday rolls forward to the new ISO week (PHP behaviour).

---

## 12. Relevance sanity tests (manual)

Run on a staging DB with representative content:

| Query | Expectation |
|-------|-------------|
| `music` | Events with title/body/rendered/category match rank in Events group first when hits exist |
| `free` | Upcoming events with “free” in text; `/events/free` lists RSVP/Both types |
| Known venue name | Events at venue via `field_venue_name` / `field_venue_address` |
| Category name | Category group or event matches via taxonomy / rendered teaser |

(No automated run in this repo pass — requires live index + data.)

---

## 13. Geo readiness (no geo search yet)

**Objective:** Document how MEL can support radius search, maps, and “near me” later without implementing them in Sprint 4.

### Current state

- **Field:** `field_event_geo` exists on event nodes (`geofield`, cardinality 1). Storage label describes a canonical **POINT** for the event.
- **Format:** Geofield values are stored as **WKT** (e.g. `POINT (lng lat)`); lat/lng are derived in code (see `EventGeoResolver::resolveFromEventGeo()` in `myeventlane_event`).
- **Read-path resolution:** Theme preprocess uses service **`myeventlane_event.geo_resolver`** (`EventGeoResolver`) to set `mel_lat`, `mel_lng`, and `mel_geo_source` on event pages. Precedence is documented in that class: `field_event_geo` → legacy event lat/lng → `field_location`-derived coords → venue entity coords → none.
- **Map UI today:** Event full template can embed a **Google Maps search URL** from coordinates or address text when coords exist — this is **not** a native map product or geo search.

### Population / gaps

- **How often populated:** Not measured in this pass (requires DB/report). Any event without `field_event_geo` and without fallbacks resolved by `EventGeoResolver` will have `mel_geo_source === 'none'` and no coordinates for map/embed behaviour.
- **Fallback if empty:** Resolver walks legacy and address/venue sources; if all fail, lat/lng are NULL and map query falls back to **venue + address strings** where templates support it.
- **Search:** Search API v1 (`mel_content`) does **not** index geo for proximity queries (see §3 gaps). Discovery listings (`upcoming_events`) are **not** geo-filtered.

### What is needed later (radius, map, near me)

| Capability | Prerequisites |
|------------|----------------|
| **Radius / proximity search** | Stable population of `field_event_geo` (or indexed lat/lng), backend that supports spatial or bounding-box queries (e.g. Solr spatial, PostGIS, or dedicated geo index), and API/UI for centre point + radius. |
| **Map browse** | Same coordinate coverage; tile/map library; performance limits (clustering, bounds queries); accessibility for map + list dual mode. |
| **“Near me”** | Browser geolocation consent UX, secure HTTPS, centre point from device, same query stack as radius search; privacy policy and fallback when denied. |

**Explicit non-goals for this sprint:** No Search API geo conditions, no new routes, no map UI beyond existing embed, no user location storage.

---

## 14. Sprint 4 — minimal listing facets (Views)

- **`/events` (`view.upcoming_events.page_events`):** Exposed **taxonomy** filters on `field_category` (identifier `category`) and `field_accessibility` (identifier `accessibility`), inherited from the default display. **Date scope** is **not** an exposed date filter; UI uses **routes** `/events`, `/events/today`, `/events/this-weekend`, `/events/free` as pills.
- **Block display** `block_upcoming`: Same base filters but **not** exposed (sidebar block stays simple).
- **`page_category`:** Category comes from the **contextual** path argument only; exposed **accessibility** remains available.
- **`page_today` / `page_this_weekend` / `page_free`:** Same exposed category + accessibility as the main listing where filters are customised per display.

Cache contexts for exposed listings include **`url.query_args`** where relevant so facet query strings vary correctly.
