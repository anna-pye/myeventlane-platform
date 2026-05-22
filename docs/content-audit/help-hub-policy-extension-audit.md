# Help hub browse policy extension audit

**Date:** 2026-05-22  
**Prior fix:** commit `0126d0f6` — `/help/search` metadata leak  
**This pass:** extend `HelpArticleBrowsePolicy` to other public browse listings; organiser IA interim; priority article drafts.

## Views audited

| View | Display | Route / placement | Lists help_article | Renders teaser metadata | YAML `field_audience` | YAML `field_help_status` | Pre-pass leak risk |
|------|---------|-------------------|--------------------|-------------------------|----------------------|--------------------------|-------------------|
| `mel_help_search` | `block_search` | `/help/search` | Yes | Yes | Exposed (empty default) | None | **Fixed in 0126d0f6** |
| `mel_help_attendee_help` | `block_attendees` | `/help/attendees` | Yes | Yes | `public` fixed | None | Draft status possible |
| `mel_help_organiser_help` | `block_organisers` | `/help/organisers` | Yes | Yes | `public` fixed | None | Draft; duplicate of attendees |
| `mel_help_vendor_help` | `block_vendors` | `/help/vendors` | Yes | Yes | `vendor` fixed | None | Draft status possible |
| `mel_help_policies_help` | `block_policies` | `/help/policies` | Yes | Yes | `public` + policy type | None | Draft status possible |
| `mel_help_featured_articles` | `block_featured` | `/help` homepage | Yes | Yes | **None** | None | **Vendor featured could leak** |
| `mel_help_faq` | `block_faq` | `/help` homepage | Yes (FAQ type) | Yes | **None** | None | Vendor FAQ type possible |
| `mel_help_related_articles` | `block_related` | `node--help-article` full | Yes | Yes | **None** | None | Cross-audience related teasers |
| `mel_help_category_listing` | `block_1` | Not on main hub routes | Yes + faq | Yes | None | None | Not extended (unused on audited routes) |
| `mel_help_centre_homepage` | — | Not referenced in controller | — | — | — | — | No change |
| `mel_help_*_admin` / analytics | admin | Staff only | Yes | Varies | N/A | N/A | Excluded (not public browse) |
| `mel_help_internal_procedures` | admin | Staff | support_procedure | — | — | — | Excluded |

## Views changed (policy applied)

All rows in **Views changed** use `HelpArticleBrowsePolicy` via `hook_views_pre_view()` and `hook_views_query_alter()`:

- `mel_help_search`
- `mel_help_attendee_help`
- `mel_help_organiser_help`
- `mel_help_vendor_help`
- `mel_help_policies_help`
- `mel_help_featured_articles`
- `mel_help_faq`
- `mel_help_related_articles`

**Not changed:** admin/analytics views; `mel_help_category_listing` (no public hub route in this audit).

## Why each change was needed

| View | Reason |
|------|--------|
| `mel_help_search` | Already fixed; kept in browse list |
| `mel_help_featured_articles` | No audience filter — could list vendor-featured articles on `/help` |
| `mel_help_faq` | No audience filter — FAQ article type only |
| `mel_help_related_articles` | No audience filter — related teasers on public articles |
| Hub views with YAML audience | YAML caps hub intent; policy adds **status** filter and intersects account policy (e.g. staff never on public hubs) |

**Hub audience caps (intersected with account policy):**

| View | Cap |
|------|-----|
| Attendee, organiser, policies | `public` only |
| Vendor hub | `vendor` only |
| Search, featured, faq, related | Account policy only |

**Help Assistant:** unchanged (`HelpRetriever`).

## Organiser IA (Scope B)

| Item | Decision |
|------|----------|
| Long-term | `/help/organisers` = public organiser onboarding; `/help/vendors` = authenticated console help (UI label “Organiser help”) |
| Interim implemented | Logged-in vendor-console users hitting `/help/organisers` → **302** to `/help/vendors` |
| User-facing label | Vendor hub H1/title → **Organiser help** (route `vendors_index` unchanged) |
| Deferred | Renaming routes, permissions, roles, or merging duplicate public lists |

## Routes verified

| Route | Anonymous | Vendor (uid 2) | Notes |
|-------|-----------|------------------|-------|
| `/help` | 200 | 200 | Featured/faq policy applied |
| `/help/search?q=ticketing` | 200, no nid 1521 | 200, includes 1521 | curl + Drush |
| `/help/attendees` | 200 | 200 | Public only |
| `/help/organisers` | 200 public list | 302 → `/help/vendors` | Redirect for vendor trust |
| `/help/vendors` | 200 empty or no vendor teasers | 200 vendor articles | Vendor cap |
| `/help/policies` | 200 | 200 | Public + policy type |
| `/node/1521` | 403 | Allowed | Unchanged |

**Staff:** `staff_playbook` not in `mel_content`; no staff audience in anon/vendor browse queries.

## Remaining risks

| Risk | Severity |
|------|----------|
| `mel_help_category_listing` if placed publicly without policy | Low |
| Organiser vs attendee hubs still show same 15 public articles | Medium (IA deferred) |
| Draft articles on `/help/vendors` if `field_help_status` not set on vendor nodes | Low — status filter now applied |
| Duplicate published articles vs new Markdown drafts (1498, 1510) | Editorial |

## Code files (Scope A + B)

- `HelpArticleBrowsePolicy.php` — browse view list, hub caps, `effectiveAudiencesForBrowseView()`
- `myeventlane_help_centre.module` — shared apply helper; query alter for all browse views
- `HelpCentreController.php` — organiser redirect; vendor hub title
