# Help article content export — audit

**Date:** 2026-05-22  
**Scope:** Priority public/vendor `help_article` portability (five articles). Read-only audit of repo mechanisms; export in `help-article-exports/help-articles-priority-2026-05.yml`.

**Source evidence:** `publish-prep/help-article-publish-register.md`, `publish-prep/editorial-update-log.md`, `publish-prep/waitlist-ticket-confirmation-publish-log.md`, live DDEV database export.

---

## Mechanisms found

| Mechanism | Location | Purpose | Idempotent? | Notes |
|-----------|----------|---------|-------------|-------|
| **Help content config + seeder** | `web/modules/custom/myeventlane_help_centre/config/install/myeventlane_help_centre.help_content.yml` (synced: `config/sync/myeventlane_help_centre.help_content.yml`) | Baseline **10** help articles + support panel definitions | **Partial** | `HelpContentSeeder::seedHelpArticles()` matches `field_help_seed_key`, then title; updates body/summary/alias/audience/taxonomy; creates if missing. |
| **Seeder service** | `HelpContentSeeder.php`, `HelpContentRepository.php` | Invoked from `myeventlane_help_centre.install` (`_myeventlane_help_centre_seed_content_update()`) on module updates | Same as above | Does **not** set `field_help_status`, `field_help_ai_allowed`, or moderation. Always sets body format to `basic_html`. |
| **Landing page seeds** | Inline in `HelpContentSeeder::seedLandingPages()` | Five `help_landing_page` nodes under `/help/landing/*` | Title match only | Skips if title exists; does not update. |
| **Schema** | `config/schema/myeventlane_help_centre.schema.yml` | Config shape for install seeds | N/A | No schema for portability export YAML. |
| **Docs importer** | `myeventlane_docs_importer` (`DocsImportService`, Drush) | CSV/registry import for documentation bundles (`staff_playbook`, etc.) | Row-level | **Not** for public `help_article` portability; different bundle and governance. |
| **Demo seed module** | `myeventlane_seed` | Demo events/users | N/A | No help article seeding. |
| **Drush (help centre)** | `RepairNodeFieldTablesCommands` only | Field table repair | N/A | No `mel:help-import` or article export command. |
| **default_content / content_sync** | — | — | — | **Not present** in repo. |
| **Markdown drafts** | `docs/content-audit/help-article-drafts/*.md` | Editorial source before publish | N/A | Not imported automatically; used for merge/publish prep. |

---

## Seeded help articles today

- **Install/sync config:** 10 articles in `myeventlane_help_centre.help_content.yml` with `seed_key`, plain-text `summary`/`body`, audience labels (`Attendees`, `Organisers`, …), taxonomy topic/article_type **names**, and **aliases** under `/help/attendees/…`, `/help/organisers/…`, `/help/vendors/…`.
- **None** of the five priority export articles are in that seed file (they were authored/merged in the database after seeds).
- **Live inventory** (`staging-help-content-inventory.md`): 31 published `help_article` nodes; **`field_help_seed_key` populated on 0 nodes** — runtime content has outgrown install seeds; identity on live site is **title + alias**, not seed key.

---

## Aliases

- Priority articles use **manual** aliases (`/help/attendees/…`, `/help/vendors/…`); no Pathauto pattern for `help_article` (see `editorial-update-log.md`).
- Seeder sets `path.alias` with `pathauto: 0` when seeding from config.
- **Aliases are portable** and should be the primary import match key alongside stable export keys (`contacting_support`, etc.).

---

## Nids are environment-specific

- Export `source_nid` values (1497, 1498, 1510, 1668, 1669) are **local references only**.
- Other environments may have the same titles/aliases on **different** nids or missing nodes entirely.
- **Do not** import by nid.

---

## Idempotency and safe update by key/alias

| Identity | Safe on fresh env? | Safe on env with existing help content? |
|----------|-------------------|----------------------------------------|
| `field_help_seed_key` | Yes, if seeder runs and key is set | **Not reliable today** — keys empty on live/staging inventory |
| **URL alias** | Yes | Yes — query `path_alias` for alias + `help_article` |
| **Title** | Risky (duplicates) | Seeder fallback only |
| **Export stable key** (YAML map key) | Yes, if importer writes `field_help_seed_key` | Requires **new** importer or manual mapping |

**Existing seeder limitations for these five articles:**

- Won't apply without adding rows to `myeventlane_help_centre.help_content.yml` **and** running update seed.
- Won't preserve `full_html` body (forces `basic_html`).
- Won't set `field_help_status` / `field_help_ai_allowed`.
- Won't set canonical list `field_audience` from `public`/`vendor` strings without mapping (seeder maps label lists like `Attendees` → `public`).

---

## Recommended export/import approach

1. **Export (done):** `docs/content-audit/help-article-exports/help-articles-priority-2026-05.yml` — stable keys, aliases, audience, governance fields, text formats preserved.
2. **Target environment import (manual until dedicated importer):**
   - For each article, resolve node by **alias** (preferred) or title on `help_article` bundle.
   - Skip if bundle is `staff_playbook`, `support_procedure`, or audience is `staff`.
   - Require `status = published`, `field_help_status = published` before treating as live.
   - Set `field_audience`, `field_help_status`, `field_help_ai_allowed`, `field_help_article_type` (taxonomy by name: Guide/FAQ/Troubleshooting), `field_help_summary`, `body` (respect `format`), `path.alias`.
   - Optionally set `field_help_seed_key` to the export stable key for future seeder alignment.
   - Reindex **`mel_content`** for updated nodes only.
3. **Do not** merge these five into install `help_content.yml` without a product decision — that file is baseline onboarding copy, not post-publish editorial exports.
4. **Later task:** Small Drush command or extend seeder to read portability YAML with governance fields; match by alias + seed key; update-only (no deletes).

---

## Importer decision (Task C)

**No importer code added.**

- Existing `HelpContentSeeder` is the only idempotent help-article writer, but it targets **config install seeds**, omits governance fields, and does not read portability YAML.
- `myeventlane_docs_importer` is the wrong bundle and audience model.
- **Manual import/update** (or DB copy in controlled ops) is appropriate for this pass.

### Manual import checklist (per article)

1. Confirm alias on target: `drush php:eval` or admin Content → filter `help_article`.
2. Edit or create published `help_article`; paste summary/body from YAML; keep formats (`basic_html` summary, `full_html` body as exported).
3. Set `field_audience` (`public` or `vendor`), `field_help_status` = `published`, `field_help_ai_allowed` = true (unless policy exception).
4. Set article type term (Guide / FAQ / Troubleshooting).
5. Save path alias exactly as in YAML (`pathauto` off).
6. `ddev drush search-api:index mel_content` (single node or batch) if content changed.

---

## Risks

| Risk | Mitigation |
|------|------------|
| Wrong audience on vendor copy | Verify `field_audience` before publish; vendor article only on `/help/vendors` hub |
| Duplicate nodes by title | Match by alias first |
| Seeder overwrite without governance fields | Do not run bulk seed update against these nids without extending seeder |
| `full_html` vs `basic_html` filter drift | Preserve exported formats; test render on target |
| Assistant retrieval | Requires published + `field_help_ai_allowed` + public/vendor audience — export includes these |
| Staff content leakage | Export excludes `staff_playbook`, `support_procedure`, `staff` audience — verify on import |
| Nid-based deploy scripts | Ban nid as identity in automation |

---

## Files produced

- `docs/content-audit/help-article-content-export-audit.md` (this file)
- `docs/content-audit/help-article-exports/help-articles-priority-2026-05.yml`
