# Venue search fix report: "the chippo" and similar queries

## Root cause

Three separate issues:

1. **Events query did not search venue fields**  
   `CONTENT_MAIN_FULLTEXT_FIELDS` was `['title','body','type','field_category']`.  
   `type` and `field_category` are **string**, not fulltext, so they were ignored by the backend.  
   **`field_venue_name` and `field_venue_address` were never searched** in the main/Events query, so venue-only searches (e.g. "the chippo") returned nothing in Events.

2. **Server `matching: words` vs phrase-only index**  
   With `matching: words`, the DB backend uses `word IN ($tokenized_keys)` (e.g. `word IN ('the','chippo')`).  
   The index stores **whole phrases** in `search_api_db_*_text.word` (e.g. `"the chippo hotel"`), not single tokens.  
   So `'chippo' IN (...)` never matched `"the chippo hotel"`.  
   Changing the server to **`matching: partial`** makes it use `LIKE '%chippo%'`, so "chippo" matches inside "the chippo hotel".

3. **`buildVenueItems` `implode()` on Address**  
   `$a->get('address_line1')` etc. return `StringData` (TypedData), not strings.  
   `implode(', ', [..., $a->get('address_line1'), ...])` caused: *Object of class ... StringData could not be converted to string*.  
   Fixed by reading `->getValue()` and casting to `(string)` before building the parts array.

---

## Files and config changed

| Path | Change |
|------|--------|
| `myeventlane_search/src/Controller/SearchController.php` | • `CONTENT_MAIN_FULLTEXT_FIELDS`: `['title','body','field_venue_name','field_venue_address']` (removed `type`,`field_category`; added venue fields).<br>• `runContentQuery()`: removed `$excludeNids` and the `runVenueQuery`-for-dedup logic; now searches all four fulltext fields.<br>• Removed `runVenueQuery()`.<br>• `buildVenueItems()`: address parts built via `$a->get($k)->getValue()` and `(string)` before `implode`, to avoid TypedData-in-implode. |
| `myeventlane_search/config/install/search_api.server.myeventlane_db.yml` | `backend_config.matching`: `words` → `partial`. |
| **Active config** (via `drush config:set`) | `search_api.server.myeventlane_db` `backend_config.matching` = `partial`. |

---

## Field IDs discovered (mel_content)

**Drush snippet used to inspect (no guessing):**

```bash
ddev drush php:eval "
\$index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load('mel_content');
if (!\$index) { print 'Index mel_content not found'; return; }
print \"=== mel_content getFields() keys (field identifiers) ===\n\";
foreach (\$index->getFields() as \$fid => \$f) {
  \$ft = \$f->getType();
  \$isT = \Drupal::getContainer()->get('search_api.data_type_helper')->isTextType(\$ft);
  print \$fid . ' | type=' . \$ft . ' | fulltext=' . (\$isT ? 'YES' : 'no') . ' | path=' . \$f->getPropertyPath() . \"\n\";
}
print \"\n=== getFulltextFields() ===\n\";
print_r(\$index->getFulltextFields());
"
```

**Result (short list):**

| Field ID | Type  | Fulltext | Property path        |
|----------|-------|----------|----------------------|
| title    | text  | YES      | title                |
| body     | text  | YES      | body                 |
| field_venue_name    | text  | YES | field_venue_name     |
| field_venue_address | text  | YES | field_venue_address  |
| type     | string| no       | type                 |
| field_category | string | no | field_category       |
| status   | boolean | no    | status               |
| node_grants | string | no  | search_api_node_grants |
| field_event_start | date | no | field_event_start  |
| field_event_end   | date | no | field_event_end    |

**`getFulltextFields()`:** `['title','body','field_venue_name','field_venue_address']`.

There are **no** separate Address sub-properties (e.g. `field_venue_address:address_line1`) in this index; the whole `field_venue_address` is one text field. The DB text table has rows for it (e.g. `au` from `administrative_area` or similar); venue name is the main searchable source for names like "the chippo".

---

## Before / after

**Query: `/search?q=the+chippo`**

| Group   | Before | After |
|---------|--------|-------|
| Events  | No results | Annas Event; The newest Event V2 |
| Venues  | No results | Annas Event (The Chippo Hotel — Unit1 208a St Johns Road, Forest Lodge, NSW); The newest Event V2 (The Chippo Hotel — 87-91 Abercrombie Street, Chippendale, NSW) |
| Vendors | No results | No results (expected) |
| Pages   | No results | No results (expected) |
| Categories | No results | No results (expected) |

**Query: `/search?q=event`**  
Still returns events, pages, vendors, etc.; event-by-title search is unchanged.

---

## Follow-up improvements (not implemented)

1. **`matching: partial`** can increase DB load and can match more than intended (e.g. "chip" in "chippo"). If that’s a problem, consider:
   - Re-tokenizing at index time so `field_venue_name` (and similar) produce unigram tokens (e.g. "chippo", "hotel") in `*_text`, and/or
   - A custom parse mode or backend behaviour that matches both whole phrases and significant substrings.
2. **Address in Venues**  
   `field_venue_address` is only partly represented in the index (e.g. country "au"); `address_line1`, `locality` etc. could be added as separate indexed text fields or one aggregated text field if address-based search is required.
3. **Re-export server config**  
   `search_api.server.myeventlane_db` was changed with `config:set` and the `config/install` YAML was updated. Ensure config sync and install use the same `matching: partial` (or whatever is chosen) for consistency across envs.
