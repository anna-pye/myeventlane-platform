# MEL Discovery — end-to-end QA (Sprint 4)

Internal checklist. Run on staging with representative content and indexes built.

---

## 1. Homepage → category → events

1. Open `/`.
2. In **Pick a category**, choose a pill that shows a non-zero count.
3. **Expect:** URL is `/events/category/{tid}` (numeric term id).
4. **Expect:** Listing shows `upcoming_events` **page_category** (upcoming events in that category only).
5. **Expect:** Header category pills (if shown) use the same route.

## 2. Homepage → popular → explore

1. If **Popular this week** renders, open **See all** or a popular item.
2. **Expect:** No 404; destination is a valid discovery route (typically `/events` or an event node).

## 3. Search → event → related

1. Run `/search?q={known_event_keyword}`.
2. **Expect:** If at least one upcoming event matches, only the Events group appears (contract v1).
3. Open an event; scroll to **Related** (embed `upcoming_events.related_by_category`).
4. **Expect:** Related events share category with the current event (excluding current nid); cards show location using venue entity → venue name → address fallback.

## 4. `/events` → filter → results

1. Open `/events`.
2. **Expect:** **When** pills: All upcoming (active), Today, This weekend, Free & RSVP link to the correct paths (not `?date=` hacks).
3. Use **Category** and **Accessibility** exposed filters; submit **Filter**.
4. **Expect:** Results narrow; **Reset** clears filters; default with no args shows all upcoming.
5. **Expect:** Pagination still works below the grid.

## 5. `/events/today` and `/events/this-weekend`

1. Open each route.
2. **Expect:** Same filter bar as `/events` (date pill highlights correctly); category/accessibility exposed filters work.
3. **Expect:** Result set respects the display’s date window (today vs weekend).

## 6. Empty state → recovery

1. Use filters or a category with no upcoming events.
2. **Expect:** Friendly empty state; links in default Views empty text (e.g. this weekend, free, homepage) still valid.

## 7. Mobile (Sprint 4 — 84006)

1. Viewport ≤ 375px on `/events`.
2. **Expect:** Date pills scroll horizontally without breaking layout; pills and exposed selects meet **~44px** minimum tap height where themed.
3. **Expect:** Exposed filters stack or scroll without clipping the grid.

## 8. Category consistency

1. Compare **Pick a category**, **mel-page-header** pills on `/events`, and **Search** category suggestions (when Events group is empty).
2. **Expect:** Same vocabulary **`categories`** and field **`field_category`** on nodes; discovery URLs for categories use **`view.upcoming_events.page_category`**, not `/events?category=` or taxonomy canonical unless intentionally separate.

## 9. Regression

- **Upcoming block** (sidebar): No exposed filter UI; still lists upcoming events.
- **Vendor / admin** views: Unchanged by this sprint’s public discovery edits.

---

**Sign-off:** Name / date — when all flows pass on staging.
