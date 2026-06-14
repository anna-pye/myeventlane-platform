# Discovery Route Conversion Audit

**Audit date:** 2026-06-14  
**Branch:** `feature/discovery-route-audit`  
**Classification:** High / Medium / Low based on visibility, intent clarity, journey quality, and route evidence only.

---

## Primary routes

| Route | Path | Visibility | Intent | Conversion value | Notes |
|-------|------|------------|--------|------------------|-------|
| `view.upcoming_events.page_events` | `/events` | **High** — homepage (≥5 sections), footer, main menu, search recovery, 404, browse shell, help | Broad marketplace browse; exposed category + ticket-type filters | **High** | Richest template stack (`mel-browse-events-page-shell` + dedicated view template); only route with full browse UX |
| `view.upcoming_events.page_popular` | `/events/popular` | **Medium** — footer, main menu ("Discover"), static menu links; **not** on homepage rails or filter chips | Boosted/promoted events (`field_promoted = 1`) | **Medium** | Commerce-adjacent (boost placement) but public listing; overlaps homepage Community spotlight (`front_featured_events`, same promoted field) |
| `view.upcoming_events.page_this_weekend` | `/events/this-weekend` | **High** — footer, filter chips, browse quicklinks, empty-state CTAs, homepage tonight secondary CTA | Time-boxed weekend planning | **High** | Strong intent match; clear View date filter evidence |
| `view.upcoming_events.page_today` | `/events/today` | **High** — footer, filter chips, browse quicklinks, homepage "Happening tonight" primary CTA | Same-day / tonight urgency | **High** | Distinct from weekend (today = calendar day; weekend = Sat–Sun window) — not duplicate |
| *(none)* | `/events/nearby` | **Medium link exposure, zero route** — hardcoded in browse/category empty states; homepage section **not rendered** (no block) | Geo-proximity discovery | **Low** (broken) | Links exist in high-trust empty states → dead-end risk on conversion recovery paths |
| *(none)* | `/events/online` | **Low visibility** — homepage section **not rendered** (no block); install demo URI only | Virtual/remote events | **Low** (broken) | No online event type in `field_event_type`; no View filter evidenced |

---

## Additional discovery routes

| Route | Path | Visibility | Intent | Conversion value | Notes |
|-------|------|------------|--------|------------------|-------|
| `view.upcoming_events.page_category` | `/events/category/{slug}` | **High** — footer (5 curated categories), hero chips, discover embed, search fallback | Category-scoped browse | **High** | AJAX chip bar reuses today/weekend/free displays via `EventFilterController` |
| `view.upcoming_events.page_free` | `/events/free` | **High** — footer, chips, browse shell, homepage free section | Free & RSVP entry (low-friction conversion) | **High** | `PublicEventDiscoveryQueryAlter` enforces no paid tickets on this surface |
| `mel_search.view` | `/search` | **Medium** — site search; empty recovery → `/events` | Keyword discovery | **Medium** | Supporting layer; grouped results with event grid |
| `view.events_calendar.page_1` | `/calendar` | **Low–Medium** — dedicated calendar page | Date-based exploration | **Medium** | Adjacent to time-filter routes; links back to `/events` |

---

## Visibility matrix (discovery surfaces)

| Route | Homepage | Footer | Search recovery | Empty states | Filter chips | Browse shell | Main menu |
|-------|----------|--------|-----------------|--------------|--------------|--------------|-----------|
| `/events` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/events/popular` | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ |
| `/events/this-weekend` | ◑ secondary CTA | ✅ | ❌ | ✅ | ✅ | ✅ | ❌ |
| `/events/today` | ✅ primary CTA | ✅ | ❌ | ◑ inherited default | ✅ | ✅ | ❌ |
| `/events/nearby` | ◑ (section inactive) | ❌ | ❌ | ✅ **broken link** | ❌ | ❌ | ❌ |
| `/events/online` | ◑ (section inactive) | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `/events/free` | ✅ | ✅ | ❌ | ✅ default empty | ✅ | ✅ | ❌ |
| `/events/category/*` | ✅ (chips) | ✅ | ✅ fallback | ✅ | ✅ (category) | ❌ | ❌ |

---

## Conversion value rationale

### High

Routes with confirmed route owner, multiple navigation entry points, and clear user intent aligned to listing → event detail → book/RSVP journey:

- `/events`, `/events/today`, `/events/this-weekend`, `/events/free`, `/events/category/*`

### Medium

Routes that work but have narrower placement or overlap with other surfaces:

- `/events/popular` — footer + menu but duplicates promoted-content story with homepage spotlight
- `/search` — functional recovery path, not primary browse entry

### Low

Routes linked without repository-backed destination or without content model support:

- `/events/nearby`, `/events/online`

---

## Mobile quality (audit only)

| Route | Mobile quality | Basis |
|-------|----------------|-------|
| `/events` | **Good** | Dedicated browse shell, mobile filter button, `mel-events-discovery` library |
| `/events/category/*` | **Good** | Category page shell + chip bar; AJAX listing |
| `/events/today`, `/events/this-weekend`, `/events/free` | **Needs review** | Shared view wrapper without browse page shell; generic `page.html.twig` — inconsistent header/quicklinks vs `/events` |
| `/events/popular` | **Needs review** | Same as above; also excluded from `$listing_routes` hero/category header preload |
| `/events/nearby`, `/events/online` | **Unknown** | No route/template to audit |
| `/search` | **Needs review** | Dedicated `page--search.html.twig`; empty state uses standard MEL components |

No runtime device testing performed (audit-only).

---

## Commerce 3 boundary check

Confirmed **out of scope** for this audit (not classified as discovery conversion routes):

- Checkout flows, cart routes, order views
- `/my-saved-events` (authenticated saved list)
- Vendor `/vendor/events/*` paths
- Ticket/booking panes

Discovery listings use `compact_commerce` view mode (presentation only); payment state not altered by these routes.
