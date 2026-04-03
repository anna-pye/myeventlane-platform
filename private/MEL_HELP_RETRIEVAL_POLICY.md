# MEL Help Retrieval Policy

**Status:** Evidence-based audit (code + config as of repo snapshot).  
**Scope:** AI and programmatic retrieval of `help_article` content. Does not replace Drupal permissions or editorial workflow configuration.

---

## Retrieval compliance (mandatory)

### Compliant vs non-compliant services

Any retrieval service used in **AI** or **user-facing help** flows must implement the **canonical retrieval rules** (this document, sections A–B and the unified validation pass).

Services that do **not** enforce all of the following are **NON-COMPLIANT** and **must not** be wired into production flows:

- `field_help_ai_allowed` (AI-safe allow-list)
- `field_help_status` (when set: `published` or `approved` only)
- `field_audience` constraints (public/vendor tier, **no** `staff` in retrieved AI context)
- Per-user **`node` access** (`access('view', $account)` or equivalent)

**Rationale:** Prevents “convenience” reuse of legacy or ad hoc queries (e.g. deprecated `HelpArticleRetriever`-style paths) that bypass policy and reintroduce leakage.

Known **non-compliant** example (retained only for deprecation / custom audit): `HelpArticleRetriever` — entity query with `accessCheck(FALSE)` and no AI/status/staff checks.

### Vendor AI and staff users

**Product rule (locked):**

- **Vendor AI always operates under public/vendor audience rules** for retrieved `help_article` content, regardless of whether the submitter is a vendor user or staff.
- **Staff users** using the vendor escalation assistant **do not** receive **staff-only** help content through that path; the same retrieval stack applies as for vendors (`HelpRetriever` + `UnifiedHelpRetriever` validation: no `staff` in `field_audience` for returned rows).
- **Staff-specific** help must be accessed via **dedicated staff surfaces** (e.g. Support Console internal knowledge, governance dashboard, docs register, direct node access where `hook_node_access` allows `administer escalations`).

**Implementation note:** `VendorAiAssistantForm` passes the real account into `retrieveForUser()`; authenticated staff still get `allowedAudienceValues` of `public` + `vendor` from `HelpRetriever` — they do **not** get an expanded staff corpus in that AI prompt.

---

## Canonical Retrieval Rules

These rules describe what each retriever **actually implements** in code. Where two paths differ, both are listed.

### A. Help Assistant — `HelpRetriever` (`myeventlane_help_assistant`)

**Source:** `web/modules/custom/myeventlane_help_assistant/src/Service/HelpRetriever.php`

| Rule | Implementation |
|------|------------------|
| **Index / engine** | Search API index `mel_content`; logs and returns `[]` if index missing. |
| **Bundle** | Query condition `type` = `help_article`. |
| **Node published flag** | Search API condition `status` = `1` (published). |
| **Full-text fields** | `title`, `field_help_summary`, `body`, `field_help_keywords`. |
| **Relevance** | Sorted by `search_api_relevance` DESC; fetches up to `max(24, limit×6)` then stops at `limit` (3–5). |
| **Audience (Search API pre-filter)** | OR group on indexed `field_audience`: anonymous → `public` only; authenticated → `public` **or** `vendor`. |
| **Post-filter: published** | `$node->isPublished()` must be true. |
| **Post-filter: node access** | `$node->access('view', $currentUser)` must be true. |
| **Post-filter: AI allow-list** | `field_help_ai_allowed` must exist, be non-empty, and boolean value true. |
| **Post-filter: documentation status** | If `field_help_status` is non-empty, value must be `published` or `approved` (not `draft`, `review`, or `archived`). |
| **Post-filter: audience (canonical list)** | `field_audience` required; must not contain `staff`; every value must be `public` or `vendor`; at least one value must intersect the caller’s allowed set (`public` or `public`+`vendor`). |
| **Staff exclusion** | Any `staff` value in `field_audience` → excluded. |
| **Error handling** | Throwable → `logger->error('Help retriever failed: …')`, return `[]`. |

**Allowed values for `field_help_status` (storage):** `draft`, `review`, `approved`, `published`, `archived` — per `config/sync/field.storage.node.field_help_status.yml`.

**Note:** `mel_content` does **not** index `field_help_ai_allowed` or `field_help_status` (see `config/sync/search_api.index.mel_content.yml`); those checks run only in PHP after loading the node from the search hit.

### B. Vendor AI — `UnifiedHelpRetriever` (`myeventlane_help_shared`) — **current**

**Source:** `web/modules/custom/myeventlane_help_shared/src/Service/UnifiedHelpRetriever.php`

Vendor escalation AI (`VendorAiAssistantForm`) calls `retrieveForUser($query, $account, $limit)`, which:

| Rule | Implementation |
|------|------------------|
| **Underlying retrieval** | `AccountSwitcher` → existing **`HelpRetriever`** (Search API `mel_content`, same rules as Help Assistant for that account). |
| **Policy pass** | After retrieval, each hit is re-validated: `isPublished()`, `access('view', $user)`, `field_help_ai_allowed`, `field_help_status`, `field_audience` (no `staff`, public/vendor only, intersection with user tier). |
| **Logging** | Policy drops log to channel **`myeventlane_help`** (`notice`) with `reason` in `{not_published, access_denied, ai_not_allowed, status_invalid, staff_audience, audience_mismatch}` when a row is filtered. |
| **Return shape to form** | Rows mapped to legacy `{nid, title, url, excerpt}` (excerpt truncated to 400 chars from `content`/`summary`). |

### B-legacy. `HelpArticleRetriever` (`myeventlane_vendor_ai`) — **deprecated**

**Source:** `web/modules/custom/myeventlane_vendor_ai/src/Service/HelpArticleRetriever.php` (class marked `@deprecated`; service `myeventlane_vendor_ai.help_retriever` retained but unused by core forms).

| Rule | Implementation (historical) |
|------|------------------------------|
| **Engine** | Entity query on node storage (no Search API). |
| **Entity access** | **`accessCheck(FALSE)`** on the query. |
| **field_help_ai_allowed / field_help_status / staff exclusion** | **Not checked** on the legacy path. |

**No core code paths should instantiate the legacy retriever** after unification; remove in a future release when custom modules are clear.

### C. Help Centre node access (view), all surfaces

**Source:** `web/modules/custom/myeventlane_help_centre/myeventlane_help_centre.module` — `hook_node_access()` for `help_article` and `faq`.

| Audience tag on `field_audience` | View access (summary) |
|-----------------------------------|------------------------|
| Contains `staff` | Allowed only if account has **`administer escalations`** (unless bypass node access). |
| `vendor` without `public` | **Forbidden** for anonymous; authenticated allowed subject to other grants. |
| `public` (with or without other values) | Neutral (falls through to other access rules). |

**Comment in code:** `field_help_audience` (taxonomy) is deprecated; access uses **`field_audience` only**.

### D. Contextual inline help — `ContextualHelpResolver`

**Source:** `web/modules/custom/myeventlane_help_centre/src/Service/ContextualHelpResolver.php`

| Rule | Implementation |
|------|------------------|
| **Resolution** | By `field_mel_register_id` when field exists, else exact `title` match to a static register map. |
| **Published** | Query `status` = `1`. |
| **Access** | `accessCheck(TRUE)` on entity queries; card omitted if `!$node->access('view', $currentUser)`. |
| **AI / field_help_status** | Not applicable (display card, not AI retrieval). |

---

## Allowed Content (by retriever)

| Retriever | Intended consumer | Allowed in practice (implemented filters) |
|-----------|-------------------|-------------------------------------------|
| **HelpRetriever** | Help Assistant (+ Event wizard AI path via same service) | Published `help_article`; viewable by current user; `field_help_ai_allowed` = true; `field_help_status` empty or `published`/`approved`; `field_audience` only `public`/`vendor` and matching user tier; **no** `staff` tag. |
| **UnifiedHelpRetriever** | Vendor escalation AI (`VendorAiAssistantForm`) | Same policy stack as Help Assistant for the submitting user (via `HelpRetriever` + explicit post-validation). |
| **HelpArticleRetriever** (deprecated) | *(none in core)* | Legacy entity query; do not use for new work. |

---

## Disallowed Content (canonical policy intent vs implementation)

| Content type | Help Assistant (`HelpRetriever`) | Vendor AI (`UnifiedHelpRetriever`) |
|--------------|----------------------------------|-------------------------------------|
| Unpublished (`status` ≠ 1) | Excluded (Search API + `isPublished()`) | Excluded (same + unified pass) |
| `field_help_ai_allowed` false / empty | Excluded | Excluded |
| `field_help_status` draft / review / archived | Excluded when field set | Excluded |
| Staff in `field_audience` | Excluded | Excluded |
| Node access denied | Excluded | Excluded (`access('view', $user)` on unified pass) |
| Non–`help_article` | Excluded | Excluded |

---

## Current Gaps (Assistant vs Vendor AI)

**After unification**, vendor AI uses the same **`HelpRetriever`** engine and policy checks as the assistant (plus a redundant validation pass). Remaining differences:

1. **Excerpt shaping:** Vendor AI maps retriever `content`/`summary` to a **400-character** excerpt for prompts (assistant uses longer grounded text in its own pipeline).  
2. **Search index gaps:** `field_help_ai_allowed` and `field_help_status` are not indexed; both paths rely on PHP post-filtering (correct but per-hit node load).  

**Legacy gaps** applied only to deprecated `HelpArticleRetriever` (priority sort, no question relevance, `accessCheck(FALSE)`); do not apply to `UnifiedHelpRetriever`.

---

## Risk Assessment

| Risk | Area | Severity | Evidence |
|------|------|----------|----------|
| **Staff or internal-only copy in vendor AI prompts** | Addressed for vendor path via `UnifiedHelpRetriever` | **Low** (residual: mis-tagged content still in index until reindex/repair) | Legacy `HelpArticleRetriever` risk retired from core form. |
| **Non–AI-approved articles in vendor context** | Unified path | **Low** | `field_help_ai_allowed` enforced. |
| **Stale index vs live node** | Search API hit vs post-validate | **Low** | Unified layer drops desynced rows and can log `not_published` / `status_invalid` / etc. |
| **Irrelevant excerpts** | Search API relevance | **Low** | Vendor AI now uses the same relevance ranking as Help Assistant for the question. |

---

## Retrieval Entry Points

| Location | Surface | Retriever / mechanism | Access model | Risk level |
|----------|---------|------------------------|--------------|------------|
| `web/modules/custom/myeventlane_help_assistant/src/Service/HelpAssistantService.php` | Help Assistant (AI answer) | `HelpRetriever` | Route/flood + user session; retrieval uses `currentUser` for access and audience | **Low** (strongest filters) |
| `web/modules/custom/myeventlane_help_assistant/src/Controller/HelpAssistantController.php` | Help Assistant (HTTP) | Delegates to `HelpAssistantService` | Flood + JSON `ask` endpoint | **Low** |
| `web/modules/custom/myeventlane_help_assistant/src/Form/HelpAssistantQueryForm.php` | Help Assistant (form) | `HelpAssistantService` | Form access | **Low** |
| `web/modules/custom/myeventlane_help_assistant/src/Service/EventSuggestionService.php` | Event wizard / vendor panel (optional AI when no rule hits) | `HelpRetriever` | Authenticated vendor context in controller; uses same audience rules as logged-in user | **Low** |
| `web/modules/custom/myeventlane_vendor_ai/src/Form/VendorAiAssistantForm.php` | Vendor AI (escalation assistant) | `UnifiedHelpRetriever` → `HelpRetriever` + policy pass | `EscalationPartyResolver::isVendor` or `isStaff`; submitter account passed into retrieval | **Low** |
| `web/modules/custom/myeventlane_help_centre/src/Service/ContextualHelpResolver.php` | Inline contextual help card | Entity query + `loadArticleByRegisterId` | `accessCheck(TRUE)` + `node->access('view')` | **Low** (display only) |
| `web/modules/custom/myeventlane_help_centre/src/Controller/HelpCentreController.php` | Help Centre hub, listings, search page | Views (`buildView`) | View `access content` + node access on rendered rows | **Low** (browse) |
| `web/modules/custom/myeventlane_help_centre/myeventlane_help_centre.module` | Checkout / wizard / forms | `ContextualHelpResolver` + `myeventlane_help_centre_get_link()` config paths | Mixed: resolver uses access; static links are config | **Low** |
| `web/modules/custom/myeventlane_help_centre/myeventlane_help_centre.module` `hook_preprocess_node()` | Help article full page | View `mel_help_related_articles` | Standard view + node access | **Low** |
| `web/modules/custom/myeventlane_support_console/src/Controller/SupportConsoleController.php` | Staff console “internal knowledge” | Entity query `help_article` + `field_audience` = `staff` | `accessCheck(TRUE)` | **Low** (staff UI) |
| `web/modules/custom/myeventlane_staff_playbooks/src/Controller/GovernanceDashboardController.php` | Governance dashboard | Entity query staff `help_article` | `accessCheck(TRUE)` | **Low** |
| `config/sync/views.view.mel_help_*.yml` (see list below) | Help Centre / admin analytics | Views on `node_field_data` | `access content` or admin perms | **Low** |
| `web/modules/custom/myeventlane_search/src/Controller/SearchController.php` | Site `/search` | `mel_content` query | **Does not** surface `help_article` in grouped results (only `event`, `article`, `page` in pages group) | **N/A** |
| `web/modules/custom/myeventlane_search/src/Controller/SearchAutocompleteController.php` | Autocomplete | Events + venues only | No help articles | **N/A** |

**Views whose config references `help_article` (Help Centre / related):**  
`mel_help_attendee_help`, `mel_help_articles_by_audience`, `mel_help_category_listing`, `mel_help_centre_homepage`, `mel_help_featured_articles`, `mel_help_faq`, `mel_help_feedback_admin`, `mel_help_least_helpful`, `mel_help_most_helpful`, `mel_help_organiser_help`, `mel_help_policies_help`, `mel_help_related_articles`, `mel_help_search`, `mel_help_top_articles`, `mel_help_top_searches`, `mel_help_vendor_help`, `mel_help_zero_results`, `mel_help_analytics_admin` — plus `mel_docs_register` (documentation register).  
**Internal procedures view** `mel_help_internal_procedures` uses `support_procedure`, not `help_article`.

---

## Retrieval Mismatch Analysis

| # | Mismatch | Reason in code | Severity |
|---|----------|----------------|----------|
| 1–5 | Vendor AI mismatches (AI flag, staff co-tag, status, access, question relevance) | **Addressed** for `VendorAiAssistantForm` by `UnifiedHelpRetriever` + `HelpRetriever` | **Resolved** (core path); monitor logs for `Unified help retrieval policy filter` if index/node desync |
| 6 | Assistant may index moderate states via Search API processors | `status` + `entity_status` / `content_access` on index; post-filter adds stricter `field_help_status` | **Low** (usually aligned; edge cases if index stale) |

**Historical contrast (legacy `HelpArticleRetriever` only):**  
A published node with `field_audience` = `vendor` + `staff`, `field_help_ai_allowed` = `0`, `field_help_status` = `review` — excluded by `HelpRetriever` / `UnifiedHelpRetriever`; was **potentially included** by the old entity query path.

---

## Deprecation Plan — `HelpArticleRetriever`

### Status (implemented)

- **`VendorAiAssistantForm`** now uses **`myeventlane_help_shared.unified_help_retriever`** only.  
- **`HelpArticleRetriever`** is **`@deprecated`**; service **`myeventlane_vendor_ai.help_retriever`** remains registered with a YAML comment for one release cycle.  
- **Next step:** delete class + service entry after confirming no contrib/custom `->get('myeventlane_vendor_ai.help_retriever')` usage.

### Prior usage map (historical)

| Consumer | File | Surface | Data returned |
|----------|------|---------|---------------|
| Vendor escalation AI form | `VendorAiAssistantForm.php` | Vendor (and staff) escalation UI | Was `[nid, title, url, excerpt]` via `HelpArticleRetriever`; now same contract via `UnifiedHelpRetriever` mapping. |

**Service ID (deprecated):** `myeventlane_vendor_ai.help_retriever` → `HelpArticleRetriever`.

### Logic gaps vs `HelpRetriever`

- No `field_help_ai_allowed` check.  
- No `field_help_status` check.  
- No exclusion of `staff` in `field_audience`.  
- No `node->access('view', …)` on query (and no post-load access check).  
- No Search API relevance to the question.  
- Sorting by `field_priority` vs Search API score (different ordering semantics).

### Replacement strategy

**Recommended:** Reuse **`HelpRetriever`** (or extract a shared internal service used by both) so vendor AI receives the **same** post-filters as the Help Assistant, with these **explicit product decisions**:

1. **Audience:** For vendor escalation context, pass **authenticated** user (already vendor/staff on form) so `HelpRetriever::allowedAudienceValuesForCurrentUser()` yields `['public','vendor']` — aligned with vendor-facing content. **Do not** run as anonymous.  
2. **Relevance vs priority:** Either extend `HelpRetriever` to accept optional sort/boost, or post-sort a merged candidate set — document trade-off (Search API score vs `field_priority`).  
3. **Excerpt length:** `HelpRetriever` uses ~1200 chars from structured fields; vendor prompt uses 400 — truncate in the vendor layer if needed.

**Alternative:** Thin wrapper service that calls `HelpRetriever`, then re-sorts by `field_priority` for vendor prompts only (still must apply staff / AI allow / status checks **before** sort).

### Migration steps (planning only — no code in this task)

1. Add adapter in vendor AI layer that calls shared retrieval API with vendor-appropriate user context.  
2. Map `HelpRetriever` result shape to existing `help_excerpts` structure expected by `VendorAiContextBuilder` / job placeholders.  
3. Kernel or functional test: vendor AI path must not return nodes failing Assistant rules (fixtures with `staff`, `field_help_ai_allowed` = 0, `field_help_status` = review).  
4. Remove `HelpArticleRetriever` and service definition; update `mel-services.json` if required by your tooling.  
5. Reindex `mel_content` if retrieval logic changes depend on new indexed fields (only if you move filters into Search API).

### Rollback strategy

- Keep `HelpArticleRetriever` behind a feature flag or service alias until vendor AI prompts are validated in staging.  
- Git revert of DI wiring in `VendorAiAssistantForm` restores previous behaviour.

### Risks of migration

| Risk | Mitigation |
|------|------------|
| **Priority ordering lost** | Preserve `field_priority` sort after filtered result set, or add to query pipeline. |
| **Performance** | Search API query + node loads vs single entity query; monitor job enqueue path. |
| **Staff vendor users** | Confirm `HelpRetriever` staff exclusion on **content** still matches product (vendors should not get staff-tagged articles; staff using same form may need a separate code path if they should see staff docs — **product decision**). |

---

## Audience Field Consolidation Plan

### Canonical decision

**`field_audience` (list: public | vendor | staff) is canonical** for access and retrieval.

**Evidence:**

- `hook_node_access` in `myeventlane_help_centre.module` uses **`field_audience` only**; comments state `field_help_audience` is deprecated.  
- `field.field.node.help_article.field_help_audience.yml` description: deprecated; use `field_audience`.  
- `HelpRetriever` / `UnifiedHelpRetriever` filter on **`field_audience`**; deprecated `HelpArticleRetriever` did so without full policy checks.  
- `HelpContentSeeder` / `myeventlane_help_centre.install` sync taxonomy → list for backward compatibility.

### Current usage map

| Location | Field | Classification |
|---------|-------|------------------|
| `config/sync/search_api.index.mel_content.yml` | Both `field_audience` and `field_help_audience` indexed | **Should** keep `field_audience`; **plan removal** of `field_help_audience` from index when content migrated |
| `config/sync/views.view.mel_help_search.yml`, `mel_docs_register.yml`, `mel_help_organiser_help.yml`, `mel_help_articles_by_audience.yml` | `field_audience` | **Must use `field_audience`** |
| `config/sync/core.entity_form_display.node.faq.default.yml`, `help_article` / `faq` view displays | Both fields on some bundles | **Forms:** align with canonical list; hide or read-only deprecated taxonomy per install hooks comments |
| `web/modules/custom/myeventlane_help_centre/src/Service/HelpContentSeeder.php` | Writes both for sync | **Transitional** — keep until migration complete |
| `web/modules/custom/myeventlane_help_assistant/tests/src/Kernel/HelpRetrieverKernelTest.php` | Creates only `field_help_audience` on test bundle | **Test debt** — does not match production retrieval (production uses `field_audience`); update tests when consolidating |
| `web/modules/custom/myeventlane_help_improvement/...`, `DocumentationOpportunityAggregationService.php` | Reads `field_audience` on help_article | **Canonical** |

### Migration plan (steps only)

1. Content audit: nodes where `field_help_audience` semantics disagree with `field_audience` (install already has sync direction taxonomy → list).  
2. Freeze new editorial use of `field_help_audience` in UI (hide / read-only on form displays).  
3. Reindex `mel_content` after removing `field_help_audience` from `field_settings` when safe.  
4. Remove redundant Views filters or displays referencing taxonomy audience if any remain.  
5. Update kernel tests to use `field_audience` list field to match production.

### Risk

- **Editorial confusion:** Two fields on same bundle until UI is cleaned up.  
- **Search/filter mismatch:** Search index still exposes `field_help_audience` until removed — could confuse admins or custom queries.

---

## Performance and index optimisation (awareness only)

**Current pattern (intentional):** retrieval runs via **Search API** (`mel_content`), then **nodes are reloaded** and **validated again** in PHP (`UnifiedHelpRetriever` / `HelpRetriever` post-filters). This is correct for safety and for catching stale index rows.

**If volume grows**, this path can become a **hotspot** (extra node loads per hit).

**Future optimisation (do not implement prematurely):** move additional filters into the index and query layer (e.g. index `field_help_ai_allowed` / `field_help_status` and add query conditions) **only** after product sign-off and reindex strategy are defined — trading stricter index coupling for fewer post-load checks.

**No code change required** for the current milestone.

---

## Document history

| Section | Prompt reference |
|---------|------------------|
| Policy, gaps, risk | PROMPT 1 |
| Entry points table | PROMPT 2 |
| Mismatch analysis | PROMPT 3 |
| Deprecation plan | PROMPT 4 |
| Audience consolidation | PROMPT 5 |
| Compliance guard, vendor/staff rule, performance note | Final review hardening |
