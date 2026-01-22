# MyEventLane Search API Implementation Report

## 1. Modules installed

| Module            | Source                         | Status   |
|-------------------|--------------------------------|----------|
| `search_api`      | `drupal/search_api` (Composer) | Enabled  |
| `search_api_db`   | `drupal/search_api_db` (Composer) | Enabled |
| `myeventlane_search` | `web/modules/custom/myeventlane_search` | Enabled |

**Note:** Solr and other external backends were not installed.

---

## 2. Search API server and indexes

### Server

| ID             | Name              | Backend      | Config path |
|----------------|-------------------|--------------|-------------|
| `myeventlane_db` | MyEventLane Database | `search_api_db` | `config/install/search_api.server.myeventlane_db.yml` |

- **Backend config:** `database: 'default:default'`, `min_chars: 2`, `matching: words`, `phrase: bigram`

### Indexes

| Index ID      | Name         | Entity / datasource          | Config path |
|---------------|--------------|------------------------------|-------------|
| `mel_content` | MEL Content  | `entity:node` (event, article, page) | `config/install/search_api.index.mel_content.yml` |
| `mel_vendors` | MEL Vendors  | `entity:commerce_store` (online)     | `config/install/search_api.index.mel_vendors.yml` |
| `mel_categories` | MEL Categories | `entity:taxonomy_term` (categories) | `config/install/search_api.index.mel_categories.yml` |

**Index A – Content (`mel_content`):**

- **Bundles:** `event`, `article`, `page` (driven by `datasource_settings.entity:node.bundles.selected`).
- **Fields:** `title`, `body`, `type`, `status`, `node_grants`, `field_venue_name`, `field_venue_address`, `field_category`, `field_event_start`, `field_event_end`.
- **Processors:** `add_url`, `content_access`, `entity_status`, `html_filter`, `ignorecase`.
- **Options:** `cron_limit: 50`, `index_directly: false` (can be set to `true` for direct indexing).

**Index B – Vendors (`mel_vendors`):**

- **Bundles:** `online` (commerce_store).
- **Fields:** `name` (description not present on `commerce_store`; `address` was tried but omitted to avoid backend/format issues).
- **Processors:** `add_url`, `ignorecase`.

**Index C – Categories (`mel_categories`):**

- **Bundles:** `categories` (taxonomy).
- **Fields:** `name`, `description`.
- **Processors:** `add_url`, `ignorecase`.

---

## 3. Views created or modified

- **No Views** were added. The `/search` page is driven by:
  - Route: `mel_search.view` → `/search`
  - Controller: `\Drupal\myeventlane_search\Controller\SearchController::build`
  - Theme: `myeventlane_search_results` → `templates/myeventlane-search-results.html.twig`

Results are grouped in the controller via Search API queries; no View config was introduced.

---

## 4. Files and config changed

### New files (myeventlane_search)

| Path | Purpose |
|------|---------|
| `myeventlane_search.info.yml` | Module definition and dependencies |
| `myeventlane_search.module` | `hook_theme()` for `myeventlane_search_results` |
| `myeventlane_search.routing.yml` | Route `/search` → `SearchController::build` |
| `myeventlane_search.services.yml` | Route subscriber to override core `search.view` |
| `src/Controller/SearchController.php` | Grouped search logic and Search API queries |
| `src/Routing/SearchRouteSubscriber.php` | Removes core `search.view` so `/search` is owned by MEL |
| `templates/myeventlane-search-results.html.twig` | Markup for grouped results and empty state |
| `config/install/search_api.server.myeventlane_db.yml` | Server config |
| `config/install/search_api.index.mel_content.yml` | Content index |
| `config/install/search_api.index.mel_vendors.yml` | Vendors index |
| `config/install/search_api.index.mel_categories.yml` | Categories index |

### Modified files

| Path | Change |
|------|--------|
| `composer.json` / `composer.lock` | Added `drupal/search_api`, `drupal/search_api_db` |
| `web/themes/custom/myeventlane_theme/templates/hero/mel-hero.html.twig` | Form `action` `/events` → `/search` |
| `web/themes/custom/myeventlane_theme/templates/components/mel-page-header.html.twig` | Form `action` `/events` → `/search`; input `value="{{ search_query|default('') }}"` |
| `web/themes/custom/myeventlane_theme/templates/page--events.html.twig` | Form `action` `/events` → `/search` |
| `web/themes/custom/myeventlane_theme/templates/page--taxonomy-term--categories.html.twig` | Form `action` `/events` → `/search` |
| `web/themes/custom/myeventlane_theme/myeventlane_theme.theme` | `search_query` set from `?q=` in `preprocess_page` for all pages (and removed from front-only block); used by hero and mel-page-header |

---

## 5. How venue results are derived

- Venues are **not** a separate entity; they are taken from **event** fields:
  - `field_venue_name`
  - `field_venue_address` (address, e.g. `address_line1`, `locality`, `administrative_area`)

**Logic:**

1. **Venue-only query**  
   - Index: `mel_content`  
   - Fulltext fields: `field_venue_name`, `field_venue_address`  
   - Condition: `type = 'event'`  
   - Used to: (a) get node IDs for de-duplication, (b) build the “Venues” group.

2. **Main content query**  
   - Fulltext: `title`, `body`, `type`, `field_category` (no venue fields).  
   - Events whose IDs appear in the venue-only result are **excluded** from the “Events” group to avoid duplicates.

3. **Venues group items**  
   - Each hit is rendered as:  
     `{Event title} ({Venue name} — {address parts})`  
   - Link: event canonical URL.

---

## 6. Configurable vs hardcoded

| Item | Where | Configurable? |
|------|--------|----------------|
| **Content types (node bundles) in content index** | `search_api.index.mel_content` → `datasource_settings.entity:node.bundles.selected` | Yes, via config (YAML or UI: Search API → Index → MEL Content → “Datasources” / bundles). |
| **Category vocabulary** | `search_api.index.mel_categories` → `datasource_settings.entity:taxonomy_term.bundles.selected` | Yes, via config. |
| **Vendor store bundle** | `search_api.index.mel_vendors` → `entity:commerce_store` bundles | Yes, via index config. |
| **Limit per group** | `SearchController::LIMIT_PER_GROUP` | No, hardcoded (5). |
| **Fulltext fields for main content** | `SearchController::CONTENT_MAIN_FULLTEXT_FIELDS` | No, hardcoded. |
| **Fulltext fields for venues** | `SearchController::CONTENT_VENUE_FULLTEXT_FIELDS` | No, hardcoded. |
| **Group labels** | `SearchController::build()` | No, `$this->t()` in code. |
| **Index IDs** | `mel_content`, `mel_vendors`, `mel_categories` | Hardcoded in controller and config. |

---

## 7. Permissions and access

- **Route:** `mel_search.view` (`/search`) uses `_access: 'TRUE'` → **anonymous and authenticated** can access.
- **Node results:** `content_access` processor and `node_grants` on `mel_content` respect node access; unpublished or otherwise restricted nodes are excluded.
- **Vendors / categories:** No extra access layer; visibility follows entity availability in the index (e.g. `entity_status` where used). Commerce store “canonical” routes may be admin-only; vendor result links can point to non-canonical URLs if you add a custom vendor-facing route later.

---

## 8. Known limitations and next steps (not implemented)

1. **Commerce Store URL**  
   - `toUrl('canonical')` may point to an admin or non-public URL. A dedicated vendor-facing route/URL could be used for the “Vendors” group.

2. **Vendor “description”**  
   - `commerce_store` has no `description` field in this setup; only `name` is indexed. If a description is added (or comes from a related entity), the index and controller can be extended.

3. **Indexing**  
   - `index_directly` is `false` for all indexes. For fresher results, set to `true` and/or run `ddev drush search-api:index <index_id>` or cron after content changes.

4. **Venue address in index**  
   - `field_venue_address` is indexed as text; the DB backend will store a string form. For more structured or locale-aware behavior, an Address-specific data type or processor could be added.

5. **Empty / no-match behavior**  
   - All groups are always shown; “No results in this group” when empty. Optionally, groups with no results could be hidden.

6. **Autocomplete, ranking, UI**  
   - As requested: no autocomplete, no ranking changes, no extra UI beyond wiring search to `/search` and the grouped results page.

---

## 9. Testing (summary)

- **/search** (no `q`): empty state: “Enter a search term to find events, vendors, venues, pages, and categories.”
- **/search?q=music**: Categories group shows e.g. “Music”; other groups can be empty if nothing matches.
- **/search?q=event**: Events and Pages groups return nodes; Venues and others as per data.
- **Forms:** Hero, `mel-page-header`, `page--events`, `page--taxonomy-term--categories` submit to `/search` with `q`; `value` on `/search` is populated from `search_query`.
- **Indexing:** `ddev drush search-api:index mel_content mel_vendors mel_categories` (each ID in a separate call) has been run successfully.

---

## 10. Drush commands used

```bash
ddev composer require drupal/search_api drupal/search_api_db
ddev drush en search_api search_api_db -y
ddev drush en myeventlane_search -y
ddev drush search-api:index mel_content
ddev drush search-api:index mel_vendors
ddev drush search-api:index mel_categories
ddev drush cr
```

After enabling `myeventlane_search`, re-index when content changes (or enable `index_directly` and rely on entity save).
