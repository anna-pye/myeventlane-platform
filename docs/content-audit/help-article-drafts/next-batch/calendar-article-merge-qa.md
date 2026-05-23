# Calendar article merge — QA log

**Date:** 2026-05-23  
**Draft:** `add-event-to-calendar.md`  
**Governance:** `calendar-duplicate-governance.md`

## Product verification (draft scope)

| Check | Result | Notes |
|-------|--------|-------|
| My Tickets **Add to calendar** | **Works (conditional)** | `ics_url` in My Tickets template; button shown when URL present |
| Order receipt `.ics` mention | **Documented in code** | Receipt template references calendar attachment |
| Event `/event/{node}/ics` | **Needs verification** | Draft marks per-event page calendar as unverified on all events |
| Google Calendar web links vs download-only | **Needs verification** | Staging behaviour not fully confirmed |
| Email vs My Tickets parity | **Partial** | Copy uses conditional wording; not every path verified browser-side |

**Governance rule applied:** Product paths that work are documented with conditional language; gaps stay as verification notes in draft, not as invented behaviour.

## Duplicate QA (2026-05-23)

| Question | Answer |
|----------|--------|
| Is there a second published calendar article? | **Yes** — nid **1501** (stub) and nid **1673** (full body) |
| Which holds canonical alias? | **1673** → `/help/attendees/add-event-to-calendar` |
| Which holds seed key? | **1673** → `add_event_to_calendar` |
| Which should be canonical? | **1501** (after identity migration per governance doc) |
| Safe to import calendar YAML now? | **No** — importer targets 1673 |
| Safe to create new node? | **No** |

## Merge decision

- **Do not** create a third calendar article.
- **Do not** import calendar YAML until nid 1673 is retired and seed/alias belong to nid 1501.
- **Do** update nid 1501 body from verified draft after duplicate cleanup.
- **Title on canonical node:** “Adding events to your calendar” (plural) — draft title may be adjusted at import/export to match.

## Blockers

| Blocker | Type | Owner action |
|---------|------|--------------|
| Duplicate nid 1673 published with seed + alias | **Governance** | Execute pre-import cleanup in `calendar-duplicate-governance.md` |
| Importer identity on wrong nid | **Import** | Complete cleanup before `mel:help-import-priority` |
| Per-event `/ics` on all events | **Product QA** | Optional spot-check; draft already conditional |

## Post-cleanup QA checklist

- [ ] Only one published help_article for calendar topic (nid 1501)
- [ ] `/help/attendees/add-event-to-calendar` resolves to `/node/1501`
- [ ] `field_help_seed_key` = `add_event_to_calendar` on nid 1501 only
- [ ] nid 1673 unpublished/archived; seed empty; no canonical alias
- [ ] Dry-run import reports match nid 1501 by `seed_key`
- [ ] Body matches `add-event-to-calendar.md` (no stub sentence)
- [ ] Public audience; `help_status` published; `ai_allowed` true
- [ ] Related help links from My tickets cluster still resolve

## Status

**Draft verified for content** — **not ready for import** until duplicate governance cleanup completes.
