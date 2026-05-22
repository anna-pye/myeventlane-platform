# Staging Help Content Inventory

## Scope

- **Environment:** Local DDEV site (same codebase as repo; database reflects current local/staging import — **Needs verification** if production differs).
- **Date:** 2026-05-22
- **Mode:** Read-only. No content, code, config, Search API reindex, or commits were changed.
- **Reference docs:** `docs/content-audit/current-help-setup.md`, `help-retrieval-and-access-audit.md`, and related audit files.

---

## Commands Run

| Command | Result |
|---------|--------|
| `git status --short` | Untracked `docs/content-audit/`; unrelated `package.json` change |
| `ddev drush cr` | Success |
| `ddev drush search-api:list` | `mel_content` enabled |
| `ddev drush search-api:status mel_content` | 100% — 58/58 indexed |
| `ddev drush entity:info node` | **Not available** on this Drush build |
| `ddev drush sqlq "SELECT type, status, COUNT(*) …"` | See node counts below |
| `ddev drush sqlq` (support_procedure count) | 0 nodes |
| `ddev drush php:eval` | `help_article` field inventory (×3), `staff_playbook` access check, Search API bundle sample |
| `ddev drush route \| grep -Ei …` | Help/assistant/staff routes listed in prior audit |
| `ddev drush route \| grep -i internal/governance` | Staff/governance routes only |

No temporary files were created.

---

## Help Article Counts

| Metric | Count |
|--------|------:|
| Total `help_article` nodes | 31 |
| Published (`status = 1`) | 31 |
| Unpublished | 0 |
| `field_audience` = public (nodes; single-value rows) | 15 |
| `field_audience` = vendor (nodes; single-value rows) | 16 |
| `field_audience` = staff | 0 |
| Missing `field_audience` | 0 |
| `field_help_status` = published | 30 |
| `field_help_status` = draft | 1 |
| `field_help_status` = review / approved / archived | 0 |
| Missing `field_help_status` | 0 |
| `field_help_ai_allowed` = true | 31 |
| `field_help_ai_allowed` = false | 0 |
| `field_help_ai_allowed` empty | 0 |
| Using deprecated `field_help_audience` (any value) | 0 |
| `field_help_seed_key` populated | 0 |
| `field_featured_help` = true | 0 |

**By help article type (taxonomy):** Guide 27, FAQ 3, Policy 1.

**Indexed in `mel_content`:** 31 `help_article` nodes (matches node count).

**Compared to YAML seeds:** Install config defines 10 seeded articles (`myeventlane_help_centre.help_content.yml`). Live site has **31** published articles and **no** `field_help_seed_key` values — content has outgrown seeds; seed keys not recorded on nodes (**Needs verification** whether seeder was run without seed_key persistence).

---

## Help Article Risk List

Assistant rules applied from `HelpRetriever.php`: published, `field_help_ai_allowed`, status `published|approved`, no `staff` audience, node access, audience `public` (anonymous) or `public|vendor` (authenticated).

| Title | Audience | Help Status | Published | AI Allowed | Risk | Action |
|-------|----------|-------------|-----------|------------|------|--------|
| Accessibility at events | public | published | yes | yes | None | None |
| Adding events to your calendar | public | published | yes | yes | None | None |
| Booking multiple tickets | public | published | yes | yes | None | None |
| Checking event details before booking | public | published | yes | yes | None | None |
| Choosing event categories | vendor | published | yes | yes | Expected vendor-only browse | None — appears on `/help/vendors` |
| Communicating with attendees | vendor | published | yes | yes | Expected vendor-only browse | None |
| Contacting support | public | published | yes | yes | None | None |
| Creating an account | public | published | yes | yes | None | None |
| Editing your event | vendor | published | yes | yes | Expected vendor-only browse | None |
| Event visibility and promotion | vendor | published | yes | yes | Expected vendor-only browse | None |
| Getting started as an organiser | vendor | published | yes | yes | **IA mismatch:** not on `/help/organisers` (view filters `public` only) | Add `public` tag, duplicate summary for organisers, or change organiser view filter — product decision |
| Handling refunds as an organiser | vendor | published | yes | yes | IA mismatch (organiser hub) | Same as above |
| How to buy tickets | public | published | yes | yes | None | None |
| How to create an event | vendor | published | yes | yes | IA mismatch (organiser hub) | Same as above |
| How to find events | public | published | yes | yes | None | None |
| How to save events | public | published | yes | yes | None | None |
| Managing attendees | vendor | published | yes | yes | Expected vendor-only browse | None |
| Payouts and fees | vendor | published | yes | yes | Expected vendor-only browse | None |
| Refunds explained | public | published | yes | yes | None | None |
| Resetting your password | public | published | yes | yes | None | None |
| Setting event capacity | vendor | published | yes | yes | Expected vendor-only browse | None |
| Setting Up Ticketing for Your Event | vendor | **draft** | yes | yes | **Assistant blocked** (status draft); published node inconsistent with editorial status | Set `field_help_status` to `published` or unpublish node |
| Setting up tickets | vendor | published | yes | yes | Expected vendor-only browse | None |
| Tracking event performance | vendor | published | yes | yes | Expected vendor-only browse | None |
| Understanding ticket types | public | published | yes | yes | None | None |
| Updating ticket availability | vendor | published | yes | yes | Expected vendor-only browse | None |
| Using filters to find events | public | published | yes | yes | None | None |
| Using images for your event | vendor | published | yes | yes | Expected vendor-only browse | None |
| What happens after booking | public | published | yes | yes | None | None |
| What to bring to an event | public | published | yes | yes | None | None |
| Writing a strong event description | vendor | published | yes | yes | Expected vendor-only browse | None |

### Targeted lists (from inventory queries)

**`field_audience` = staff:** None.

**`field_help_ai_allowed` = true but status not published/approved:**

- Setting Up Ticketing for Your Event (nid 1521) — `draft`

**Public/vendor articles with AI false or empty:** None (all 31 have AI allowed).

**Published but not safe for Help Assistant (authenticated user):**

- Setting Up Ticketing for Your Event (nid 1521) — `field_help_status = draft`

**Published but not safe for Help Assistant (anonymous user):** All 15 `public` articles are safe. Sixteen `vendor`-only articles are correctly excluded (not a defect).

---

## Staff Playbook Check

| Metric | Value |
|--------|------:|
| Total `staff_playbook` nodes | 10 |
| Published | 10 |
| Unpublished | 0 |
| Anonymous `view` access | **0** (all forbidden) |

**Titles (nid):** Refund decision flow (1475), Vendor dispute handling (1476), Event moderation checklist (1477), Escalation handling guide (1478), Handling support requests (1479), Reviewing refund edge cases (1480), Content quality review (1481), Handling repeated complaints (1482), Vendor onboarding support (1483), Ticket issue resolution (1484).

**Browse routes (staff only):**

| Route | Permission |
|-------|------------|
| `/admin/myeventlane/governance` | `administer escalations` |
| `/admin/myeventlane/playbooks/{node}/ai/summary` | Staff playbooks AI |
| Escalation canonical sidebar | `view staff playbooks` |

**No** Views list `staff_playbook` (grep `config/sync/views.view.*`).

**Search API `mel_content`:** `staff_playbook` **not** in indexed bundles; **0** staff playbooks in index query sample.

**Risk:** Low on this environment — access hook blocks anonymous; playbooks not in Search API or public Views.

---

## Support Procedure Check

| Item | Finding |
|------|---------|
| Bundle exists | Yes — `config/sync/node.type.support_procedure.yml` |
| Live nodes | **0** |
| View | `mel_help_internal_procedures` — bundle filter `support_procedure` only |
| View access plugin | `perm: access content` |
| View displays | `default`, `block_internal` only — **no page route** |
| Block placement | **0** blocks placed for `views_block:mel_help_internal_procedures-block_internal` |

**Reachability today:** With zero nodes and no block placement, anonymous/authenticated/vendor users are unlikely to see content. **Risk if nodes are added without access hardening:** View does not filter `field_audience` or staff permissions — would rely solely on `hook_node_access` for `support_procedure` (**Needs verification** — no `support_procedure` hook found in `myeventlane_help_centre.module`; only `help_article` and `faq`).

**Recommended verification:** Confirm whether `support_procedure` has `hook_node_access` elsewhere or inherits unrestricted published node access.

---

## Search API Check

| Item | Finding |
|------|---------|
| Index | `mel_content` — enabled, 100% (58/58) |
| Indexed bundles (config) | `blog_post`, `event`, `help_article`, `help_landing_page`, `page` |
| `staff_playbook` excluded | Yes (config + live query) |
| `support_procedure` excluded | Yes |
| `help_article` in index | Yes — 31 items |
| Live bundle breakdown (sample query) | event 17, help_article 31, help_landing_page 5, page 2 |

**Retriever filters (not in index):** `HelpRetriever` adds `type = help_article`, `status = 1`, `field_audience` OR group, then PHP checks `field_help_ai_allowed`, `field_help_status`, node access (`web/modules/custom/myeventlane_help_assistant/src/Service/HelpRetriever.php`).

**Risk:** Low for staff playbook leakage via Search API. Index includes **events** and **pages** — Assistant query restricts to `help_article` only.

---

## Help View Visibility Check

### Views listing `help_article`

| View ID | Audience filter | `field_help_status` | `field_help_ai_allowed` | Notes |
|---------|-----------------|---------------------|-------------------------|-------|
| `mel_help_attendee_help` | `public` | No | No | Used on `/help/attendees` |
| `mel_help_organiser_help` | `public` | No | No | Used on `/help/organisers` — **same 15 nodes as attendee** |
| `mel_help_vendor_help` | `vendor` | No | No | Used on `/help/vendors` — 16 nodes |
| `mel_help_search` | Exposed optional | No | No | `/help/search` |
| `mel_help_featured_articles` | None | No | No | **`field_featured_help` — 0 nodes flagged** → hub featured area likely empty |
| `mel_help_faq` | None (type = FAQ term) | No | No | 3 FAQ-type articles |
| `mel_help_policies_help` | `public` + Policy type | No | No | 1 Policy-type public article |
| `mel_help_category_listing` | No | No | No | Category pages |
| `mel_help_related_articles` | No | No | No | Related block |
| `mel_help_centre_homepage` | help_article | No | No | Hub blocks |
| `mel_help_articles_by_audience` | No | No | No | Includes deprecated `faq` bundle in filter |
| `mel_docs_register` | Admin register | No | No | Staff editorial |
| `mel_help_feedback_admin` / analytics views | Admin | — | — | Staff |

### Views listing `staff_playbook`

None in `config/sync`.

### Views listing `support_procedure`

| View ID | Access | Placement |
|---------|--------|-----------|
| `mel_help_internal_procedures` | `access content` | Block display exists; **not placed** |

### Browse vs Help Assistant mismatches

| Mismatch | Severity | Detail |
|----------|----------|--------|
| Views omit `field_help_ai_allowed` / `field_help_status` | Low | Browse can show articles Assistant would drop — only **one** live article (1521) affected today |
| `/help/organisers` vs vendor-tagged organiser content | Medium (UX) | 16 organiser-topic articles use `vendor` audience; organiser index only lists `public` articles |
| `/help/attendees` vs `/help/organisers` | Low | Identical listing (15 public articles) — consider merging or differentiating |
| Featured block empty | Low | No articles have `field_featured_help` |
| Hub FAQ block | OK | 3 FAQ-type articles exist |

**Node access:** Vendor-only articles deny anonymous view (confirmed on nid 1507) — aligns with `myeventlane_help_centre_node_access`.

---

## Recommended Fix Queue

Do not implement in this task — ordered by priority:

### 1. Access-control risks

1. **Verify `support_procedure` node access** before creating any nodes — view uses `access content` and has no audience filter; bundle currently empty.
2. **Keep `staff_playbook` out of `mel_content`** — confirmed; re-check after any index config change.

### 2. Assistant safety risks

1. **Fix nid 1521** — “Setting Up Ticketing for Your Event”: published node with `field_help_status = draft` and AI allowed — excluded from Assistant; confusing for editors.
2. **Confirm anonymous Help Assistant** only surfaces `public` articles (15 nodes) — matches retriever design.

### 3. Stale / inconsistent help articles

1. Decide editorial rule when `field_help_status` ≠ published node status.
2. Optionally set `field_featured_help` on top articles for hub (currently zero).

### 4. Missing AI flags

- None on this site (all 31 allowed). Maintain on new articles.

### 5. IA / missing plain-English help

1. Resolve **organiser hub vs vendor audience** — 16 vendor articles invisible on `/help/organisers`.
2. Continue gap articles from `help-docs-gap-analysis.md` (checkout failures, Stripe, waitlist, wallet) — many attendee topics now exist (15 public articles cover core flows).
3. Map live titles to `top-20-priority-help-articles.md` — several priorities already published (buy tickets, find events, account, refunds, support).

### 6. Blog / FAQ backlog

1. Only 3 FAQ-type articles — expand FAQ set per `faq-backlog.md` if hub FAQ block should be richer.
2. Blog remains separate (`/blog`, `blog_post` in index).

---

## Final Summary

### What is safe

- **No staff-audience `help_article` nodes** and **no `field_help_audience` usage** on help articles.
- **All 31 help articles** have `field_audience`, `field_help_status`, and `field_help_ai_allowed` populated.
- **`staff_playbook`** (10 nodes): anonymous cannot view; not in Search API; no public View.
- **Help Assistant alignment** for **15 public articles**: safe for anonymous and authenticated retrieval.
- **16 vendor articles**: correctly hidden from anonymous Assistant; available to authenticated users with vendor role + node access (verified uid 2).
- **Search API** matches documented bundle list; 31/31 help articles indexed.

### What needs verification

- Whether this DDEV database matches **staging/production** counts.
- **`support_procedure` access** if content type is used in future.
- **Browser check** of `/help`, `/help/organisers`, `/help/vendors`, Assistant on anonymous vs logged-in sessions.
- Whether **organiser hub duplicating attendee listing** is intentional product IA.

### Fix before writing more content

1. **nid 1521** status alignment (draft vs published).
2. **Organiser vs vendor audience** strategy for `/help/organisers`.
3. **`support_procedure` access model** if staff procedures will be added.

### Safe to write immediately

- New **public** attendee articles (checkout edge cases, waitlist, wallet) — flags and audience model are healthy.
- New **vendor** articles — set `field_audience: vendor`, `field_help_ai_allowed: 1`, `field_help_status: published`.
- **FAQ entries** — only 3 exist; room to grow without duplicating existing Guide titles.
- **Staff playbooks** — separate workflow; 10 playbooks already cover core escalation themes.

---

## Alignment with documentation audit

| Documented setup | Live match? |
|------------------|-------------|
| `/help` hub | Yes |
| `field_audience` canonical | Yes — no deprecated field data |
| Assistant filters | Yes — one draft-status outlier |
| `staff_playbook` excluded from Assistant index | Yes |
| 10 YAML seeds | **Partial** — 31 live articles; seed keys empty |
| Top-20 priority gaps | **Partially closed** — many attendee guides now exist |

---

## Follow-up Verification: Node 1521 and Browser Pass

**Date/time:** 2026-05-22 (local DDEV, `https://myeventlane.ddev.site`)

**Scope:** Editorial fix on node 1521 only; read-only verification otherwise. No code, routes, views, permissions, or Search API reindex performed.

### Commands run

| Command | Purpose |
|---------|---------|
| `ddev drush sqlq` (node 1521 fields + body) | Content inspection |
| `ddev drush php:eval` | Set `field_help_status` → `published`; post-fix verification; Help Assistant retrieval; staff leak check |
| `ddev drush cr` | Cache rebuild (×2) |
| `ddev drush search-api:status mel_content` | Index drift check |
| `ddev drush sqlq` (nid 1521 + field values) | After-state confirmation |
| `git status --short` | Change scope |
| Browser (Cursor IDE) | `/help`, `/help/search`, `/help/organisers`, `/help/vendors`, `/node/1521` |

`curl` was unavailable in the agent shell; browser + Drush used instead.

### Node 1521 inspection summary

| Field / check | Value |
|---------------|-------|
| Title | Setting Up Ticketing for Your Event |
| Published | Yes (`status = 1`) |
| `field_audience` | `vendor` |
| `field_help_status` (before) | `draft` |
| `field_help_ai_allowed` | `true` |
| Article type | Guide |
| Summary | Present — step-by-step ticketing setup for organisers |
| Body | ~922 chars; numbered steps (log in, Events, Ticketing tab, ticket types, publish) |
| Placeholder text | None found |
| Internal-only content | None found |
| Safe for vendor help | Yes — generic vendor console guidance, no staff procedures |

**Duplicate note:** Overlaps topic with “Setting up tickets” (nid 1508). Both can coexist; 1521 is a longer guide.

### Node 1521 decision

**Action taken:** Set `field_help_status` to **`published`** (node left published).

**Why not unpublish:** Body and summary are complete, accurate enough for vendor self-service, and aligned with other published vendor guides. The issue was editorial status out of sync with the published node, not unsafe or incomplete content.

**Why not rewrite:** No wording changes required for the status decision.

### Before / after state

| | Before | After |
|---|--------|-------|
| Node published | yes | yes |
| `field_help_status` | `draft` | `published` |
| `field_help_ai_allowed` | true | true |
| `field_audience` | vendor | vendor |
| Help Assistant (authenticated vendor, uid 2) | Blocked (status) | **Retrieves nid 1521** for query “setting up ticketing event” |
| Help Assistant (anonymous, Drush) | Blocked | Blocked (expected — vendor audience + node access) |
| `mel_content` index | 58/58 (100%) | **57/58 (98.3%)** — drift after save |

### Browser / access checks

| Check | Result |
|-------|--------|
| `/help` | Loads — Help Centre hub, Help Assistant region, FAQ buttons |
| `/help/search` | Loads — lists articles including “Setting Up Ticketing for Your Event” |
| `/help/attendees` | Not re-opened this pass; prior audit: public-only listing |
| `/help/organisers` | Loads — **15 public articles only**; **no** “Setting Up Ticketing for Your Event” |
| `/help/vendors` | Loads — **includes** “Setting Up Ticketing for Your Event” |
| `/node/1521` | Full article visible in browser session (**session had Account menu / cart — logged-in user**, not anonymous) |
| Anonymous access (Drush `node->access('view', anonymous)`) | **Denied** for nid 1521 |
| Help Assistant staff leakage (Drush) | No `staff_playbook` anonymous access; no staff bundles in retrieval sample |
| `/help/assistant` | Embedded on `/help` hub; dedicated route not isolated in this pass |

**Browse/search note:** `/help/search` (anonymous or default view) can show **titles** of vendor-audience articles without an audience filter applied by default. Node access should still block full view for anonymous users on canonical URLs (**Needs verification** in a logged-out browser session).

### Search API status

```
mel_content: 98.3% complete — 57 indexed / 58 total
```

- Drift appeared immediately after saving node 1521 (field change).
- **Reindex not run** (per task instructions).
- Help Assistant retrieval for authenticated vendor **worked without reindex** in this test (post-save).
- **Recommendation:** Run `ddev drush search-api:index mel_content` before production Assistant QA if answers seem stale; optional on local DDEV.

### Remaining risks

| Risk | Severity | Notes |
|------|----------|-------|
| Organiser hub IA | Medium (UX) | `/help/organisers` lists `public` only; vendor-topic articles only on `/help/vendors` |
| Search view audience filter | Low | Exposed filter includes legacy label “Organiser” — canonical values are `public` / `vendor` / `staff` |
| `mel_content` index drift | Low | 1 item pending index |
| `support_procedure` | Low (0 nodes) | Unchanged |
| Vendor article titles on `/help/search` | Low | Possible title leakage in listings — confirm logged-out behaviour |

### Recommendation: organiser hub IA (do not fix in this task)

**Current behaviour:**

- `/help/organisers` → View `mel_help_organiser_help` filters `field_audience: public` (same 15 articles as attendee hub).
- `/help/vendors` → View `mel_help_vendor_help` filters `field_audience: vendor` (16 articles, including organiser-topic guides).
- Sixteen vendor-audience articles (e.g. “How to create an event”, “Setting Up Ticketing for Your Event”) **do not appear** on `/help/organisers`.

**Recommendation (product/content, later):**

1. Decide whether “organiser” in the UI means **public** (attendee-adjacent) or **vendor console** (authenticated host).
2. If organiser means host: either add a second audience value on key articles (`public` + `vendor`), change `mel_help_organiser_help` to include `vendor`, or replace organiser hub copy with a clear CTA to `/help/vendors` for logged-in hosts.
3. Avoid duplicating all 16 articles on both hubs without a content strategy.

### Fix queue update (post–nid 1521)

1. ~~Align nid 1521 `field_help_status`~~ — **Done** (`published`).
2. Organiser hub IA — still open.
3. Optional `mel_content` reindex when convenient.
4. ~~Logged-out browser pass for `/help/search` + `/node/1521`~~ — **Done** (see Final Public Verification below).

---

## Final Public Verification: Help Search, Vendor Article Access, and Index Alignment

**Date/time:** 2026-05-22 (local DDEV, `https://myeventlane.ddev.site`)

**Scope:** Read-only verification + allowed `mel_content` reindex. No code, views, routes, permissions, or node edits.

### Commands run

| Command | Result |
|---------|---------|
| `ddev drush search-api:status mel_content` (before) | 96.6% — 57/59 |
| `ddev drush search-api:index mel_content` | Indexed **2** pending items |
| `ddev drush search-api:status mel_content` (after) | **100% — 59/59** |
| `ddev drush cr` | Success |
| `ddev drush php:eval` | Anonymous/vendor node access; Help Assistant retrieval; staff playbook isolation |
| `git status --short` | No help-content edits; unrelated module changes in tree |
| `ddev drush sqlq` (node counts) | Unchanged help/staff counts |
| `/usr/bin/curl -sk` (no cookies) | Anonymous HTTP checks on help routes + `/node/1521` |
| `curl -sk -X POST /help/assistant` (JSON, no cookies) | Anonymous Assistant query |
| Browser (logged-in admin/vendor session) | `/help/vendors`, `/node/1521` — confirms vendor access when authenticated |

### Reindex result

| Stage | Indexed | Total | % |
|-------|---------|-------|---|
| Before | 57 | 59 | 96.6% |
| After `search-api:index mel_content` | 59 | 59 | **100%** |

Search API warning during index: rendered_item field missing some view mode config (pre-existing; not changed in this task).

### Anonymous route checks (curl, no session cookie)

| URL | HTTP | Title / outcome | Article 1521 |
|-----|------|-----------------|--------------|
| `/help` | 200 | MyEventLane Help Centre | Not in hub HTML |
| `/help/search` | 200 | Search Help | **Title listed** (2 mentions); **summary teaser text** in listing (`field_help_summary` excerpt); **no body** (`Ticketing</strong> tab` not in HTML) |
| `/help/attendees` | 200 | Help for Attendees | Not listed |
| `/help/organisers` | 200 | Help for Organisers | Not listed (`How to create an event` = 0) |
| `/help/vendors` | 200 | Page title still “Help for Organisers” (copy bug) | **Title listed** in listing HTML |
| `/node/1521` | **403** | **Access denied** | Full body **not** shown |
| Click-through `/node/1521` from search | **403** | Access denied | Protected |

**Drush (anonymous):** `node->access('view')` = **false** for nid 1521.

**Help Assistant (anonymous JSON POST):** `{"question":"setting up ticketing for your event"}` → `status: fallback`, **no nid 1521**, empty `articles` array.

**Conclusion:** Anonymous users **cannot** read article 1521 body. Search **does** expose title + short summary in the default listing (teaser view) without an audience filter — **not** full article body. Clicking the link is **blocked**.

### Logged-in vendor checks

| Check | Result |
|-------|--------|
| Drush uid 2 (`vendor` role) `access('view')` on nid 1521 | **true** |
| Drush Help Assistant as uid 2 (“setting up ticketing”) | **Returns nid 1521** |
| Browser `/help/vendors` | Lists “Setting Up Ticketing for Your Event” |
| Browser `/node/1521` | Full article body visible (admin toolbar session) |
| Help Assistant on hub | Not re-tested end-to-end in browser; Drush + prior pass OK |

### Staff playbook isolation

| Check | Result |
|-------|--------|
| Anonymous can view any `staff_playbook` (Drush sample) | **0** leaks |
| Vendor uid 2 can view staff playbook sample | **0** leaks |
| `staff_playbook` in `mel_content` Search API query | **0** items |
| Staff routes | `/admin/myeventlane/governance` requires `administer escalations` (unchanged) |

### Organiser IA recommendation (not fixed)

**Current behaviour (confirmed):**

| Hub | View filter | What users see |
|-----|-------------|----------------|
| `/help/attendees` | `field_audience: public` | 15 public articles |
| `/help/organisers` | `field_audience: public` | **Same 15 public articles** (not host guides) |
| `/help/vendors` | `field_audience: vendor` | 16 vendor articles (host/organiser operations) |

**Labelling issue:** `/help/vendors` page `<title>` is still “Help for Organisers” while H1 is “Vendor help” — may confuse users.

**Recommended product decision:**

| Option | Assessment |
|--------|------------|
| **A** — Merge under one “Organisers” label | Simplest IA, but blurs attendee vs host operations unless content is re-tagged carefully. |
| **B** — Public organisers vs vendor console (preferred long-term) | Keep `/help/organisers` for **public** host onboarding copy; keep `/help/vendors` for **authenticated** console help; fix duplicate attendee/organiser lists and page titles. |
| **C** — Redirect organisers → vendors when logged in | Good **interim** fix: vendors land on the right articles without duplicating content. |

**Recommendation:** Adopt **Option B** for structure and labelling; implement **Option C** as a quick win (redirect `/help/organisers` → `/help/vendors` for users with vendor access) until organiser hub content is split intentionally.

### Remaining risks

| Risk | Severity | Status after this pass |
|------|----------|------------------------|
| Search lists vendor titles/summaries to anonymous | Low | Confirmed — mitigated by 403 on canonical node |
| Organiser/vendor hub duplication and title mismatch | Medium | Open — product IA |
| `support_procedure` with `access content` view | Low | 0 nodes; block not placed |
| Featured help block empty | Low | Unchanged |

### Final status

| Criterion | Status |
|-----------|--------|
| `mel_content` at 100% | **Pass** |
| Node 1521 `field_help_status` = published | **Pass** (unchanged this pass) |
| Anonymous denied `/node/1521` | **Pass** |
| Anonymous Assistant excludes 1521 | **Pass** |
| Vendor Assistant includes 1521 | **Pass** |
| Staff playbooks isolated | **Pass** |
| Help Centre public routes load | **Pass** |

**Overall:** Help Centre access controls and post-fix node 1521 behaviour are **aligned with documented MEL policy**. Remaining work is **content IA** (organiser vs vendor hubs) and optional hardening of `/help/search` default filters for anonymous users (audience filter default = public only).

## Search Metadata Leak Fix Verification

**Date:** 2026-05-22

### Issue summary

Anonymous users received HTTP 403 on `/node/1521` (vendor-only help article) and Help Assistant correctly excluded nid 1521, but `/help/search` listed the article **title and summary teaser** because `mel_help_search` used SQL Views with an **exposed** `field_audience` filter defaulting to empty (no restriction). Node access ran only on canonical view, not before listing metadata rendered.

### Implementation chosen

1. **`HelpArticleBrowsePolicy`** service (`myeventlane_help_centre.help_browse_policy`) — centralises browse/search audience and status rules (not used by Help Assistant).
2. **`hook_views_pre_view()`** — sets `field_audience_value` filter to effective audiences (intersects optional `?audience=` with account allowances).
3. **`hook_views_query_alter()`** — adds SQL `IN` constraints on `field_audience` and `field_help_status` so filters survive exposed-form processing (defence in depth).

**Audience rules (search/browse):**

| Account | Allowed `field_audience` |
|---------|--------------------------|
| Anonymous / authenticated without vendor console | `public` only |
| Vendor console / `view vendor help centre` | `public`, `vendor` |
| `administer escalations` | `public`, `vendor`, `staff` |

**Status rules (search/browse):** normal users → `published`, `approved` only; bypass / `administer help articles` → includes `draft`, `review`.

**`field_help_ai_allowed`:** not required for browse/search (unchanged; Assistant still enforces via `HelpRetriever`).

**Config YAML:** `views.view.mel_help_search.yml` unchanged (UUIDs/display IDs preserved).

### Files changed

| File | Change |
|------|--------|
| `web/modules/custom/myeventlane_help_centre/src/Service/HelpArticleBrowsePolicy.php` | **New** policy service |
| `web/modules/custom/myeventlane_help_centre/myeventlane_help_centre.services.yml` | Register `help_browse_policy` |
| `web/modules/custom/myeventlane_help_centre/myeventlane_help_centre.module` | `views_pre_view` + `views_query_alter` for `mel_help_search` |

### Commands run

```bash
php -l web/modules/custom/myeventlane_help_centre/myeventlane_help_centre.module
php -l web/modules/custom/myeventlane_help_centre/src/Service/HelpArticleBrowsePolicy.php
ddev drush cr
ddev drush search-api:index mel_content   # already 100%
ddev drush search-api:status mel_content
ddev drush config:status
```

### Before / after — anonymous search

| Check | Before fix | After fix |
|-------|------------|-----------|
| `/help/search?q=ticketing` lists nid 1521 title/summary | **Yes** (teaser) | **No** |
| `/help/search?q=Setting+Up+Ticketing` lists nid 1521 | **Yes** | **No** |
| `/node/1521` | 403 | 403 (unchanged) |
| Public articles in search (e.g. tickets) | Yes | Yes |
| Help Assistant | Excludes 1521 | Excludes 1521 (unchanged) |

**Anonymous curl (no cookies):** `/help/search?q=ticketing` → HTTP 200, **0** `/node/1521` links; `/node/1521` → HTTP 403.

### Before / after — vendor search

| Check | Before fix | After fix |
|-------|------------|-----------|
| `/node/1521` access (uid 2) | Allowed | Allowed |
| `/help/search?q=ticketing` includes nid 1521 | Yes | **Yes** (Drush view execute) |
| Help Assistant “setting up ticketing” | Includes 1521 | Includes 1521 (unchanged) |

### Search API status

| Index | Status |
|-------|--------|
| `mel_content` | **100%** (59/59 indexed) |

Browse/search listing uses **Views SQL**, not Search API; reindex not required for this fix.

### Staff isolation (re-checked)

| Check | Result |
|-------|--------|
| `staff_playbook` in `mel_content` bundles | **Not indexed** (`help_article`, `event`, `page` only) |
| Published `staff_playbook` nodes | 10 (admin paths only) |
| `support_procedure` nodes | **0** |
| Staff-audience `help_article` in anonymous/vendor search | Excluded by policy |

### Remaining risks

| Risk | Severity |
|------|----------|
| Other `mel_help_*` listing views still lack `field_help_status` filter | Low — hub views filter by audience; draft vendor articles could appear on `/help/vendors` |
| Organiser/vendor hub duplication and title mismatch | Medium — **deferred** (organiser IA not changed) |
| `countSearchResults()` analytics uses same view — counts now respect policy | Informational |

### Tests

No targeted PHPUnit test for `HelpArticleBrowsePolicy` or `mel_help_search` access filtering. Existing `HelpRetrieverKernelTest` covers Assistant only.

### Organiser IA

**Deferred** — no changes to `/help/organisers`, `/help/vendors`, or redirects.
