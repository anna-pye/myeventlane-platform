# MEL Search — Regression & QA Scenarios

**Purpose:** Repeatable checks for `/search` and related autocomplete.  
**Companion:** `private/MEL_SEARCH_CONTRACT.md`

**Execution:** Run in staging with known fixtures (or production with careful choice of terms). Record **Pass / Fail / Blocked** and notes.

---

## `/search` — grouped results

| Query | Expected result | Pass criteria |
|-------|-----------------|---------------|
| *(empty)* | Empty state copy; no groups. | No PHP errors; message invites user to enter a term. |
| **“music”** (or genre in event titles) | **If** ≥1 upcoming event matches → **Events** group only (cards), up to 5; vendors/categories/pages hidden. | Events shown as `event_card`; no vendor/category sections. |
| **“music”** when no event matches | **Pages/Blog** (article/page only), **Venues** (event-derived), **Vendors**, **Categories** as indexes allow. | No events group; at least one other group **or** global empty state. |
| **“free”** | Prefer upcoming events whose **title/body/teaser** mention free; no dedicated “free ticket” facet unless content says so. | Document actual outcome; flag gap if product expects RSVP filter. |
| **Venue name** (e.g. known `field_venue_name`) | Upcoming events at venue surface; with ≥1 event → events-only mode. | Event cards list includes expected venue; venue list may be suppressed when events show. |
| **Category name** | Events with category in **teaser/rendered** or title; else fallback to **Categories** group from `mel_categories`. | Behaviour matches contract doc; no crash. |
| **Random informational** (e.g. “refund policy”) | May match **help** in index but help **not** listed; may reduce event count in top 15; pages if article/page match. | Observe slot consumption; log if events missing despite matches in DB (index + 15-cap). |
| **Past event title** | **No** past events in results (date filter on event branch). | Confirm upcoming-only. |

---

## Autocomplete (`SearchAutocompleteController`)

| Query | Expected result | Pass criteria |
|-------|-----------------|---------------|
| **Short event title prefix** | Up to 5 **event** suggestions (`type: event`). | JSON array; labels match event titles. |
| **Venue substring** | Up to 5 **unique** `venue` labels from upcoming events. | Dedup works; no duplicate venue names. |
| **Category name prefix** | Up to 5 **category** terms. | `type: category`; links consistent with taxonomy routes when used downstream. |
| **Vendor / organiser name** | **No** vendor autocomplete. | Empty or only event/venue/category — not a failure if documented. |
| **Empty `q`** | `[]` | 200 JSON empty array. |

---

## UI consistency (events)

| Check | Pass criteria |
|-------|---------------|
| Search event card vs `/events` listing | Same **event_card** view mode; same core fields visible (image, date, location, price/ticket hint per display config). |
| Grid layout | `mel-event-grid` renders without broken markup. |
| “See more” (≥5 events) | Link targets `upcoming_events` with `q` query param where template defines. |

---

## Document history

| Section | Source |
|---------|--------|
| Full table | Prompt 81003 |
