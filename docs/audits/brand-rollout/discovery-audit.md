# Discovery Audit

**Brand rollout:** The Hidden Gem + The Guide (Bright Edition)
**Audit date:** 2026-06-14
**Method:** Evidence-based.

---

## 1. Discovery surfaces inventory (evidence)

### Browse pages — `upcoming_events` View (`config/sync/views.view.upcoming_events.yml`)
| Path | Display | Intent |
|---|---|---|
| `/events` | `page_events` | Main browse / marketplace |
| `/events/category/%` | `page_category` | Category browse |
| `/events/free` | `page_free` | Free events |
| `/events/popular` | `page_popular` | Popular |
| `/events/this-weekend` | `page_this_weekend` | **Weekend discovery** (directly on-brand) |
| `/events/today` | `page_today` | Today |

Additional browse Views: `all_events`, `new_events`, `mel_home_events` (homepage embeds), `mel_saved_events` (saved/followed).

### Search pages — `myeventlane_search` module
| Path | Controller | Backend |
|---|---|---|
| `/search` | `SearchController::build` | **Search API** index (`search_api` + `search_api_db` enabled in `core.extension.yml`) |
| `/search/autocomplete` | `SearchAutocompleteController::autocomplete` | Search API index, query tag `myeventlane_event_discovery`, fulltext over event + venue fields |

Search is **database-backed Search API** (no Solr). Includes dedicated venue search (`VENUE_SEARCH_FIX_REPORT.md`, `SEARCH_IMPLEMENTATION_REPORT.md`).

### Category pages
`upcoming_events:page_category` at `/events/category/%` + homepage `front_category_pills` View + `myeventlane_category_pills` block. Category-follow CTA exists (`templates/components/mel-category-follow-cta.html.twig`).

### Calendar
`events_calendar` View (`config/sync/views.view.events_calendar.yml`) → page display at `/calendar`. Plus per-event ICS export (`myeventlane_event.calendar_ics` → `/event/{node}/calendar.ics`).

### Featured / editorial / recommendation Views
`featured_events`, `front_featured_events`, `featured_events_carousel`, `front_recommended_events`, `featured_discover_recommended`, `front_discover_events`.

> **Repository evidence not found** for dedicated `/events/nearby` and `/events/online` routes, although `page--front.html.twig` links to them (homepage §2). These are likely unbuilt or additional `upcoming_events` displays not present in the View config inspected. **Flag for validation** — broken links would undercut a discovery-first brand.

---

## 2. What is the current discovery model?

| Model | Present? | Evidence weight |
|---|---|---|
| **Browse-first** | ✅ **PRIMARY** | The dominant pattern: `upcoming_events` exposes 6 page displays by intent (free/popular/today/weekend/category) + `all_events`/`new_events`. Homepage is region-of-browse-rails. |
| **Search-first** | ◑ Secondary/supporting | Real Search API at `/search` + autocomplete, but it is a supporting tool, not the primary entry (homepage leads with browse rails, not a dominant search-results page). |
| **Editorial-first** | ◑ Emerging | `featured_events` + **"Curated by MyEventLane"** curator line + `featured_discover_recommended`. Editorial *vocabulary* exists but isn't the spine. |
| **Recommendation-first** | ◑ Emerging | `front_recommended_events` View + `home_recommended` region exist; "Recommended for you" is one rail among many, not the organising principle. |

**Conclusion: MEL is BROWSE-FIRST, with a working SEARCH layer and an EMERGING EDITORIAL/RECOMMENDATION layer.**

---

## 3. Fit with "The Guide helps people discover experiences they never knew existed"

The Guide is an **editorial + recommendation** persona. MEL today is **browse-first**. The architecture supports the shift cheaply because:

- Editorial (`featured_events`, curator line) and recommendation (`front_recommended_events`) **scaffolding already exists** — it just isn't the lead.
- Intent-based displays (`this-weekend`, `today`, `free`, `popular`) map naturally onto Guide framing ("This weekend's gems", "Tonight near you").
- Search API supports the autocomplete/answer surface a conversational "Ask the Guide" could later use.

### Gap analysis
| Gap | Evidence | Bright Edition action |
|---|---|---|
| Browse rails lead; editorial/recommendation are secondary | §2 | Promote editorial/recommendation rails to the top; re-voice as the Guide. Config/copy, not new code. |
| No "Hidden Gem" concept in data/Views | no `gem`/`hidden` View or field found | Add a curated "Hidden Gems" `upcoming_events` display or a flag/term + View (small). |
| Discovery copy is functional, not wonder-driven | View titles, homepage headings | Re-voice. |
| Vibe Mixer not connected to a Views query | `discovery-audit` §1 + `component-inventory.md` | Wire Vibe Mixer chips to exposed filters / contextual filters on `upcoming_events`. |
| Possible broken `/events/nearby` `/events/online` links | §1 note | Validate / build displays. |

---

## 4. Verdicts

| Verdict | Item |
|---|---|
| **SAFE TO REUSE** | `upcoming_events` View + all intent displays; `/search` Search API + autocomplete; `events_calendar`; category pills/follow; featured & recommended Views |
| **NEEDS EVOLUTION** | View titles/exposed-filter copy → Guide voice; promote editorial/recommendation rails; connect Vibe Mixer to a query |
| **ADD (low risk)** | "Hidden Gems" curated View display; "This weekend" Guide surface (display already exists) |
| **VALIDATE** | `/events/nearby`, `/events/online` route existence |
| **DON'T TOUCH** | Search API index config, View query/access layer |

**Bottom line:** The discovery engine (browse + search + emerging editorial/recommendation) is in place and healthy. The Guide is a **re-prioritisation + re-voicing** of existing Views, plus one small "Hidden Gems" curation surface — not a new discovery system.
