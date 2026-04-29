# MEL — Help centre and staff-only access verification (Task 8)

**Date:** 2026-04-29  
**Branch:** `cursor/onboard-storage-fix-128b4`  
**Latest commit (at audit):** `82daf37f` — `docs(audit): add vendor dashboard visibility audit` (Task 7 audit **committed**; working tree **clean** at preflight)  
**Scope:** Diagnostic only — no product code changes, no config export, no secrets, not Task 9.

## Executive summary

| Severity | Count | Summary |
|----------|------|---------|
| **P0** | 0 | No confirmed staff-only or staff-audience `help_article` leakage to anonymous users, public Search API–backed Help Assistant, or public help search in local checks. `staff_playbook` is not indexed on `mel_content`. |
| **P1** | 0 | No confirmed bypass of audience controls on reviewed paths; see assumptions and **follow-ups** where prod-only behaviour could differ. |
| **P2** | 2 | (1) Earlier anonymous assistant curl tests failed until JSON body was passed reliably — operator error risk for manual QA. (2) `help_landing_page` still uses taxonomy `field_help_audience` while `hook_node_access` documents deprecated taxonomy for **article** access — clarify product intent for landing pages. |

**Recommended next task:** **Task 9** — Discovery / category / event page polish and mobile / accessibility pass — **unless** product wants a narrow **Task 8B** titled *“Harden mel_help_search exposed audience filter for staff-tagged help_article (when such nodes exist)”* after staff editorial workflow goes live.

---

## Phase 1 — Preflight

**Commands run:**

| Command | Result |
|---------|--------|
| `git branch --show-current` | `cursor/onboard-storage-fix-128b4` |
| `git status --short` | *(empty — clean)* |
| `git log -10 --oneline` | Latest: `82daf37f docs(audit): add vendor dashboard visibility audit` |
| `composer validate` | `./composer.json is valid` |
| `ddev drush cr` | `[success] Cache rebuild complete.` |

**Task 7 audit committed:** Yes — top commit is the vendor dashboard visibility audit doc.

---

## Phase 2 — Routes, controllers, services (map)

### Drush route sample (`ddev drush route | grep -Ei "help|assistant|article|playbook|staff|search|ask|ai"`)

Representative MEL routes (non-exhaustive; full dump filtered in session):

| Route name | Path | Notes |
|------------|------|--------|
| `myeventlane_help_centre.home` | `/help` | `HelpCentreController::homepage` — `_permission: access content` |
| `myeventlane_help_centre.search` | `/help/search` | `HelpCentreController::searchIndex` — `_access: TRUE` |
| `myeventlane_help_centre.vendor_help` | `/vendor/help` | **301** redirect to help hub (see `HelpCentreController::vendorHelp`) |
| `myeventlane_help_centre.vendor_topic` | `/vendor/help/topic/{category}` | `_permission: view vendor help centre` |
| `myeventlane_help_assistant.page` | `/help/assistant` | `GET` — **301** to `/help#mel-help-assistant` when help centre module present |
| `myeventlane_help_assistant.ask` | `/help/assistant` | `POST` — JSON assistant — `_access: TRUE` (flood + retrieval enforce policy) |
| `myeventlane_help_centre_ai.ask` | `/help/ask` | `_permission: access myeventlane help assistant` (see `myeventlane_help_centre_ai.routing.yml`) |
| `myeventlane_staff_playbooks.governance_dashboard` | `/admin/myeventlane/governance` | Staff playbooks governance |
| `myeventlane_staff_playbooks_ai.summary` | `/admin/myeventlane/playbooks/{node}/ai/summary` | Staff playbook AI summary (admin path) |
| `mel_search.view` | `/search` | Public site search |
| `mel_search.autocomplete` | `/search/autocomplete` | Events / venues / categories only (see controller docblock) |

### Modules inspected (grep + spot reads)

- `web/modules/custom/myeventlane_help_centre`
- `web/modules/custom/myeventlane_help_assistant`
- `web/modules/custom/myeventlane_help_shared`
- `web/modules/custom/myeventlane_help_centre_ai`
- `web/modules/custom/myeventlane_help_improvement`
- `web/modules/custom/myeventlane_staff_playbooks`
- `web/modules/custom/myeventlane_search`

### Answers (checklist)

1. **Public help home route/controller:** `myeventlane_help_centre.home` → `/help` → `HelpCentreController::homepage`.
2. **Help article content type:** `help_article` (canonical audience: `field_audience` list field).
3. **Help search route/view:** `/help/search` → View `mel_help_search`, display `block_search`.
4. **Help Assistant:** JSON `POST` `HelpAssistantController::ask`; GET `/help/assistant` redirects to hub fragment.
5. **Vendor help:** `/vendor/help` redirects to canonical `/help`; vendor topics via `/vendor/help/topic/{category}` with vendor permission.
6. **Staff playbook content type:** `staff_playbook` — `myeventlane_staff_playbooks` module; `hook_node_access` requires `view staff playbooks` or `administer staff playbooks`.
7. **Retrieval services:** `myeventlane_help_assistant.retriever` (`HelpRetriever`), `UnifiedHelpRetriever` (`myeventlane_help_shared`) for explicit account context (e.g. vendor AI).
8. **Access control:** `myeventlane_help_centre_node_access` for `help_article` / `faq`; `myeventlane_staff_playbooks_node_access` for `staff_playbook`; Search API `content_access` processor on `mel_content`; `HelpRetriever` post-filters nodes.

---

## Phase 3 — Content types and fields (sample)

**Command:** `ddev drush php-eval` — sample up to 20 nodes per type (`help_article`, `staff_playbook`, `help_landing_page`), fields `field_audience`, `field_help_audience`, `field_help_status`, `field_help_ai_allowed`, `field_help_category` (no body text captured).

**Observations:**

- **help_article:** Sample shows `field_audience` populated (`public` or `vendor`); `field_help_audience` **empty** on samples; `field_help_status` published; `field_help_ai_allowed` enabled on samples.
- **staff_playbook:** Titles only in log (no internal body pasted). NIDs e.g. 1475–1484 in sample.
- **help_landing_page:** `field_audience` **empty** on samples; **`field_help_audience`** taxonomy populated (legacy IA).
- **Staff-audience help_article:** Query `type=help_article` + `field_audience.value=staff` returned **count 0** in this database — staff-only editorial articles may not exist yet; governance paths still matter when they do.

---

## Phase 4 — Access-control review

### `hook_node_access` — help_article / faq (`myeventlane_help_centre.module`)

- **`field_audience` contains `staff`:** Allow view only if account has **`administer escalations`**; otherwise forbidden.
- **Vendor-only** (`vendor` present, **no** `public`): Anonymous → forbidden; authenticated passes neutral/other grants.
- Documents **`field_help_audience` (taxonomy) deprecated** for access — access uses **`field_audience` only** for these bundles.

### `hook_node_access` — staff_playbook (`myeventlane_staff_playbooks.module`)

- View/create denied unless **`view staff playbooks`** or **`administer staff playbooks`**.

### Help Assistant permissions (`myeventlane_help_assistant.permissions.yml`)

- **`access myeventlane help assistant`** — GET page/block access for standalone flows; hub integration uses fragment on `/help`.
- **`/help/ask`** (help_centre_ai): requires **`access myeventlane help assistant`** (stricter than POST `/help/assistant`).

### Documented matrix

| Content | Anonymous | Logged-in (typical) | Staff playbook role / perm |
|---------|-----------|---------------------|------------------------------|
| `help_article` public | Yes (with `access content` + grants) | Yes | N/A |
| `help_article` vendor-only | No (hook forbids) | Yes (if permitted) | N/A |
| `help_article` staff audience | No | No | **`administer escalations`** only |
| `staff_playbook` | No | No | **`view staff playbooks`** / **`administer staff playbooks`** |

**Entity vs route:** Help listings use Views with cache context `user.node_grants:view`; node access applies to rendered rows. **Search API:** `mel_content` uses **`content_access`** processor (preprocess index/query). **HelpRetriever** adds explicit `type`, `status`, `field_audience` query filters and **post-load** checks (`view`, AI allow-list, status, audience).

**Route-only reliance:** Partially mitigated — `/help/search` is open, but **filters + grants + hook** apply. **`POST /help/assistant`** is open — policy enforced in **`HelpRetriever`** + **`HelpAssistantService`** (grounding), not route permission alone.

---

## Phase 5 — Search API and retrieval filters

### Config files listed

- `config/sync/search_api.index.mel_content.yml`
- `config/sync/search_api.index.mel_categories.yml`
- `config/sync/search_api.index.mel_vendors.yml`
- `config/sync/search_api.server.myeventlane_db.yml`
- Plus `search_api.settings.yml`, `search_api_db.settings.yml`

### `mel_content` bundles (`search_api.index.mel_content.yml`)

Selected: `article`, `event`, `help_article`, `help_landing_page`, `page` — **`staff_playbook` not included.**

Processors include **`content_access`** and **`entity_status`**. Indexed **`field_audience`** (canonical).

### Drush

- `ddev drush search-api:list` — `mel_content` **enabled**, 100% indexed (76/76 at audit time).
- `ddev drush search-api:server-list` — `myeventlane_db` enabled.

### HelpRetriever (`HelpRetriever.php`) — checklist

| # | Question | Answer |
|---|----------|--------|
| 1 | Filter bundle `help_article`? | **Yes** — `addCondition('type', 'help_article')`. |
| 2 | Exclude `staff_playbook`? | **Yes** — not in index bundles; query also restricts type. |
| 3 | Filter `field_audience` by context? | **Yes** — anonymous: `public` only; authenticated: `public` + `vendor`. |
| 4 | Published / status? | **Yes** — `status` = 1; `field_help_status` must be `published` or `approved` when field present. |
| 5 | `field_help_ai_allowed`? | **Yes** — must be truthy. |
| 6 | Vendor retrieval? | **Public + vendor** list values for authenticated users (not staff multi-value). |
| 7 | Public retrieval? | **Public** list values only for anonymous. |
| 8 | Deprecated `field_help_audience`? | **Grep** under `web/modules/custom`: **only** comment in `myeventlane_help_centre.module` — **not** used in retrieval paths reviewed. |

### UnifiedHelpRetriever

Re-applies policy after account switch; rejects `staff` audience values and non–public/vendor labels.

### Public site search autocomplete (`SearchAutocompleteController`)

Comment: events, venues, categories — **no help pages**. Does not broaden help/staff exposure.

---

## Phase 6 — Manual / HTTP checks (local DDEV)

**Host:** `https://myeventlane.ddev.site` (from `ddev describe`).

| Check | Result |
|-------|--------|
| Anonymous `GET /help` | **HTTP 200** — page loads |
| Anonymous `GET /help/search?q=Payouts` | **HTTP 200** — matches present for “Payouts” (public-facing search UX); vendor-only body content not verified by title substring alone |
| Anonymous `POST /help/assistant` with **correct JSON body** (heredoc) `{"question":"reset password"}` | **HTTP 200** `status: ok` — sources include public article nid **1496** only |
| Anonymous `POST /help/assistant` `{"question":"Getting started as an organiser vendor onboarding"}` | **Fallback**, **empty** `sources` / `articles` — no vendor-only article surfaced in payload |
| `curl` quoting mistake earlier produced empty question → misleading fallback | Document as **P2** QA ergonomics, not a product bug |
| `GET https://vendor.myeventlane.ddev.site/vendor/help` (no session) | **302** to `/user/login?destination=...` — vendor hostname gate; full vendor-logged-in browse **not** exercised |

**Staff playbook HTTP:** Not loaded in browser; admin paths exist in route list. No staff-only body text recorded.

---

## Phase 7 — Findings classification

### P0 (none confirmed)

- No evidence that anonymous assistant responses included staff playbook text or staff-only `help_article` (no staff-tagged `help_article` in DB; retrieval code rejects staff audience).
- `staff_playbook` not on `mel_content` index.
- Vendor-biased anonymous prompt returned empty sources (no leak of vendor-only titles in JSON).

### P1 (none confirmed)

- **`mel_help_search`** exposes **Audience** filter (`identifier: audience`) including staff — **mitigated** by node access for `staff` audience on `help_article` when nodes exist; current DB has **zero** staff-audience help articles. Recommend retesting when editorial publishes staff articles.
- Vendor-only visibility on public hub with `?context=vendor` — product expectation not changed in this task; **not** flagged as failure.

### P2

- Manual **`curl`** must send valid JSON (shell quoting) — easy false “empty retrieval” signal.
- **`help_landing_page`** uses **`field_help_audience`** taxonomy while articles use **`field_audience`** — document alignment for IA-only pages.

---

## Phase 8 — Files changed

| File | Action |
|------|--------|
| `docs/audits/mel-help-staff-access-verification.md` | **Created** (this audit) |

No PHP/module/theme changes.

---

## Commands reference (rolled up)

```bash
git branch --show-current
git status --short
git log -10 --oneline
composer validate
ddev drush cr
ddev drush route | grep -Ei "help|assistant|article|playbook|staff|search|ask|ai" || true
ddev drush search-api:list
ddev drush search-api:server-list
ddev drush config:get myeventlane_help_assistant.settings
ddev drush search-api:status mel_content
# Plus php-eval samples and curl HTTPS checks documented above
```

---

## Recommended next task

- **If no P0/P1 to fix:** **Task 9** — Discovery / category / event page polish and mobile / accessibility pass.
- **Optional narrow follow-up (8B):** When staff-audience `help_article` nodes exist in production, repeat anonymous **`/help/search?audience=staff&q=…`** and assistant prompts to confirm View + teaser modes never leak summaries to anonymous users beyond core node access expectations.
