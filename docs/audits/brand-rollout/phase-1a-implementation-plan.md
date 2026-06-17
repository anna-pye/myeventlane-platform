# Phase 1A — Implementation Plan (Public Discovery Copy Slice)

**Brand territory:** The Hidden Gem + The Guide — Bright Edition  
**Plan date:** 2026-06-17  
**Branch (at plan time):** `feature/homepage-visual-refresh-phase-1`  
**Status:** **Plan only. No code changed by this document.**

> Scope is the **smallest safe slice**: public discovery **copy + rail order**, using surfaces and blocks that already exist. No SCSS/tokens, no mascot/art, no AI assistant, no email, no Commerce, no vendor/admin routes, no new components, no new architecture.
>
> Source audits: `homepage-audit.md`, `discovery-audit.md`, `component-inventory.md`, `brand-gap-analysis.md`, `final-rollout-strategy.md`. Brand authority: `docs/brand/homepage-system.md`, `docs/brand/copy-guidelines.md`, `docs/brand/guide-character-system.md`, `docs/brand/event-card-system.md`.

---

## 1. GOAL

Re-voice public discovery surfaces so visitors feel *“What wonderful thing can I discover this weekend?”* — using existing homepage rails, hero, empty states, and card badges only, with no new architecture.

---

## 2. PRODUCT SURFACE

**Public discovery**

---

## 3. AUDIT FIRST

Evidence gathered from the repository on 2026-06-17. Paths are relative to the repo root unless noted.

### 3.1 Routes

| Route | Evidence |
|---|---|
| `/home` (front page) | `config/sync/system.site.yml` → `page.front: /home` |
| `/events` | `views.view.upcoming_events` display `page_events` |
| `/events/category/%` | display `page_category` |
| `/events/today`, `/events/this-weekend`, `/events/free`, `/events/popular` | `views.view.upcoming_events` |
| `/events/hidden-gems` (Hidden Gems browse) | `page--view--upcoming-events--page-hidden-gems.html.twig`; display `page_hidden_gems` in `views-view--upcoming-events.html.twig` |
| `/search` | `myeventlane_search` module → `SearchController` |

### 3.2 Services and preprocess

| Service / hook | Path | Role |
|---|---|---|
| `HomepageSectionVisibility` | `web/modules/custom/myeventlane_front/src/Service/HomepageSectionVisibility.php` | Sets `mel_home_show_*` flags; avoids empty section shells |
| `HomepageMerchandising` | `web/modules/custom/myeventlane_front/src/Service/HomepageMerchandising.php` | Community Favourites dedup pool |
| `FeaturedEventsRenderBuilder` | `web/modules/custom/myeventlane_front/src/Service/FeaturedEventsRenderBuilder.php` | Featured rail content |
| `HomeHeroBlock` | `web/modules/custom/myeventlane_front/src/Plugin/Block/HomeHeroBlock.php` | Block plugin `myeventlane_home_hero`; `#theme => myeventlane_home_hero` |
| `EventMerchandisingPresenter` | `web/modules/custom/myeventlane_event/src/EventCard/EventMerchandisingPresenter.php` | Card badge + body signal labels |
| `myeventlane_theme_preprocess_page` | `web/themes/custom/myeventlane_theme/myeventlane_theme.theme` (≈ L1940+) | Injects `mel_home_show_*` on front page |

### 3.3 Templates (copy owners)

| Surface | Template | Copy type |
|---|---|---|
| Homepage layout + section headings | `web/themes/custom/myeventlane_theme/templates/page--front.html.twig` | Hardcoded `\|t` literals in `mel-section-shell` includes |
| Homepage hero | `web/modules/custom/myeventlane_front/templates/myeventlane-home-hero.html.twig` | H1, sub-line, search placeholders, CTAs |
| Hero fallback (inactive when block placed) | `web/themes/custom/myeventlane_theme/components/hero/hero.twig` | **Do not edit** — not active when `homepage_hero` region is filled |
| Section chrome | `web/themes/custom/myeventlane_theme/components/layout/mel-section-shell.html.twig` | Structure only; headings passed in |
| View empty (homepage embeds) | `web/themes/custom/myeventlane_theme/templates/includes/mel-view-empty-events.html.twig` | Default title/text/CTA |
| Browse empty + recovery links | `web/themes/custom/myeventlane_theme/templates/includes/mel-browse-empty-recovery.html.twig` | Title/text + recovery link row |
| Browse `/events` empty | `web/themes/custom/myeventlane_theme/templates/views/views-view--upcoming-events--page-events.html.twig` | Inline empty copy |
| Intent browse empties | `web/themes/custom/myeventlane_theme/templates/views/views-view--upcoming-events.html.twig` | `mel_empty_copy` map |
| Category empty | `web/themes/custom/myeventlane_theme/templates/views/views-view--upcoming-events--page-category.html.twig` | Inline `.mel-empty-state` copy |
| Search empty | `web/modules/custom/myeventlane_search/templates/myeventlane-search-results.html.twig` | Uses `mel-browse-empty-recovery` |
| Tonight homepage empty | `web/themes/custom/myeventlane_theme/templates/views/views-view-unformatted--upcoming-events--homepage-tonight.html.twig` | Overrides `mel-view-empty-events` |
| Event card badge consumption | `web/themes/custom/myeventlane_theme/templates/components/event-card/mel-event-card.html.twig` | Reads `_merch.image_badge_label` — **no badge text here** |

### 3.4 Block → region mapping (config)

| Section | Block config | Region | View / plugin |
|---|---|---|---|
| Hero | `config/sync/block.block.myeventlane_theme_homeheromyeventlane.yml` | `homepage_hero` | `myeventlane_home_hero` |
| Featured | `block.block.myeventlane_theme_views_block__front_featured_events_block_featured.yml` | `homepage_featured` | `front_featured_events` |
| Discover chips | block in `home_discover` region | `home_discover` | `mel_home_events` embed_discover |
| Tonight | `block.block.myeventlane_theme_homepage_tonight.yml` | `homepage_tonight` | `upcoming_events` homepage_tonight |
| Hidden Gems | `config/sync/block.block.myeventlane_theme_homepage_hidden_gems.yml` | `homepage_hidden_gems` | `upcoming_events` homepage_hidden_gems |
| Community Favourites | `config/sync/block.block.myeventlane_theme_homepage_community_favourites.yml` | `homepage_community_favourites` | popular-events block |
| Free & RSVP | `block.block.myeventlane_theme_homepage_free.yml` | `homepage_free` | `mel_home_events` under_20 |
| Latest | `block.block.myeventlane_theme_homepage_latest.yml` | `homepage_latest` | `upcoming_events` homepage_latest |
| **Recommended (Guide surface)** | `config/sync/block.block.myeventlane_theme_views_block__front_recommended_events_block_1.yml` | `home_recommended` | `front_recommended_events` block_1; **visibility: authenticated users only** |
| Blog | `block.block.myeventlane_theme_homepage_blog.yml` | `homepage_blog` | `mel_blog` homepage_preview |

### 3.5 Homepage rail order mechanism

**Confirmed:** order is **template-driven** — each rail is printed in sequence inside `page--front.html.twig`. Block weights in regions **cannot** reorder sections across regions.

**Committed order (HEAD, 2026-06-17):**

1. Featured (Community spotlight)  
2. Discover (chips)  
3. Tonight  
4. Hidden Gems  
5. Community Favourites  
6. Free & RSVP  
7. Latest  
8. Recommended  
9. Nearby  
10. Online  
11. Blog  
12. Host CTA → Newsletter  

**Uncommitted WIP** on the same branch reorders Discover to #1 and promotes Recommended above Tonight/Latest — see §8 Safety.

### 3.6 Already implemented (reduces Phase 1A scope)

These items were **not** in the 2026-06-14 audits but **are confirmed in HEAD today**:

| Item | Evidence |
|---|---|
| Hidden Gems homepage rail | `page--front.html.twig` L109–122; `mel_home_show_hidden_gems` in `myeventlane_theme.theme` L1964 |
| Community Favourites rail | `page--front.html.twig` L124–137; block config exists |
| Hero discovery copy (partial) | `myeventlane-home-hero.html.twig` L21–23: *“Find your next favourite experience.”* |
| **Hidden Gem** card badge | `EventMerchandisingPresenter.php` L121–124 → `'Hidden Gem'` with modifier `discovery-gold` |
| **Spotlight** card badge (promoted) | `EventMerchandisingPresenter.php` L118–120 → `'Spotlight'` (replaces legacy `'Featured'`) |
| Hidden Gems browse page | `page--view--upcoming-events--page-hidden-gems.html.twig` |
| Unit tests for badge priority | `web/modules/custom/myeventlane_event/tests/src/Unit/EventMerchandisingPresenterTest.php` |

### 3.7 Gaps vs brand (remaining Phase 1A work)

| Gap | Brand reference | Current evidence |
|---|---|---|
| Rail order not location/Hidden-Gem-led | `homepage-system.md` § Homepage hierarchy | Tonight is #3; Hidden Gems #4; editorial Featured is #1 |
| Blog + some subtitles still nightlife/utilitarian | `copy-guidelines.md`, `guide-character-system.md` | Blog: *“Guides for better nights out”* (`page--front.html.twig` L187) |
| Recommended rail not Curator Guide voice | Curator phrase: *“I think you’ll love this.”* | *“Worth exploring next”* / *“Events popular with the community right now.”* |
| Empty states neutral, not Guide-nudged | `guide-character-system.md` Helper/Explorer | *“Nothing here yet”* defaults; browse recovery links functional but not voiced |
| **Spotlight** ≠ brand **Editor’s Pick** label | `event-card-system.md` § Approved badges | Presenter uses `'Spotlight'`, not `'Editor's Pick'` — re-voice candidate only |
| **Community Favourite** card badge | `event-card-system.md` | **Not present** in presenter — **out of scope** (would be new badge logic) |

---

## 4. WHAT MUST NOT CHANGE

- `HomepageSectionVisibility` query logic and `mel_home_show_*` guard conditions
- View query filters, access checks, and Search API index configuration
- Block placement config (regions, visibility rules, plugin IDs) — **including authenticated-only visibility on `front_recommended_events`**
- Commerce, checkout, ticket, RSVP, capacity, and payment flows
- Vendor/admin routes, Event Studio, and dashboard surfaces
- `EventMerchandisingPresenter` badge **priority logic** (Sold out → Spotlight → Hidden Gem)
- SCSS, design tokens, mascot assets, AI assistant (`myeventlane_help_assistant`), email templates
- Entity fields (`field_hidden_gem` etc.) and badge eligibility criteria
- Fallback hero SDC (`components/hero/hero.twig`) — inactive but must not be edited in this slice
- Uncommitted SCSS/markup WIP in the working tree — **Phase 1A is copy + rail-order only; do not bundle visual refresh**

---

## 5. WHAT CAN CHANGE

- Translatable string literals (`|t`) in the templates listed in §3.3
- Order of existing `{% if mel_home_show_* %}` section blocks in `page--front.html.twig` (markup reorder only)
- Optional re-label of **existing** presenter strings: `'Spotlight'` → `'Editor's Pick'` (PHP + unit test assertions)
- Per-view empty-state overrides that already use `mel-view-empty-events` or `mel-browse-empty-recovery`
- Recovery link **labels** in `mel-browse-empty-recovery.html.twig` (not route targets)

---

## 6. RULES

- Drupal 11 presentation/logic separation: Twig for copy; services for logic.
- Extend existing systems; no duplicate components, routes, or controllers.
- Preserve all access boundaries and authenticated-only recommendation visibility.
- Australian English; avoid exclusivity/FOMO vocabulary per `copy-guidelines.md`.
- Maximum **two Guide moments** on homepage (`homepage-system.md` § Guide placement) — copy-only; no illustration in Phase 1A.
- **Stop if unconfirmed:** do not add Community Favourite card badge, new Hidden Gem data layer, or Vibe Mixer placement (config/architecture — deferred).
- Proposed strings below are **illustrative drafts for sign-off**, not final copy.

---

## 7. STEPS

Work in four groups. After each group, stop, review, and run the validation command before continuing.

### Group A — Homepage hero copy

**Change A1 — Hero headline and supporting line**

| Field | Detail |
|---|---|
| **Repository evidence** | `myeventlane-home-hero.html.twig` L20–24: eyebrow `'MyEventLane'`, H1 `'Find your next favourite experience.'`, text `'Discover more of your city. Unexpected experiences. Real community.'`; L44 search placeholder; L69 `'Explore events'` CTA |
| **File path** | `web/modules/custom/myeventlane_front/templates/myeventlane-home-hero.html.twig` |
| **Route affected** | `/home` |
| **Current behaviour** | Hero already uses discovery-leaning copy (post–community-favourites work). Sub-line emphasises city/community, not weekend wonder. |
| **Proposed behaviour** | Re-voice H1 + sub-line to Bright Edition wonder, e.g. H1 → `'Discover something wonderful this weekend.'`; text → `'Hidden gems, local favourites, and experiences you never knew existed — all in one place.'` Eyebrow, search placeholders, and primary CTA optional. **Copy-only; no markup/SCSS change in Phase 1A.** |
| **Risk level** | **Low** |
| **Config export required** | **No** |
| **Validation command** | `ddev drush cr` then load `/home` — confirm hero text, search form, rotator (if featured events present), and CTAs still work |
| **Rollback step** | `git checkout -- web/modules/custom/myeventlane_front/templates/myeventlane-home-hero.html.twig` |

---

### Group B — Homepage rail order + section headings + Guide recommendation block

Execute as **one coordinated edit** to `page--front.html.twig`.

**Change B1 — Discovery rail order**

| Field | Detail |
|---|---|
| **Repository evidence** | Template sequence in `page--front.html.twig`; brand target order in `docs/brand/homepage-system.md` § Homepage hierarchy; audits `homepage-audit.md` §2, `discovery-audit.md` §3 |
| **File path** | `web/themes/custom/myeventlane_theme/templates/page--front.html.twig` |
| **Route affected** | `/home` |
| **Current behaviour** | Featured → Discover → Tonight → Hidden Gems → Community Favourites → Free → Latest → Recommended → Nearby → Online → Blog |
| **Proposed behaviour** | Reorder **existing** sections only (preserve each block’s `{% if %}` guard and `content:` region verbatim): **Tonight → Hidden Gems → Discover (chips) → Featured (Community spotlight) → Community Favourites → Latest → Recommended → Free → Nearby → Online → Blog → Host CTA → Newsletter**. Aligns with brand mobile priority (time-led + Hidden Gem before generic browse) without adding rails. |
| **Risk level** | **Low–moderate** (single-template markup reorder; dedup/visibility logic unchanged) |
| **Config export required** | **No** |
| **Validation command** | `ddev drush cr` → load `/home`; confirm each rail renders when data exists and hides when empty; `ddev drush watchdog:show --severity=Error` |
| **Rollback step** | `git checkout -- web/themes/custom/myeventlane_theme/templates/page--front.html.twig` |

**Change B2 — Section headings / subtitles (Guide voice)**

| Field | Detail |
|---|---|
| **Repository evidence** | Literals in `page--front.html.twig`: Featured L54–55; Discover L39–40; Tonight L100–101; Hidden Gems L115–116 (already Guide-voiced); Community Favourites L130–131; Free L145–146; Latest L85–86; Recommended L70–71; Blog L187–188 |
| **File path** | `web/themes/custom/myeventlane_theme/templates/page--front.html.twig` |
| **Route affected** | `/home` |
| **Current behaviour** | Mixed voice: Hidden Gems already uses Guide (*“The Guide has found…”*); Blog still *“Guides for better nights out”*; Recommended uses popularity framing, not Curator Guide |
| **Proposed behaviour** | Copy-only `\|t` updates, e.g.: Featured → title `'Hidden gems the Guide loves'` / subtitle `'Come look at this — local experiences worth showing up for.'`; Recommended → `'Picked for you'` / `'The Guide thinks you'll love these.'`; Blog → `'Guides to discovering more'` / `'Ideas for finding, hosting, and growing local events.'`; Tonight subtitle → `'On tonight near you.'` Keep all `link_url` / `path()` calls unchanged. |
| **Risk level** | **Low** |
| **Config export required** | **No** |
| **Validation command** | `ddev drush cr` → `/home`; confirm headings render and “See all” links resolve |
| **Rollback step** | Same file rollback as B1 |

**Change B3 — In-flow Guide recommendation block (existing block only)**

| Field | Detail |
|---|---|
| **Repository evidence** | Block `config/sync/block.block.myeventlane_theme_views_block__front_recommended_events_block_1.yml` — `status: true`, `region: home_recommended`, `plugin: views_block:front_recommended_events-block_1`, visibility `authenticated` role; rendered via `page.home_recommended`; gated by `mel_home_show_recommended` (`myeventlane_theme.theme` L1956) |
| **File path** | `page--front.html.twig` (position via B1; copy via B2) — **no config change** |
| **Route affected** | `/home` (authenticated users with recommendation results) |
| **Current behaviour** | Recommendation rail exists mid-page; heading *“Worth exploring next”*; anonymous users never see the block (by design) |
| **Proposed behaviour** | **Reuse existing block** as the Curator Guide recommendation surface: promote position (B1, directly after Latest or Featured per sign-off) and apply Curator copy (B2). No new block, View, or component. |
| **Risk level** | **Low** |
| **Config export required** | **No** |
| **Validation command** | `ddev drush cr` → log in as authenticated user with activity; confirm rail renders in new position with updated heading; confirm anonymous `/home` unchanged except rail order of public sections |
| **Rollback step** | Covered by B1/B2 rollback |

**Assumption check:** An existing reusable recommendation block **is confirmed**. Rule satisfied — no new architecture.

---

### Group C — Empty-state copy (discovery surfaces)

**Change C1 — Canonical view empty defaults**

| Field | Detail |
|---|---|
| **Repository evidence** | `mel-view-empty-events.html.twig` L10–15: title `'Nothing here yet'`, text `'Try a category, browse this weekend, or explore community spotlight on the homepage.'`, CTAs `'Explore events'` / `'Back to discovery'` |
| **File path** | `web/themes/custom/myeventlane_theme/templates/includes/mel-view-empty-events.html.twig` |
| **Route affected** | Homepage embeds (`mel_home_events`, tonight block fallback, etc.) |
| **Current behaviour** | Neutral empty messaging |
| **Proposed behaviour** | e.g. title `'No gems here just yet'`; text `'The Guide is always finding new experiences — check back soon or explore what's on across MyEventLane.'`; keep CTA targets unchanged |
| **Risk level** | **Low** |
| **Config export required** | **No** |
| **Validation command** | `ddev drush cr` → trigger a homepage rail empty state (or use a view with no results) |
| **Rollback step** | `git checkout -- web/themes/custom/myeventlane_theme/templates/includes/mel-view-empty-events.html.twig` |

**Change C2 — Browse recovery empty states**

| Field | Detail |
|---|---|
| **Repository evidence** | `mel-browse-empty-recovery.html.twig` (shared recovery links); `views-view--upcoming-events--page-events.html.twig` L43–46; `views-view--upcoming-events.html.twig` L42–62 `mel_empty_copy` map; `views-view--upcoming-events--page-category.html.twig` L75–76; `myeventlane-search-results.html.twig` L18–21; `views-view-unformatted--upcoming-events--homepage-tonight.html.twig` L15–21 |
| **File path** | Templates above (primary: `mel-browse-empty-recovery.html.twig` + `views-view--upcoming-events.html.twig`) |
| **Route affected** | `/events`, `/events/today`, `/events/this-weekend`, `/events/free`, `/events/popular`, `/events/hidden-gems`, `/events/category/%`, `/search` |
| **Current behaviour** | Functional recovery paths; neutral titles (*“No events found”*, *“Nothing on today yet”*) |
| **Proposed behaviour** | Re-voice titles/text to optimistic Explorer/Helper Guide tone per `copy-guidelines.md` § Empty search; **do not** change recovery link routes. Category page inline empty (L75–76) updated in same pass. **Do not** consolidate `.mel-empty` vs `.mel-empty-state` vocabulary — structural, out of scope. |
| **Risk level** | **Low** |
| **Config export required** | **No** |
| **Validation command** | `ddev drush cr` → `/events?keys=zzzznoresult`, `/events/hidden-gems` (empty if no gems), `/search?q=zzzznoresult` |
| **Rollback step** | `git checkout -- web/themes/custom/myeventlane_theme/templates/includes/mel-browse-empty-recovery.html.twig web/themes/custom/myeventlane_theme/templates/views/views-view--upcoming-events.html.twig web/themes/custom/myeventlane_theme/templates/views/views-view--upcoming-events--page-events.html.twig web/themes/custom/myeventlane_theme/templates/views/views-view--upcoming-events--page-category.html.twig web/modules/custom/myeventlane_search/templates/myeventlane-search-results.html.twig` |

---

### Group D — Event card badge language (optional; sign-off required)

**Change D1 — Re-voice existing Spotlight label only**

| Field | Detail |
|---|---|
| **Repository evidence** | `EventMerchandisingPresenter.php` L118–120 `'Spotlight'` when `is_promoted`; `'Hidden Gem'` L121–124 when `is_hidden_gem` (**already brand-aligned**); tests assert `'Spotlight'` and `'Hidden Gem'` in `EventMerchandisingPresenterTest.php` |
| **File path** | `web/modules/custom/myeventlane_event/src/EventCard/EventMerchandisingPresenter.php` + `tests/src/Unit/EventMerchandisingPresenterTest.php` |
| **Route affected** | All surfaces rendering event cards (`/home`, `/events`, `/search`, etc.) |
| **Current behaviour** | Promoted events show **Spotlight** badge; hidden-gem field shows **Hidden Gem** badge |
| **Proposed behaviour** | Re-label `'Spotlight'` → `'Editor's Pick'` only (maps to brand approved badge in `event-card-system.md`). **Do not** add Community Favourite, Trending Tonight, Nearby, or Just Added badges — not supported in presenter today. |
| **Risk level** | **Moderate** (logic-bearing PHP + unit tests). **Recommend deferring to Phase 1B** if Phase 1A must stay Twig-only. |
| **Config export required** | **No** |
| **Validation command** | `ddev exec ./vendor/bin/phpunit web/modules/custom/myeventlane_event/tests/src/Unit/EventMerchandisingPresenterTest.php` then `ddev drush cr` + visual check of a promoted card |
| **Rollback step** | `git checkout -- web/modules/custom/myeventlane_event/src/EventCard/EventMerchandisingPresenter.php web/modules/custom/myeventlane_event/tests/src/Unit/EventMerchandisingPresenterTest.php` |

**STOP — not in scope:** Community Favourite / Trending Tonight / Nearby / Just Added card badges — *repository evidence not found* for presenter support. Adding them would be new badge logic, not re-voice.

---

### Recommended execution order

| Order | Group | Files | Risk |
|---|---|---|---|
| 1 | A | `myeventlane-home-hero.html.twig` | Low |
| 2 | C | empty-state templates | Low |
| 3 | B | `page--front.html.twig` (single coordinated edit) | Low–moderate |
| 4 | D (optional) | `EventMerchandisingPresenter.php` + test | Moderate |

**Global validation (after all applied groups):**

```bash
ddev drush cr
ddev drush watchdog:show --severity=Error
# Manual: /home (anon + authenticated), /events, /events/hidden-gems, /search?q=zzzz
```

No `ddev drush cex` — Phase 1A changes no config entities.

---

## 8. SAFETY

### Working tree status (2026-06-17)

**The working tree is NOT clean.** Uncommitted changes exist on `feature/homepage-visual-refresh-phase-1`:

| File | Notes |
|---|---|
| `web/modules/custom/myeventlane_front/templates/myeventlane-home-hero.html.twig` | Markup wrapper changes (search shell) — **includes non–Phase 1A structure** |
| `web/themes/custom/myeventlane_theme/templates/page--front.html.twig` | Partial rail reorder WIP |
| `web/themes/custom/myeventlane_theme/src/scss/components/_event-card.scss` | **Out of Phase 1A scope** |
| `web/themes/custom/myeventlane_theme/src/scss/components/_home-hero.scss` | **Out of Phase 1A scope** |
| `web/themes/custom/myeventlane_theme/src/scss/pages/_front-page.scss` | **Out of Phase 1A scope** |

**Last commit:** `571acb94d` — *Merge pull request #598 from anna-pye/feature/community-favourites-visibility-verification*

**Before starting Phase 1A implementation:**

1. Commit or stash unrelated visual-refresh WIP explicitly (human decision).
2. Prefer a **copy-only branch** cut from `571acb94d` (or current HEAD after WIP is resolved) to avoid mixing SCSS with copy.

### Rollback

Per-file rollback:

```bash
git checkout -- \
  web/modules/custom/myeventlane_front/templates/myeventlane-home-hero.html.twig \
  web/themes/custom/myeventlane_theme/templates/page--front.html.twig \
  web/themes/custom/myeventlane_theme/templates/includes/mel-view-empty-events.html.twig \
  web/themes/custom/myeventlane_theme/templates/includes/mel-browse-empty-recovery.html.twig \
  web/themes/custom/myeventlane_theme/templates/views/views-view--upcoming-events.html.twig \
  web/themes/custom/myeventlane_event/src/EventCard/EventMerchandisingPresenter.php
```

Whole-slice rollback (after commit):

```bash
git revert <phase-1a-commit-sha>
```

Return to last known-good commit:

```bash
git checkout 571acb94d -- <paths above>
```

---

## 9. DONE MEANS

Phase 1A is complete when all of the following are true:

| Criterion | Verify |
|---|---|
| Hero copy matches signed-off Bright Edition strings | Load `/home` — H1 + sub-line |
| Homepage rails follow agreed order (§7 B1) | Visual inspection `/home`; no missing/duplicated sections |
| Section headings use Guide/Curator voice where specified | `/home` heading scan; blog + recommended updated |
| Empty states on browse/search use Guide-nudged copy | `/events?keys=zzzznoresult`, `/search?q=zzzznoresult` |
| Existing recommendation block reused in promoted position (authenticated) | Logged-in `/home` when recommendations exist |
| **Hidden Gem** badge unchanged and still renders | Card on `/events/hidden-gems` or homepage Hidden Gems rail |
| No new components, blocks, routes, or config exports | `git diff config/sync` empty |
| No SCSS/token/mascot/AI/email/Commerce changes in the slice | `git diff -- '*.scss' '*.yml'` empty for Phase 1A commit |
| No PHP errors | `ddev drush watchdog:show --severity=Error` clean after cache rebuild |
| Optional Group D only if explicitly approved | PHPUnit green + promoted card shows `'Editor's Pick'` |

**Residual risks:**

- `/events/nearby` and `/events/online` “See all” links on homepage point to `page_events` / `page_free` fallbacks (`page--front.html.twig` L161, L175) — routing mismatch flagged in `discovery-audit.md`; **not fixed in Phase 1A**.
- Recommendation rail invisible to anonymous users by block visibility — intentional; Curator copy applies only when the block renders.
- Uncommitted visual-refresh SCSS on the branch must not ship mixed into a copy-only Phase 1A PR.

---

## Explicitly OUT of Phase 1A

| Item | Reason |
|---|---|
| SCSS / token / palette changes | Excluded by scope |
| Mascot / hero art / Guide illustrations | Excluded by scope |
| AI assistant → “Ask the Guide” | Excluded by scope |
| Email / digest copy | Excluded by scope |
| Vibe Mixer block placement | Config + architecture — deferred (`homepage-audit.md` §4) |
| New Hidden Gems View/display or `field_hidden_gem` logic | Already exists in HEAD |
| Community Favourite / Trending Tonight / Nearby / Just Added **card badges** | Not supported in presenter — would be new logic |
| Empty-state vocabulary consolidation (`.mel-empty` vs `.mel-empty-state`) | Structural — deferred (`component-inventory.md` §E) |
| `/events/nearby`, `/events/online` route builds | Validate separately — not copy |
| Hero markup/SCSS visual refresh | Separate work on current branch WIP |

---

## Sign-off checklist (before implementation)

- [ ] Final hero, heading, and empty-state copy approved (drafts in §7 are illustrative).
- [ ] Rail order preference confirmed (proposed B1 order vs brand `homepage-system.md` table).
- [ ] Decision on Group D: re-voice `'Spotlight'` → `'Editor's Pick'` now, or defer to Phase 1B.
- [ ] Working tree cleaned or branched — no SCSS bundled into copy PR.
- [ ] No parallel edits to `page--front.html.twig` during Group B.
