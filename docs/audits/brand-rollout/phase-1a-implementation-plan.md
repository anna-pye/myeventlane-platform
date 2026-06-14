# Phase 1A — Implementation Plan (Public Discovery Copy Slice)

**Brand territory:** The Hidden Gem + The Guide — Bright Edition
**Plan date:** 2026-06-14
**Branch:** `feature/event-studio-consolidation`
**Status:** **Plan only. No code changed by this document.**

> Scope is the **smallest safe slice**: public discovery **copy + rail order**, using surfaces and blocks that already exist. No SCSS/tokens, no mascot/art, no AI assistant, no email, no Commerce, no vendor/admin routes, no new components, no new architecture.
>
> Source audits: `homepage-audit.md`, `discovery-audit.md`, `event-page-audit.md`, `onboarding-audit.md`, `component-inventory.md`.

---

## 0. Pre-flight facts (verified this session)

| Fact | Evidence |
|---|---|
| Front page route | `config/sync/system.site.yml` → `page.front: /home` |
| Active hero = block plugin `myeventlane_home_hero` → template | `config/sync/block.block.myeventlane_theme_homeheromyeventlane.yml`; `HomeHeroBlock.php:99` `#theme => myeventlane_home_hero`; renders `myeventlane_front/templates/myeventlane-home-hero.html.twig` |
| Homepage rail **order is template-driven** (each rail is its own region, printed top→bottom) — **not** block weight | `page--front.html.twig` lines 41,56,71,86,101,116,130,144,158 |
| Section headings/subtitles are hardcoded `\|t` literals in the front template | `page--front.html.twig` (per heading) |
| Recommendation block **already placed and enabled** | `block.block.myeventlane_theme_views_block__front_recommended_events_block_1.yml` → `status: true`, `region: home_recommended` |
| Event-card badge text mostly from PHP presenter | `myeventlane_event/src/EventCard/EventMerchandisingPresenter.php` |
| Canonical discovery empty-state copy | `templates/includes/mel-view-empty-events.html.twig` |

**Config-export note (applies to all items):** every copy string in scope lives in **Twig templates or PHP**, not in config entities. **No `drush cex` is required for any Phase 1A item.** The one config entity involved (the recommended block placement) is **not modified**.

> Proposed strings below are **illustrative, on-brand drafts for sign-off**, not final copy. The emotional target is *"What wonderful thing can I discover this weekend?"* — optimistic, welcoming, discovery-first.

---

## Change 1 — Homepage hero copy

- **Repository evidence:** `myeventlane_front/templates/myeventlane-home-hero.html.twig`
  - L20 eyebrow `{{ 'MyEventLane'|t }}`
  - L21 H1 `{{ 'Your lane to great events.'|t }}`
  - L23 text `{{ 'Find workshops, gigs, markets, festivals, and community moments worth leaving the house for.'|t }}`
  - L41 search placeholder `'Search events, organisers, or places'`; L60 `'Explore events'`
- **File path:** `web/modules/custom/myeventlane_front/templates/myeventlane-home-hero.html.twig`
- **Route affected:** `/home` (front page)
- **Current behaviour:** Hero shows "Your lane to great events." + utilitarian sub-line.
- **Proposed behaviour:** Re-voice H1 + sub-line to discovery-wonder, e.g. H1 → `'Discover something wonderful this weekend.'`; text → `'Hidden gems, local favourites, and experiences you never knew existed — all in one place.'` (eyebrow/search/CTA labels optional). Copy-only; no markup/logic change.
- **Risk level:** **Low** (single template, copy-only).
- **Config export required:** **No** (template).
- **Validation command:** `ddev drush cr` → load `/home`, confirm hero renders and rotator/search still function (`home-hero-rotator` library unaffected).
- **Rollback step:** `git checkout -- web/modules/custom/myeventlane_front/templates/myeventlane-home-hero.html.twig`
- **Assumption check:** Confirmed active template (block places `myeventlane_home_hero`; `page.homepage_hero` printed first in `page--front.html.twig:10`). The fallback `templates/components/hero/hero.twig` ("Your lane to great events.") is **not active** and must **not** be edited.

---

## Change 2 — Homepage discovery rail order

- **Repository evidence:** `page--front.html.twig` prints regions in fixed order: featured (L41) → discover (L56) → tonight (L71) → free (L86) → latest (L101) → **recommended (L116)** → nearby (L130) → online (L144) → blog (L158). Order is the template sequence; each rail is a separate region, so block weights cannot reorder them.
- **File path:** `web/themes/custom/myeventlane_theme/templates/page--front.html.twig`
- **Route affected:** `/home`
- **Current behaviour:** Generic browse rails (discover/tonight/free/latest) appear above the editorial "Featured" and "Recommended" rails sit mid-page (L116).
- **Proposed behaviour:** Promote curated/editorial rails by **moving existing section blocks** so order becomes: hero → **Featured (Community spotlight)** → **Recommended for you** → Happening tonight → Discover → Free/RSVP → Latest → Nearby → Online → Blog → Host CTA → Newsletter. Pure block-move of existing `{% include mel-section-shell %}` sections; **all `mel_home_show_*` visibility conditions and region variables preserved verbatim**.
- **Risk level:** **Low–moderate** (markup reorder in one template; must keep each section's `{% if %}` guard + `content:` region intact).
- **Config export required:** **No** (template; not block weights).
- **Validation command:** `ddev drush cr` → load `/home`; confirm each rail still renders only when it has results (toggle by data), no duplicated/dropped sections, no PHP notices in `ddev drush watchdog:show`.
- **Rollback step:** `git checkout -- web/themes/custom/myeventlane_theme/templates/page--front.html.twig`
- **Assumption check:** Confirmed order is template-driven (no weight mechanism). Changes 2, 3, 6 all edit this one file — execute as **one coordinated edit**.

---

## Change 3 — Homepage section headings / subtitles

- **Repository evidence:** `page--front.html.twig` literals — `'Community spotlight'` / `'Local events and creators worth showing up for'` (featured); `'Discover events'` / `'Filter by what you feel like tonight'`; `'Happening tonight'`; `'Easy ways to get involved'`; `'Freshly added'`; `'Recommended for you'` / `'Events you may enjoy'`; `'Nearby events'`; `'Online events'`; `'Guides for better nights out'`.
- **File path:** `web/themes/custom/myeventlane_theme/templates/page--front.html.twig`
- **Route affected:** `/home`
- **Current behaviour:** Functional/nightlife-leaning headings (e.g. "Guides for better nights out", "Filter by what you feel like tonight").
- **Proposed behaviour:** Re-voice the `title:`/`subtitle:` strings to the Guide, e.g. Featured → `'Hidden gems the Guide loves'`; Recommended → `'Picked for you'` / `'The Guide thinks you'll love these'`; Blog → `'Guides to discovering more'`. Copy-only; `mel-section-shell` structure and `link_url`/`path()` calls unchanged.
- **Risk level:** **Low** (copy-only).
- **Config export required:** **No** (template).
- **Validation command:** `ddev drush cr` → load `/home`, confirm headings render and "See all" links still resolve.
- **Rollback step:** `git checkout -- .../page--front.html.twig` (same file as Changes 2 & 6).
- **Assumption check:** Confirmed all headings are template literals (not block titles, not config).

---

## Change 4 — Event card badge language (where already supported)

- **Repository evidence:**
  - Presenter produces badge text: `EventMerchandisingPresenter.php:110` `$this->t('Featured')` (gated by `($isHero || $isSpotlight) && $isPromoted`); L100/L156 `'Selling fast'`; sold-out `'Sold out'`.
  - Card consumes via `_merch.image_badge_label` (`mel-event-card.html.twig:94–96`); literal `'Selling fast'` also at template L335/L412.
  - **Unit test exists:** `myeventlane_event/tests/src/Unit/EventMerchandisingPresenterTest.php`.
- **File path:** `web/modules/custom/myeventlane_event/src/EventCard/EventMerchandisingPresenter.php` (+ test) — *and/or* literal strings in `mel-event-card.html.twig`.
- **Route affected:** All discovery surfaces rendering event cards (`/home`, `/events`, `/events/category/%`, `/search`).
- **Current behaviour:** Promoted hero/spotlight cards show a **"Featured"** badge; low-stock cards show **"Selling fast"**.
- **Proposed behaviour (minimal):** Re-voice the **existing** `'Featured'` label only (e.g. → `'Hidden gem'` or `'Guide pick'`) at `EventMerchandisingPresenter.php:110`, and update the assertion in `EventMerchandisingPresenterTest`. No new badge type, no new modifier, no template logic change.
- **Risk level:** **Moderate** (logic-bearing, **unit-tested** PHP; not pure copy). **Recommend sign-off before doing — or defer to Phase 1B** if Phase 1A must stay Twig-only.
- **Config export required:** **No** (PHP).
- **Validation command:** `ddev exec ./vendor/bin/phpunit web/modules/custom/myeventlane_event/tests/src/Unit/EventMerchandisingPresenterTest.php` then `ddev drush cr` and visual check of a promoted card.
- **Rollback step:** `git checkout -- web/modules/custom/myeventlane_event/src/EventCard/EventMerchandisingPresenter.php web/modules/custom/myeventlane_event/tests/src/Unit/EventMerchandisingPresenterTest.php`
- **STOP / not confirmed:** A dedicated **"Hidden Gem" badge type/modifier is NOT present** in the repo (grep for `hidden gem`/`gem` badge returned nothing). Per the rules, **a new badge is out of scope** — *Repository evidence not found*. Only the existing `'Featured'`/`'Selling fast'` labels are re-voiceable.

---

## Change 5 — Empty-state copy (discovery surfaces)

- **Repository evidence:**
  - Canonical include defaults: `includes/mel-view-empty-events.html.twig:10–13` title `'Nothing here yet'`, text `'Check back soon or explore more events across MyEventLane.'`, CTA `'Explore events'`.
  - View-template literals: `views-view--upcoming-events--page-events.html.twig:42` "No events found"; `views-view--upcoming-events--page-category.html.twig:80` "No events in this category"; `views-view--mel-home-events--tonight.html.twig:15` + `views-view-unformatted--upcoming-events--homepage-tonight.html.twig:16` "Nothing listed for tonight yet"; `views-view--mel-help-search.html.twig:43` "No results found".
- **File path:** `web/themes/custom/myeventlane_theme/templates/includes/mel-view-empty-events.html.twig` (primary) + the per-view templates above.
- **Route affected:** `/events`, `/events/category/%`, `/events/today` (tonight rail on `/home`), `/search` (help search on `/help/search`).
- **Current behaviour:** Neutral "No events found" / "Nothing here yet" messaging.
- **Proposed behaviour:** Re-voice to optimistic Guide nudges, e.g. title `'No gems here just yet'`; text `'The Guide is always finding new experiences — check back soon or explore what's on across MyEventLane.'`; keep CTA `'Explore events'` → `/events`. Editing the **canonical include defaults** cascades to all callers that don't override. Copy-only.
- **Risk level:** **Low** (copy-only; reuses existing `empty-state.html.twig` component — no new component).
- **Config export required:** **No** (templates).
- **Validation command:** `ddev drush cr` → visit `/events` with a no-result filter (e.g. `/events?keys=zzzznoresult`) and a `/search` no-result query; confirm empty state renders with new copy + working CTA.
- **Rollback step:** `git checkout -- web/themes/custom/myeventlane_theme/templates/includes/mel-view-empty-events.html.twig` (+ any per-view templates touched).
- **Assumption check:** Confirmed copy lives in Twig; `empty-state.html.twig` is the existing shared component (no duplication). Note the `.mel-empty` vs `.mel-empty-state` vocabulary split (`component-inventory.md §E`) — **do not consolidate vocab in Phase 1A** (that is structural, out of scope); only change visible text.

---

## Change 6 — One in-flow Guide recommendation block (existing block only)

- **Repository evidence:** `front_recommended_events` block is **already placed and enabled** — `config/sync/block.block.myeventlane_theme_views_block__front_recommended_events_block_1.yml` (`status: true`, `region: home_recommended`, `plugin: views_block:front_recommended_events-block_1`). Rendered in-flow at `page--front.html.twig:116`, gated by `mel_home_show_recommended` = `viewDisplayHasResults('front_recommended_events','block_1')` (`myeventlane_theme.theme:1952`). Heading/subtitle literals "Recommended for you" / "Events you may enjoy" in the same template.
- **File path:** `web/themes/custom/myeventlane_theme/templates/page--front.html.twig` (heading copy + position handled by Changes 2 & 3).
- **Route affected:** `/home`
- **Current behaviour:** The recommendation rail exists, sits mid-page (L116), shows only when the View has results, headed "Recommended for you".
- **Proposed behaviour:** **Reuse the existing block** as the in-flow Guide recommendation surface — promote its position (Change 2) and re-voice its heading to the Guide (Change 3, e.g. "Picked for you by the Guide"). **No new block, no new component, no new View, no config change.**
- **Risk level:** **Low** (no new placement; copy + order only).
- **Config export required:** **No** — block placement config is **reused unchanged**.
- **Validation command:** `ddev drush cr` → load `/home` as an authenticated user with activity that yields results; confirm the recommendation rail renders in the promoted position with new heading. (If empty, confirm it hides gracefully — existing visibility logic.)
- **Rollback step:** covered by Change 2/3 rollback (`git checkout -- .../page--front.html.twig`).
- **Assumption check:** Confirmed an existing reusable recommendation block already supports this (`front_recommended_events`). The rule "only if an existing reusable block already supports it" is **satisfied** — no new architecture.

---

## Execution grouping & sequencing

| Group | Files | Changes | Risk |
|---|---|---|---|
| A | `myeventlane-home-hero.html.twig` | 1 | Low |
| B | `page--front.html.twig` (one coordinated edit) | 2, 3, 6 | Low–moderate |
| C | `mel-view-empty-events.html.twig` (+ per-view templates) | 5 | Low |
| D | `EventMerchandisingPresenter.php` (+ unit test) | 4 | **Moderate — sign-off / consider deferring** |

**Recommended order:** A → C → B → (optional, after sign-off) D. Groups A/B/C are pure Twig copy/markup; Group D is the only logic-bearing/tested change and is the natural deferral candidate if Phase 1A must remain template-only.

## Global validation (after all groups)
```bash
ddev drush cr
ddev drush watchdog:show --severity=Error    # confirm no new template/render errors
npm run build                                # rebuild theme assets if any theme file changed
# Manual: /home, /events, /events/category/<term>, /search, /help/search
```
> No `ddev drush cex` needed — Phase 1A changes no config entities.

## Global rollback
```bash
git checkout -- \
  web/modules/custom/myeventlane_front/templates/myeventlane-home-hero.html.twig \
  web/themes/custom/myeventlane_theme/templates/page--front.html.twig \
  web/themes/custom/myeventlane_theme/templates/includes/mel-view-empty-events.html.twig
# Group D (only if applied):
git checkout -- \
  web/modules/custom/myeventlane_event/src/EventCard/EventMerchandisingPresenter.php \
  web/modules/custom/myeventlane_event/tests/src/Unit/EventMerchandisingPresenterTest.php
```
Or revert the whole slice in one commit: `git revert <phase-1a-commit-sha>`.

---

## Explicitly OUT of Phase 1A (per rules / unconfirmed)

| Item | Reason |
|---|---|
| "Hidden Gem" badge **type** | *Repository evidence not found* — no existing badge type; would be new component/architecture |
| Token / SCSS / palette changes | Excluded by scope (Phase 2) |
| Mascot / hero art changes | Excluded by scope |
| AI assistant → "Ask the Guide" | Excluded by scope (Phase 3) |
| Email / digest copy | Excluded by scope (Phase 5) |
| Empty-state vocabulary consolidation (`.mel-empty` vs `.mel-empty-state`) | Structural, not copy — out of scope |
| Block placement / visibility-condition changes | Reuse existing config unchanged |
| `/events/nearby`, `/events/online` validation | Routing question, not copy — track separately (`discovery-audit.md §1`) |

---

## Sign-off checklist before implementation
- [ ] Final hero + heading + empty-state copy approved (drafts above are illustrative).
- [ ] Decision on Group D (re-voice `'Featured'` badge now, or defer to Phase 1B).
- [ ] Confirm rail-order preference (proposed: Featured → Recommended → Tonight → Discover → …).
- [ ] Confirm no parallel work is editing `page--front.html.twig` (single-file coordination).
