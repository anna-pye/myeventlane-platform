# MEL Help Unification — Regression Report

**Date:** 2026-03-27  
**Method:** Static verification against `UnifiedHelpRetriever::validateNode()` and `VendorAiAssistantForm` wiring. **Automated PHPUnit / browser tests were not executed** in this pass (no full Drupal stack run in CI here).

---

## Test matrix

| # | Scenario | Expected | Result | Evidence |
|---|----------|----------|--------|----------|
| 1 | `field_help_ai_allowed` = FALSE | Must **not** appear in vendor AI excerpts | **Pass** | `validateNode()` returns FALSE with reason `ai_not_allowed` (`web/modules/custom/myeventlane_help_shared/src/Service/UnifiedHelpRetriever.php`). `HelpRetriever` already excludes; unified layer duplicates. |
| 2 | `field_audience` contains `staff` only | Must **not** appear | **Pass** | `nodeAudienceAllowed()` sets `staff_audience` and returns FALSE. |
| 3 | `field_help_status` = `review` (field non-empty) | Must **not** appear | **Pass** | `validateNode()` requires `published` or `approved`; else `status_invalid`. |
| 4 | Valid article (published, AI allowed, status OK, public/vendor, access OK) | **May** appear (subject to Search API relevance) | **Pass** | Rows pass `validateNode()` unchanged; same as `HelpRetriever` output for valid nodes. |
| 5 | Mixed `field_audience` = `vendor` + `staff` | Must **not** appear | **Pass** | First `staff` delta triggers `staff_audience` before intersection logic. |

---

## Unexpected behaviour

- **None identified** in code review. If the Search API index returns a nid that no longer passes live node checks (e.g. edited after index), the unified layer **drops** the row and emits a **`myeventlane_help`** notice — this is intentional defence in depth, not a regression.

---

## Follow-up (recommended)

1. Run `phpunit` with a kernel test that stubs `HelpRetriever` or uses real index fixtures to assert `retrieveForUser()` filtering.  
2. Manual QA: submit Vendor AI question on staging with fixtures for cases 1–5.  
3. Enable **`myeventlane_help_shared`** (`config/sync/core.extension.yml` updated) and run `drush cr` after deploy.

---

## Sign-off

| Criterion | Status |
|-----------|--------|
| Invalid content excluded by policy layer | **Pass** (static) |
| No regressions vs intended contract `{nid, title, url, excerpt}` | **Pass** (`VendorAiAssistantForm::mapUnifiedRowsToVendorAiArticles`) |
