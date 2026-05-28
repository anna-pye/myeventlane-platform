# Calendar Help Centre duplicate governance

**Date:** 2026-05-23  
**Branch:** `docs/help-calendar-duplicate-governance`  
**Task:** Resolve duplicate governance before calendar draft import/update.  
**Scope:** Docs and cleanup plan only — no importer run, no product code changes.

## DB truth (local, confirmed via Drush)

**Query date:** 2026-05-23  
**Methods:** `ddev drush sql:query` (node_field_data, path_alias), `ddev drush php:eval` (entity load, alias manager, importer match simulation).

| Field | nid **1501** | nid **1673** |
|-------|--------------|--------------|
| **Title** | Adding events to your calendar | Adding an event to your calendar |
| **Bundle** | help_article | help_article |
| **Status (published)** | 1 (published) | 1 (published) |
| **Moderation state** | published | published |
| **Path alias** | *(none — resolves to `/node/1501`)* | `/help/attendees/add-event-to-calendar` (path_alias id **141** → `/node/1673`) |
| **field_help_seed_key** | *(empty)* | `add_event_to_calendar` |
| **field_audience** | public | public |
| **field_help_audience** | *(empty)* | *(empty)* |
| **field_help_status** | published | published |
| **field_help_ai_allowed** | 1 (true) | 1 (true) |
| **Body length (chars)** | 66 | 2536 |
| **Body summary length** | 0 | 0 |
| **Body preview** | “After booking, add the event to your calendar to avoid missing it.” | Full draft-aligned article (My Tickets / email / conditional calendar copy) |
| **Changed** | 2026-03-26 18:41:06 | 2026-05-22 19:57:37 |

**Importer match simulation (`add_event_to_calendar`, alias `/help/attendees/add-event-to-calendar`):**

- `field_help_seed_key` → **nid 1673 only**
- Alias lookup → **`/node/1673`**
- Title “Adding events to your calendar” → **nid 1501**
- Title “Adding an event to your calendar” → **nid 1673**

## Canonical decision

**Canonical node:** **nid 1501**

| Attribute | Target on nid 1501 |
|-----------|-------------------|
| Title | Adding events to your calendar |
| Alias | `/help/attendees/add-event-to-calendar` |
| Seed key | `add_event_to_calendar` |
| Audience | public (`field_audience`) |
| help_status | published |
| ai_allowed | true |
| Body | Replace stub with verified draft (`add-event-to-calendar.md`) after duplicate retirement |

**Rationale:** nid 1501 is the long-lived published stub referenced in publish-prep and status-sweep docs. nid 1673 is a newer duplicate that absorbed the canonical alias and seed key without retiring 1501. Governance requires one article, not a third node.

**Duplicate:** nid **1673** must not remain independently published on the same topic after cleanup.

## Duplicate risk

| Risk | Detail |
|------|--------|
| **Two live articles** | Both published; public may see stub at `/node/1501` and full article at `/help/attendees/add-event-to-calendar`. |
| **Importer mis-target** | `PriorityHelpArticleImporter` matches **seed_key first**, then **alias** — YAML for `add_event_to_calendar` updates **1673**, not 1501, until identity moves. |
| **Search / AI duplication** | Both `ai_allowed`; Help Centre retrieval may surface overlapping calendar guidance. |
| **Editorial drift** | Titles differ (plural vs singular); related-help links may point at wrong node. |
| **No redirect fallback** | Redirect module is **not enabled** locally — retiring 1673 without alias transfer would 404 the canonical URL. |

## Recommended duplicate handling

**Choose Option 1** (unpublish + release identity to nid 1501). Option 2 is weaker here because Redirect is unavailable. Option 3 applies **until** Option 1 pre-steps complete.

### Option 1 — Recommended (safe, reversible)

1. **Backup** both nodes (export via Drush entity or DB snapshot) before edits.
2. On **nid 1673:**
   - Set `field_help_status` to **archived** (or draft) and unpublish (`status` = 0).
   - Clear **`field_help_seed_key`** (empty value).
   - **Delete** path alias id **141** (`/help/attendees/add-event-to-calendar` → `/node/1673`).
3. On **nid 1501:**
   - Set **`field_help_seed_key`** = `add_event_to_calendar`.
   - Create path alias **`/help/attendees/add-event-to-calendar`** → `/node/1501`.
   - Confirm `field_audience` = public, `field_help_status` = published, `field_help_ai_allowed` = 1.
   - Keep title **Adding events to your calendar** (canonical title).
4. **Dry-run** calendar YAML import (when export exists): `ddev drush mel:help-import-priority <calendar-yaml> --dry-run` — expect match **nid 1501** by `seed_key`.
5. **Live import** or manual body merge from `add-event-to-calendar.md` onto nid 1501.
6. Spot-check alias, Help browse/search, and related-help links.

### Option 2 — Not recommended now

Archive nid 1673 and add redirect to nid 1501 — **blocked**: no enabled Redirect module on local stack.

### Option 3 — Current state (do not import yet)

**Active until Option 1 completes.** Seed key and canonical alias both belong to nid 1673; importer cannot safely update nid 1501.

## Pre-import cleanup steps (exact order)

| Step | Action | Node |
|------|--------|------|
| 1 | Export/snapshot nodes 1501 and 1673 | both |
| 2 | Unpublish; set help_status archived | 1673 |
| 3 | Clear `field_help_seed_key` | 1673 |
| 4 | Delete alias `/help/attendees/add-event-to-calendar` | 1673 |
| 5 | Set `field_help_seed_key` = `add_event_to_calendar` | 1501 |
| 6 | Add alias `/help/attendees/add-event-to-calendar` | 1501 |
| 7 | `mel:help-import-priority` **dry-run** on calendar YAML | expect nid 1501 |
| 8 | Live import or manual body update from draft | 1501 |
| 9 | Re-run dry-run | expect **skipped** (no changes) |
| 10 | QA: alias, public audience, no second published calendar article | — |

**Do not:**

- Create a new help_article node.
- Run calendar YAML import while `add_event_to_calendar` remains on nid 1673.
- Import batch 02 YAML as a substitute for calendar cleanup (batch 02 excludes calendar; see `help-articles-batch-02-2026-05-notes.md`).

## Can the importer safely update nid 1501 now?

**No.**

Until nid 1673 releases `field_help_seed_key` and the canonical alias, `mel:help-import-priority` will match and update **nid 1673** only.

## Manual DB / content edit needed?

**Yes** — identity migration (steps 2–6 above) before import. Body may be applied via importer after identity moves, or copied manually from `add-event-to-calendar.md` if YAML export is not yet generated.

## Rollback notes

| If… | Rollback |
|-----|----------|
| Cleanup on 1673 only | Re-publish 1673; restore seed `add_event_to_calendar`; restore path_alias id 141 |
| Identity moved to 1501 | Clear seed on 1501; remove alias on 1501; restore 1673 published + seed + alias |
| Import updated wrong node | Restore node export from step 1 backup |

Document rollback nids and alias ids in the import log when cleanup is executed.

## Related files

- Draft: `add-event-to-calendar.md`
- QA: `calendar-article-merge-qa.md`
- Register: `next-batch-register.md`
- Batch 02: `help-articles-batch-02-2026-05-notes.md` (calendar **excluded** from batch 02 export)
