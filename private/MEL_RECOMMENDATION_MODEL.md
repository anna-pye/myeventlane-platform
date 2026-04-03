# MEL Recommendation Model

**Status:** Architecture + product documentation (no ML, no personalization).  
**Constraints:** Deterministic, explainable signals; reuse `PopularEventsService` and Views; no new scoring systems beyond what this doc canonises.

---

## Canonical signals

| Service / artifact | Role | Canonical? |
|-------------------|------|------------|
| **`PopularEventsService`** (`myeventlane_analytics.popular_events`) | **Primary engagement ranker** for “what’s hot” in a time window | **Yes** — single source for ticket/RSVP-based popularity |
| **`TrendingCategoriesService`** | Ranks **taxonomy categories** by summing per-event scores drawn from popular events in a pool | **Yes** — derivative of `PopularEventsService`, not a second formula |
| **`PopularEventsBlock`** | Renders events using `PopularEventsService` + configurable view mode | **Yes** — UI surface for canonical popularity |
| **`TrendingCategoriesBlock`**, **`TrendingInCategoryBlock`** | Surfaces category trending / in-category lists using `TrendingCategoriesService` | **Yes** — same signal stack |
| **`TrendingScoreService`** | Per-event score: `(recent_RSVPs × 2) + (boost ? 10 : 0)` from `myeventlane_rsvp` + `BoostManager` | **No** — **legacy / non-canonical** |
| **`TrendingEventsController`** | Loads upcoming events, scores with `TrendingScoreService`, sorts | **No** — **deprecated** in code; not registered in routing |
| **Views** (`mel_home_events`, `front_*`, `upcoming_events`, etc.) | Chronological, editorial (`field_promoted`), or filter-based rails | **Yes** — canonical for **non-engagement** ordering |
| **`ConversionAnalyticsService`**, **`AnalyticsDataService`**, vendor dashboards | Funnels, reports, PDFs | **Out of scope** for public recommendations (analytics only) |
| **`EventSuggestionService`** (event wizard) | Rule-based + optional AI **organiser** hints | **Out of scope** for attendee-facing recommendations |

---

## Data inputs

### `PopularEventsService` (locked spec, from class docblock)

| Input | Source | Use |
|-------|--------|-----|
| **Tickets sold** | Commerce `order_item` + `field_target_event`, last *N* days (`order_item.created`), **excludes** boost line items via `OrderItemClassifier` | Weight **×3** in score |
| **RSVPs** | `rsvp_submission` entity storage (canonical RSVP tables), last *N* days | Weight **×1** in score |
| **Published events only** | `node_field_data.status = 1` | Hard filter |
| **Past events** | `field_event_start` vs request time | **Not hidden**; **deprioritised** in sort (upcoming first, then score) |

**Formula:** `score = (tickets_sold × 3) + (rsvps × 1)`  
**Transparency fields per row:** `nid`, `score`, `tickets_sold`, `rsvps`, `going` (= tickets + rsvps), `is_past`.

### `TrendingCategoriesService`

| Input | Source |
|-------|--------|
| Popular event rows | `PopularEventsService::getPopularEventIds()` (bounded pool, default scan 60) |
| Category | `field_category` → vocabulary `categories` |
| Aggregation | Sum event scores and “going” per category; rank categories |

### `TrendingScoreService` (non-canonical)

| Input | Notes |
|-------|--------|
| Count from `myeventlane_rsvp` | Different storage path than `PopularEventsService` RSVP aggregation |
| Boost flag | +10 if `BoostManager::isBoosted()` |

**Do not use** for new product surfaces; align or remove in a cleanup sprint.

---

## Duplicate logic

| Issue | Detail |
|-------|--------|
| **Two “trending” concepts** | **Popular** path: Commerce + RSVP submission entities, fixed weights, no boost in score. **TrendingScore** path: `myeventlane_rsvp` counts ×2 + boost bonus. They measure **different things** and can **disagree**. |
| **Deprecated controller** | `TrendingEventsController` documents preference for Views (`mel_home_events`, front rails). |
| **“Recommended” View sort** | `front_recommended_events` sorts by **`field_promoted` DESC** then start — similar **ordering** to featured, but **without** requiring `promoted = 1`, so it surfaces **all** upcoming with editorial bump — overlaps **semantically** with both featured and generic upcoming lists. |

**Decision — one canonical signal layer**

1. **Engagement-based ranking (attendee-facing “popular / trending”):** **`PopularEventsService` only** (+ `TrendingCategoriesService` for category rollups).  
2. **Chronological / editorial rails:** **Views** (`field_event_start`, `field_promoted`, filters such as tonight / RSVP type).  
3. **`TrendingScoreService`:** **Not part of the canonical layer** — treat as technical debt until removed or aligned to `PopularEventsService` (single formula, single RSVP source).  
4. **No new scoring systems** without replacing or explicitly deprecating the above in writing.

### Popular vs trending categories (locked — use both)

**They do not conflict** because they solve different jobs:

| Surface | Role | Canonical backing |
|---------|------|-------------------|
| **Popular this week** | **Event-level rail** — ranked list of events by engagement | `PopularEventsService` |
| **Trending categories** | **Discovery navigation** — which categories are “hot” right now; drives pills, strips, or “explore” links | `TrendingCategoriesService` (same engagement data, aggregated by taxonomy) |

**Recommendation:** Ship **both** on the homepage where layout allows: e.g. horizontal **category trending** near the category strip, and a **Popular this week** row of cards. Neither replaces the other.

---

## Related events (v1)

### Goal

Deterministic “more events like this one” without ML.

### Inputs (confirmed on `event` bundle)

- **`field_category`** — taxonomy `categories` (`field_category_target_id` in Views).
- **`field_event_start`** — upcoming filter.
- **Current node id** — exclude from results.

### Rules

1. **Same category** as the current event (primary `field_category` term; if multi-value, pick **primary** term or **first** delta — **product choice** at implementation time; document in UI).  
2. **Upcoming only:** `field_event_start >= now` (same as discovery).  
3. **Exclude current event:** `nid != current_nid`.  
4. **Sort (v1):**  
   - **Default:** `field_event_start` **ASC** (soonest first) — predictable, matches listing behaviour.  
   - **Optional variant:** merge **popularity** by loading NIDs from `PopularEventsService::getPopularEventIds(7, K)` and **boost** those nids **within** the same-category set (stable secondary sort) — still deterministic, **no new score formula**; reuses canonical service.

### Implementation approach

- **Preferred:** Reuse **`views.view.upcoming_events`** — it already exposes **`field_category_target_id`** as a **contextual filter** (`taxonomy_index_tid`, default “ignore” when absent).  
- **Pattern:** On the event node page, embed a view (block or `views_embed_view`) with **display** that matches discovery (`event_card`, upcoming filters) and pass **argument** = current event’s category tid.  
- **Lightweight variant:** If a dedicated “related” display is clearer, **duplicate** the upcoming_events query config as `upcoming_events` display `related_by_category` — still one View, no new PHP query layer.  
- **Do not** introduce a parallel entity_query for v1 unless Views embedding is blocked (access, caching).

---

## Related events (v1.5) — same organiser

**Goal:** High-value, low-effort related strip without new scoring.

### Rules

1. **Same vendor / organiser** as the current event — filter on `field_event_vendor` (or the canonical store/vendor reference on `event`; confirm machine name in config before build).  
2. **Upcoming only:** `field_event_start >= now`.  
3. **Exclude current event:** `nid != current_nid`.  
4. **Sort:** `field_event_start` **ASC** (same as category related).

### Implementation

- **Preferred:** New **`upcoming_events`** display (or contextual filter) keyed on **vendor target id**, mirroring the category pattern — **one View**, no new service.  
- **Label:** **“More from this organiser”** (use real vendor display name in heading where possible).

**Schedule:** **v1.5** if v1 scope is tight; **not** a blocker for category related in Phase 2.

---

## Homepage Rails (FINAL)

**Status:** Locked for Sprint 83003 finalisation — no duplicate broad lists, no “recommended” rail, no misleading geo copy.

**Live template:** `web/themes/custom/myeventlane_theme/templates/page--front.html.twig` (extends `page.html.twig`). **Block config:** `config/sync/block.block.*.yml`.

**Drupal gotcha (fixed in theme):** If `system.site` `page.front` points at a **node** (e.g. `/home`), core adds `page__node__…` theme suggestions that beat `page__front`, so the homepage never used `page--front.html.twig`. **`myeventlane_theme_theme_suggestions_page_alter()`** removes `page__node*` suggestions on the front page and forces **`page__front`** so the MEL layout always applies.

### What users see (event rails + navigation)

| # | User-facing label | Type |
|---|-------------------|------|
| — | *(Hero — not a rail)* | Marketing / search entry |
| 0 | **Pick a category** | Navigation (taxonomy strip / pills — **not** an event ranking rail) |
| 1 | **Featured** | Curated / promoted carousel (`home_featured` region) |
| 2 | **Discover events** | **Only** broad chronological upcoming list (`mel_home_events` **discover**) — there is **no** separate “Upcoming” rail |
| 3 | **Tonight** | Time-urgency window (`mel_home_events` **tonight**) |
| 4 | **Free & RSVP** | RSVP / free-type filter (`mel_home_events` **under_20**); honest label vs query |
| 5 | **Popular this week** | Engagement (`PopularEventsBlock` → `PopularEventsService`; section omitted if empty) |
| — | Create event CTA | Static |
| * | `page.content` | Only if editors place blocks in **Content** — keep empty on default homepage to avoid stray duplicates |

**Canonical journey:** curated → broad discovery → urgent (tonight) → accessible (free/RSVP) → social proof (popular).

**Rail headings (exact strings in Twig):** `Featured`, `Discover events`, `Tonight`, `Free & RSVP`, `Popular this week`. Rejected on homepage: “Recommended”, “for you”, “Near you” (without geo), combined “discover recommended” naming.

**Secondary link copy:** All primary rails use **`See all`** (`|t`) for the listing link (Featured component + `mel-section-head` rows) for visual consistency.

### Removed or disabled — why

| Item | Why |
|------|-----|
| **`home_discover` View block** | Same output as Twig `drupal_view('mel_home_events','discover')` — **disabled** (`status: false`). |
| **`front_recommended_events` block** | Overlaps Featured + broad discovery; “recommended” label without personalisation — **disabled**. |
| **`mel_home_events` → `near_you`** | Non-geo sort — **removed** from template (not renamed). |
| **Second `page--front` template** | Removed; single source of truth. |
| **`featured_discover_recommended`** | **No block placement**; view name mixes intents — **do not** surface on homepage until product re-specs it. |
| **`front_discover_events` / `front_featured_events` blocks** | **Disabled**; homepage uses `mel_home_events` discover + `home_featured` carousel instead. |

### Visual / layout notes

- **Spacing:** Sections use existing `.mel-home__*` and `.mel-container` patterns from `_homepage.scss`.  
- **Headings:** Discover / Tonight / Free / Popular share **`mel-section-head`** + **`mel-section-head__title`** + **`mel-link`**. **Featured** uses **`featured-events`** (curator line + carousel) — deliberate editorial treatment, same vertical rhythm as other sections.  
- **Grids:** Sticker wall (discover), horizontal scroller rows (tonight / free), **`mel-event-grid`** inside Popular block — each tuned in SCSS; no extra rails added in 83003.

---

## Homepage rails audit (reference)

**Evidence:** same template as **Homepage Rails (FINAL)** above, plus `config/sync/views.view.*.yml`.

| Block / View (display) | Logic (summary) | Purpose (intent) | Overlap |
|------------------------|-----------------|------------------|---------|
| **`mel_home_events` → `discover`** | Published events, `field_event_start >= now`, sort start ASC, poster / card modes per display | “Discover events” sticker wall | Was **high** with `front_discover_events` — homepage uses **one** embed only |
| **`mel_home_events` → `tonight`** | Start **between** `now` and `tomorrow`, sort start ASC | Same-day / tonight | Distinct time window |
| **`mel_home_events` → `under_20`** | Upcoming + `field_event_type` in **rsvp / both** — **not** a Commerce price filter | **Label: Free & RSVP** | Honest copy (see **Label decisions**) |
| **`mel_home_events` → `near_you`** | Upcoming, sort **`created` DESC** — **not** geolocation | Was “Near you” | **Removed** from homepage template |
| **`front_discover_events` → `block_discover`** | Legacy discover | — | Not placed on homepage |
| **`front_featured_events` → `block_featured`** | **`field_promoted = 1`**, upcoming | Editorial featured | Not the active homepage featured block |
| **`front_recommended_events` → `block_1`** | Sort **`field_promoted` DESC** then start | “Recommended” | **Disabled** — overlaps featured + upcoming |
| **`featured_discover_recommended`** | Mixed naming / weak default filters | — | **No** homepage placement |

**Category strip:** `myeventlane_event_categories_strip` / `myeventlane_category_pills` — taxonomy navigation, not event ranking.

**`PopularEventsBlock`:** Embedded in `page--front.html.twig` when the popular service returns rows (section omitted if empty).

### Label decisions (trust)

| Issue | **Decision** |
|-------|----------------|
| **`under_20` display** | **Shipped:** User-facing copy **“Free & RSVP”** — matches `field_event_type` ∈ {rsvp, both}. **Option B (later):** real price filter before “Under $20” style labels. |
| **`near_you` display** | **Homepage:** rail **removed**. Reintroduce only as **“Recently added”** (or similar) if the sort stays `created` DESC — **never** “Near you” without geo. |

---

## Homepage rails (v1) — target layout

Product simplification **on top of** the audit. Implementation is phased (see Rollout).

| Rail | Purpose | Data source |
|------|---------|-------------|
| **Featured** | Curated / commercial spotlight | View filter **`field_promoted`** = true + upcoming (`front_featured_events` or single display) |
| **Upcoming / Discover** | Broad discovery, chronological | **`mel_home_events` discover** (or consolidate with `front_discover_events` — **one** view only) |
| **This weekend / Tonight** | Time-bounded discovery | **`mel_home_events` tonight** and/or dedicated **weekend** display on `upcoming_events` if URL parity matters |
| **Free & RSVP** | RSVP / free-leaning events (honest label) | **`mel_home_events` under_20`** query unchanged until **Option B** price filter ships |
| **Popular this week** | Event-level engagement rail | **`PopularEventsBlock`** → `PopularEventsService` |
| **Trending categories** | Navigation / discovery strip (which categories are hot) | **`TrendingCategoriesBlock`** (or strip) → `TrendingCategoriesService` — **both** with Popular when layout allows (see **Popular vs trending categories** above) |
| **Recently added** *(optional)* | Newest upcoming listings | **Only** if **`near_you`** is kept — relabelled; sort `created` DESC; **not** named “Near you” |

**Remove / defer**

- **Duplicate discover Views:** retire **`front_discover_events`** from placement if **`mel_home_events` discover** is the single source.  
- **`featured_discover_recommended`** as a homepage rail — **defer** until filters and naming are fixed (upcoming + clear intent).  
- **`front_recommended_events`** — rename or replace: either **merge** into “Featured + rest by date” **or** drop if redundant with Featured + Upcoming.  
- **`near_you` under old name** — **do not ship** “Near you”; **remove** or **rename** to **“Recently added”** (Phase 1).

---

## Placement rules

### Allowed

| Placement | Use |
|-----------|-----|
| **Homepage** | Featured, chronological rails, **Popular** event rail **and** **trending categories** navigation (distinct roles), category strip |
| **Event detail (canonical)** | **Related:** same category (v1) + **same organiser** (v1.5); optional “Popular this week” if event appears in top list (same `PopularEventsService`, explainable) |
| **Empty states** | Links to `/events`, category URLs, **Featured**/**Free** landing views — **deterministic** suggestions only |
| **Discovery listings** | `/events`, category pages — existing Views |

### Not allowed (without new product sign-off)

| Placement | Reason |
|-----------|--------|
| **Every page** | Noise; erodes trust |
| **Checkout / payment / account security flows** | Distraction and conversion risk |
| **Admin / vendor dashboards** (default) | Use **operational** analytics, not attendee recommendation widgets; vendor “insights” are a **separate** product |
| **Help / support flows** | Keep help retrieval separate (`MEL_HELP_RETRIEVAL_POLICY.md`) |
| **“Recommended for you” with no mapped logic** | Forbidden label class (see below) |

### Change-control rule (no feature creep)

**No new recommendation rail or user-facing label** may ship without **all** of:

1. **Defined data source** (which field, index, or service — e.g. `PopularEventsService`, View ID, taxonomy).  
2. **Defined query** (filters, sort, limit — reproducible in Views UI or documented SQL/API).  
3. **Defined explanation label** (copy that matches the query; glossary entry if non-obvious).

If any item is missing, the rail is **out of scope** until the doc is updated.

---

## Recommendation labels

Each **user-visible** label must map to **inspectable** logic (query + sort + filters). No fake personalization.

| Label | Mapped logic | OK? |
|-------|----------------|-----|
| **Popular this week** | `PopularEventsService` (7-day window, tickets×3 + RSVPs×1), optional “X going” | **Yes** |
| **Trending in [Category]** | `TrendingCategoriesService` / `TrendingInCategoryBlock` — engagement in category | **Yes** — use **real category name** in copy |
| **More in [Music]** (example) | Same category as current event, upcoming, exclude nid — `upcoming_events` contextual filter | **Yes** |
| **More from this organiser** | Same `field_event_vendor` (or canonical ref) + upcoming + exclude nid + sort start ASC — **v1.5** View pattern | **Yes** — see **Related events (v1.5)** |
| **Featured** | `field_promoted` + upcoming | **Yes** |
| **Tonight** / **This weekend** | Time-window filters on `field_event_start` | **Yes** |
| **Free & RSVP** | `field_event_type` ∈ rsvp/both — **canonical label** for current `under_20` display until real price filter exists | **Yes** |
| **Under $20** (future) | Requires **Option B** Commerce/price filter — do not use until implemented | **Pending** |
| **“Recommended for you”** | Implies personalization | **No** — do not use |
| **“Trending now”** | If used, **define** as either **same as Popular** (rename) or **category trending** — avoid ambiguous middle ground | **Use only** with glossary = canonical service |

---

## Rollout plan

### Phase 1 — Homepage rails cleanup

**Status (83003):** Implemented — see **Homepage Rails (FINAL)** above.

- **One discover source:** `mel_home_events` discover (Twig embed); duplicate `home_discover` block **disabled**.  
- **`near_you`:** **Removed** from homepage template (not relabelled).  
- **`under_20` rail:** Label **“Free & RSVP”**; “More” → `/events/free`.  
- **`PopularEventsBlock`:** On homepage when non-empty; category strip unchanged.  
- **`featured_discover_recommended`:** Still no placement; **`front_recommended_events`** block **disabled**.  
- **Trending categories** as an extra homepage row: **not** added in 83003 (no new rails).

### Phase 2 — Event detail “more in category”

- Embed **`upcoming_events`** (or sibling display) with **category contextual argument** + exclude current nid.  
- Section title: **“More in [Category name]”** from term label.

### Phase 2.5 — Event detail “more from this organiser” (v1.5)

- View display or contextual filter on **vendor** + upcoming + exclude current; label **“More from this organiser”**.

### Phase 3 — Refine from usage

- Monitor empty rails, label confusion, and **`PopularEventsService`** fallbacks (missing Commerce tables).  
- Optionally align **`TrendingScoreService`** with canonical layer **or** delete unused code paths.  
- **Option B:** real **under $20** (or price) filter when index/Commerce supports it — then update labels (`MEL_SEARCH_CONTRACT.md` backlog alignment).

---

## Cross-references

- Search / discovery overlap: `private/MEL_SEARCH_CONTRACT.md`  
- Help retrieval (separate system): `private/MEL_HELP_RETRIEVAL_POLICY.md`

---

## Document history

| Section | Prompt |
|---------|--------|
| Signals, inputs, duplicates, decision | 82001 |
| Related events v1 | 82002 |
| Homepage audit | 82003 |
| Homepage rails v1 | 82004 |
| Placement | 82005 |
| Labels | 82006 |
| Rollout | 82007 |
| Sprint 3 review refinements | Popular vs trending, Free/RSVP label, near_you, v1.5 organiser, change-control rule |
| **Homepage Rails (FINAL)** | Sprint **83003** finalise — single `page--front.html.twig`, category above Featured, one discover list, exact rail labels, duplicate blocks disabled, `near_you` removed, `See all` link consistency, doc lock |
