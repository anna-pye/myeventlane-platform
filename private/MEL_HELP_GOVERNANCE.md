# MEL Help System Governance

**Purpose:** Operational and editorial rules that sit on top of the technical retrieval policy in `private/MEL_HELP_RETRIEVAL_POLICY.md`.  
**Evidence:** Drupal config and custom modules in this repository; **role-by-role permission matrices are not exported here** — confirm who can create/publish `help_article` nodes in your environment (`config/sync/user.role.*.yml` and the Permissions UI).

---

## 1. Publishing

### Who can publish

- **Technical enforcement:** Standard Drupal node access, permissions, and the **Editorial** content moderation workflow for `help_article` (`config/sync/workflows.workflow.editorial.yml` lists `help_article` under `entity_types.node`).
- **Default moderation state:** Workflow config sets `default_moderation_state: draft` for moderated types — new revisions start as draft until transitioned.
- **Governance rule (operational):** Assign “create / edit own” vs “edit any” vs “revert revisions” for `help_article` only to content/support roles that understand audience and AI flags. **Verify** actual role grants in your deployment.

### Required fields before publish (retrieval-aligned)

These are **minimum bar for content that must appear in the Help Assistant** (per `HelpRetriever`):

| Requirement | Rationale |
|-------------|-----------|
| `status` = published | Search API and PHP checks |
| `field_audience` populated with at least one of `public` / `vendor` (and **not** used for staff-only public AI) | Pre-filter + post-filter |
| `field_help_ai_allowed` = true | Assistant post-filter |
| `field_help_status` empty **or** `published` / `approved` | Assistant post-filter |
| No `staff` value in `field_audience` if the article must appear in public/vendor AI | Assistant rejects any `staff` value |

**General Help Centre browse** still uses Views + `hook_node_access`; an article can be visible on the web but **blocked from AI** if `field_help_ai_allowed` is false — that is a valid product pattern.

---

## 2. AI eligibility (`field_help_ai_allowed`)

### When it may be TRUE

- **Governance rule:** Set to **true** only when the body/summary has been reviewed for: factual accuracy, absence of internal-only procedures, and safe wording for generative reuse.
- **Technical enforcement:** Help Assistant **requires** true; Vendor escalation AI uses **`UnifiedHelpRetriever`**, which enforces the same rule on a second pass (see `private/MEL_HELP_RETRIEVAL_POLICY.md`).

### Who approves

- **Not encoded in repo** as a dedicated workflow state for this boolean. **Operational recommendation:** tie approval to the same role that may transition `field_help_status` to `published`/`approved` or an explicit “documentation owner” checklist.

### Draft generator behaviour

- `web/modules/custom/myeventlane_help_improvement/src/Service/HelpArticleDraftGeneratorService.php` sets `field_help_ai_allowed` to **0** when audience is `staff`, otherwise defaults from AI output key `allow_in_help_assistant`. Human review is required before publish (module description and permissions emphasise drafts stay unpublished).

---

## 3. Review lifecycle

### Editorial list field

- **`field_help_status`** allowed values:** `draft`, `review`, `approved`, `published`, `archived` (`config/sync/field.storage.node.field_help_status.yml`).
- **Help Assistant** only allows **`published`** and **`approved`** when the field is set.

### Review frequency and expiry

- **`field_last_reviewed`** appears on `help_article` and in **`mel_docs_register`** / review display (`config/sync/views.view.mel_docs_register.yml`).
- **UI cue:** `myeventlane_help_centre_preprocess_views_view_field()` adds a “Review due” pill when last reviewed is older than **180 days** (computed in PHP).

### Expired review handling

- **Current code:** Visual indicator only in the docs register review display; **no automatic unpublish or AI disable**.  
- **Governance rule:** Define whether overdue review triggers `field_help_status` → `review`, `field_help_ai_allowed` → false, or a ticket — **process decision outside codebase**.

---

## 4. Ownership (`field_content_owner`)

- **Field:** `field_content_owner` (entity reference to user) on `help_article` — see `config/sync/field.field.node.help_article.field_content_owner.yml`.
- **Importer:** `web/modules/custom/myeventlane_docs_importer/src/Service/DocsImportService.php` can set owner from CSV when provided.
- **Visibility:** Included in default and teaser view displays and **mel_docs_register** for operational tracking.
- **Governance rule:** Every production help article should have an owner (accountable editor); register view is the operational source of truth for audits.

---

## 5. Logging and observability

### Help Assistant

- **`HelpAnalyticsService::logAiEvent`:** event types `ai_query`, `ai_success`, `ai_low_confidence` — stores query snippet, result count, confidence, optional timing (`web/modules/custom/myeventlane_help_centre/src/Service/HelpAnalyticsService.php`). Invalid event type → **error** log on `myeventlane_help` channel.
- **`HelpRetriever`:** empty question → no log; index missing → **error**; throwable → **error** with message.
- **`HelpAssistantService`:** AI manager failures and invalid JSON → **error**; grounding failure → **notice**.

### Help search (zero results)

- **`logSearch`:** writes `search` and, when `resultCount === 0`, **`zero_result`** (`HelpAnalyticsService`).

### Contextual help

- Unknown context / missing article / register mapping issues → **warning** logs in the help centre resolver channel.

### Vendor AI retrieval

- **Vendor AI (unified path):** inherits `HelpRetriever` logging; policy mismatches → **`myeventlane_help`** channel (`UnifiedHelpRetriever`). Deprecated **`HelpArticleRetriever`** remains in tree only.

### Failed retrievals (governance ask)

- **Operational rule:** Monitor `myeventlane_help_assistant` / **`myeventlane_help`** (unified policy filter) after deploys or index outages.  
- **Product rule:** Define SLO for `mel_content` index health because Help Assistant returns **no** articles if the index is missing.

### Policy filter drops as data-quality signals

**UnifiedHelpRetriever** logs `notice` events on channel **`myeventlane_help`** when a search hit is dropped after the policy pass (message prefix: `Unified help retrieval policy filter`, fields include `nid`, `title`, `reason`).

**Governance rule:** **Repeated** policy-filter drops for the **same node** (same `nid` / title in logs over a short window) should trigger **editorial or engineering review** of that content. Persistent drops may indicate:

- Incorrect **`field_audience`** tagging (e.g. mixed or stale values vs index)
- Incorrect **`field_help_ai_allowed`** or **`field_help_status`** relative to publish intent
- **Stale Search API index** data vs live node (reindex or index processor review)

**Operational recommendation:** Periodically aggregate or alert on log lines matching that prefix (e.g. log platform query by `nid`, threshold N per day). This turns defensive logs into **feedback for documentation quality and index health**, not only incident response.

---

## 6. Cross-reference

- **Retrieval rules and technical risk:** `private/MEL_HELP_RETRIEVAL_POLICY.md`  
- **Discovery notes (if present):** `private/MEL_DISCOVERY_V1.md` (may overlap; policy doc is authoritative for retrieval)

---

## Document history

| Section | Prompt reference |
|---------|------------------|
| Full document | PROMPT 6 |
| Policy filter aggregation / data quality | Final review hardening |
