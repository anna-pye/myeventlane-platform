# Brand Gap Analysis — Current MEL vs `docs/brand/*`

**Date:** 2026-06-14
**Branch:** `feature/event-studio-consolidation`
**Brand source of truth:** `docs/brand/` (v1.0 — README declares it authoritative)
**Scope (as requested):** Homepage · Event cards · Onboarding flow · Discovery surfaces
**Method:** Evidence-based. Current-state claims cite repository paths; brand requirements cite `docs/brand/*`. **No code changed.**

> Severity key: **🔴 Critical** (contradicts a core pillar/promise) · **🟠 High** (visible brand miss) · **🟡 Medium** (partial / inconsistent) · **🟢 Low** (minor/polish) · **✅ Aligned**.

---

## 0. Cross-cutting finding: token / colour system diverges from brand

This underpins all four scoped surfaces, so it is stated once up front.

| Brand token (`design-tokens.md`) | Brand value | Current implementation (`myeventlane_theme/src/scss/base/_tokens.scss`) | Status |
|---|---|---|---|
| **Primary Purple** (primary actions) | `#6B46FF` | `--mel-color-primary: #f26d5b` (**coral**) | 🔴 **Primary colour is wrong hue** — brand primary is purple; coral is only a *secondary accent* in brand |
| Coral (secondary accent) | `#FF6B4A` | primary slot = `#f26d5b` | 🟡 Coral exists but occupies the *primary* role |
| Lavender | `#CDBDFF` | `--mel-color-accent: #7c83fd` (periwinkle) | 🟡 Approximate, not the brand value |
| **Discovery Gold** (Hidden Gem) | `#FFC83D` | **absent** | 🔴 No Discovery Gold token → Hidden Gem cannot be styled per brand |
| Warm Cream (canvas) | `#FFF7EE` | `--mel-color-bg: #fff9f5` | 🟢 Close but not exact |
| Soft Sky | `#EAF4FF` | not a named token | 🟡 Missing |

**Notable:** the brand's Primary Purple `#6B46FF` **is already in the codebase — but only on vendor surfaces** (`_vendor-events.scss:111,295`, `_vendor-studio-layout.scss:32-33`), i.e. applied to the *operational* theme rather than the *public* brand primary. The public consumer surface still leads with coral.

> Per `design-tokens.md` governance, alignment = update brand doc first (done — it's the target), then a dedicated token implementation task. This is a **Phase 2 (brand layer)** item, not a copy fix.

---

## 1. Homepage — gap analysis

**Brand spec:** `homepage-system.md` + `mel-brand-system-v1.md` (Discovery Principles).
**Current implementation:** `web/themes/custom/myeventlane_theme/templates/page--front.html.twig`, hero block `myeventlane_front/templates/myeventlane-home-hero.html.twig`.

### 1.1 Hero
| Brand requirement | Current state (evidence) | Gap | Severity |
|---|---|---|---|
| Headline states discovery promise ("Discover what's on near you") | H1 = `'Your lane to great events.'` (`myeventlane-home-hero.html.twig:21`) | "Lane" metaphor, not discovery/local promise | 🟠 High |
| Supporting line reinforces *local* exploration | `'Find workshops, gigs, markets, festivals, and community moments worth leaving the house for.'` (:23) | Lists formats; no *local/nearby* framing | 🟡 Medium |
| **One locked hero, no rotating carousel with competing CTAs** (`homepage-system.md` Hero constraints; `DESIGN_SYSTEM.md` locks `.mel-event-hero--featured-style`) | Homepage uses a **different** hero (`.mel-home-hero`) with a **feature rotator** (`mel-home-hero__feature-rotator`, `data-mel-home-hero-rotator`, lib `home-hero-rotator`) | Homepage hero is not the locked `.mel-event-hero--featured-style`; contains a rotating element | 🟠 High (contract review) |
| Single primary CTA | Primary `'Explore events'` + secondary `'Create event'` (:60,:63) | Acceptable (1 primary), but "Create event" is a vendor action in a discovery hero | 🟢 Low |
| Location/date quick filters | Hero has search + "Suburb or city" input (:51) | ✅ Present | ✅ |

### 1.2 Section order & hierarchy
**Brand canonical mobile order:** Hero → **Tonight/This week near you** → **Hidden Gems** → Browse by category → Editor's Pick → Community Favourites → Just Added → Blog → Footer.
**Current order** (`page--front.html.twig` region sequence): Hero → Featured ("Community spotlight") → Discover → Tonight → Free/RSVP → Latest → Recommended → Nearby → Online → Blog → Host CTA → Newsletter.

| Brand-required section | Present today? | Gap | Severity |
|---|---|---|---|
| Tonight/This week **near you** as #2 | Tonight exists but is #4; not location-led | Re-prioritise + add location framing | 🟠 High |
| **Hidden Gems rail** | ❌ **Absent** (no gem View/section) | Core differentiator missing from homepage | 🔴 Critical |
| Browse by category (high priority) | Category pills exist (`myeventlane_category_pills`) but not prioritised in stack | Promote | 🟡 Medium |
| Editor's Pick / Curator rail | ❌ Absent (Featured ≈ editorial but not named/curated per brand) | Missing | 🟠 High |
| Community Favourites | ❌ Absent | Missing | 🟡 Medium |
| Just Added | "Freshly added"/Latest exists | ✅ Conceptually present (rename) | 🟢 Low |
| Max **two guide moments** | 0 guide moments present | No guides at all (see §5) | 🟠 High |
| Location-aware "Near you" (prompt area when unknown) | "Nearby" rail exists; `/events/nearby` link unverified (`discovery-audit.md §1`) | Location-first not enforced; possible broken link | 🟠 High |

> Current homepage is **format/recency-led**; brand wants **location-led + Hidden-Gem-led**. The structural shell (region-driven rails, shared `mel-section-shell`) is reusable — the gap is *which rails exist and their order/voice*.

---

## 2. Event cards — gap analysis

**Brand spec:** `event-card-system.md`, `copy-guidelines.md`.
**Current implementation:** `templates/components/event-card/mel-event-card.html.twig`; badges from `myeventlane_event/src/EventCard/EventMerchandisingPresenter.php`.

### 2.1 Structure & reuse
| Brand requirement | Current state | Gap | Severity |
|---|---|---|---|
| Title, date·time, location(+distance), price/RSVP, one badge | Card renders title, date badge, time, price label, status — single image badge (`_image_badge_label`, :94) | Distance ("2 km away") not shown; otherwise aligned | 🟡 Medium |
| **One canonical card, no parallel systems** | `mel-event-card.html.twig` is canonical; variants are modifiers (featured/compact/editorial) | ✅ Aligned (no parallel card) | ✅ |
| One badge per card | Card sets a single `_image_badge_label` | ✅ Aligned | ✅ |
| Mobile full-width, truncation, lazy-load | Card system + skeleton/lazy patterns present | ✅ Largely aligned | ✅ |

### 2.2 Badge system — **the biggest card gap**
**Brand approved badges:** Hidden Gem, Community Favourite, Trending Tonight, Editor's Pick, Just Added, Nearby (`event-card-system.md`).
**Current badges produced** (`EventMerchandisingPresenter.php`): `'Featured'` (:110), `'Selling fast'` (:100,:156), `'Sold out'`; plus category label fallback.

| Brand badge | Implemented? | Gap | Severity |
|---|---|---|---|
| **Hidden Gem** (Discovery Gold) | ❌ No badge, no Gold token | Flagship discovery badge absent | 🔴 Critical |
| Community Favourite | ❌ | Absent | 🟠 High |
| Trending Tonight | ❌ | Absent | 🟡 Medium |
| Editor's Pick | ◑ `'Featured'` is the nearest analogue | Not named per brand; criteria undocumented | 🟡 Medium |
| Just Added | ◑ "Freshly added" rail, not a card badge | Partial | 🟢 Low |
| Nearby | ❌ (no distance/location on card) | Absent | 🟠 High |
| **Not approved:** scarcity framing | `'Selling fast'` is shown | Borderline vs "no fake scarcity" — allowed only "when true"; needs criteria governance | 🟡 Medium |

> Badge text originates in a **unit-tested PHP presenter** (`EventMerchandisingPresenterTest`) — re-voicing/adding badges is a governed code change with criteria documentation (`drupal-design-governance.md` badge governance), not a copy tweak.

### 2.3 On-card copy
Brand prefers "Free / From $25 / Donation", human dates, suburb+distance. Current card supports price label + human date; **distance/suburb context missing**. No avoided vocabulary detected on cards (✅).

---

## 3. Onboarding flow — gap analysis

**Brand spec:** `guide-character-system.md` (Helper + Celebrating archetypes), `drupal-design-governance.md` (Event Studio onboarding = Helper Guide).
**Current implementation:** post-login hub (`mel-post-login-hub.html.twig`, `PostLoginHubBuilder`), vendor onboarding flow (`/vendor/onboard/*`), customer onboard steps (`myeventlane_core/templates/customer-onboard-step.html.twig`), empty states.

| Brand requirement | Current state (evidence) | Gap | Severity |
|---|---|---|---|
| **Helper Guide** "Let's get started" at account creation / first steps | Standard Drupal `user.register` (altered) + customer onboard steps; **no Helper Guide voice/persona** | No guide presence in onboarding | 🟠 High |
| Post-login welcome with recommendation (**Curator** tone) | Post-login hub has `headline`/`body`/**`recommendation` slot** (`mel-post-login-hub.html.twig`) | Structure ready, but **copy is generic, no Curator Guide voice** | 🟡 Medium |
| **Celebrating Guide** on success ("Great choice — see you there") | Vendor onboarding has `vendor-onboard-complete-celebration.html.twig`; RSVP/checkout success copy exists | Celebration moment exists but **not branded to Celebrating Guide** | 🟡 Medium |
| Location-first onboarding (choose your area) | Registration captures **no interest/vibe/location** preference (`onboarding-audit.md §3`) | No "choose your area / what are you into" step | 🟠 High |
| One guide voice per screen, AU English, short | N/A — no guides yet | Cannot assess; absent | 🟠 High |
| Vendor Event Studio = Helper Guide (professional/supportive) | Onboarding wizard/tooltips exist (`vendor-onboard-tooltip.js`); operational tone | No Helper Guide persona | 🟡 Medium |

> **Strong reuse positive:** the post-login hub's built-in `recommendation` slot is the ideal Curator-Guide entry point (`onboarding-audit.md §1`). The gap is **voice + a location/interest step**, not architecture.

---

## 4. Discovery surfaces — gap analysis

**Brand spec:** `mel-brand-system-v1.md` (Discovery Principles), `homepage-system.md`, `event-card-system.md` (search/browse parity), `copy-guidelines.md` (empty states).
**Current implementation:** `upcoming_events` View (`/events`, `/events/category/%`, `/events/free`, `/events/popular`, `/events/this-weekend`, `/events/today`), `/search` (Search API), `/calendar`, category pills; empty states in `includes/mel-view-empty-events.html.twig` + view templates.

| Brand discovery principle | Current state (evidence) | Gap | Severity |
|---|---|---|---|
| **Local first** (nearby before generic) | Browse is recency/category-led; `myeventlane_location` module exists; `/events/nearby` link unverified | Discovery is **browse-first, not location-first**; nearby surface unconfirmed | 🟠 High |
| **Surface the unexpected** (Hidden Gems, not only top sellers) | No Hidden Gem / discovery-score surface anywhere | Core promise ("experiences you never knew existed") not operational in discovery | 🔴 Critical |
| Scannable hierarchy (title/time/place + 1 action) | Cards follow this | ✅ Aligned | ✅ |
| Honest badges, no stacking | Single badge enforced; but approved discovery badges absent (§2.2) | Badge *vocabulary* gap | 🟠 High |
| **Search & browse parity** (same card) | `/search` and `/events` both use `mel-event-card` | ✅ Aligned | ✅ |
| Optimistic empty states | `'No events found'` (`views-view--upcoming-events--page-events.html.twig:42`); include default `'Nothing here yet'` / `'Check back soon…'` (`mel-view-empty-events.html.twig:10-13`) | Functional, **not** the brand's "widen your date range / explore nearby suburbs" Explorer optimism | 🟡 Medium |
| Discovery copy uses preferred vocabulary (Discover/Explore/Find/Nearby/Local) | Mixed; some nightlife-leaning ("Filter by what you feel like tonight", "better nights out") | Voice inconsistent with brand | 🟡 Medium |

---

## 5. Guide system — cross-surface gap (Critical)

The **Guide framework** (`guide-character-system.md`) defines 5 archetypes (Explorer, Host, Curator, Helper, Celebrating) expressed as tone + placement + illustration.

| Where brand wants a Guide | Current state | Severity |
|---|---|---|
| Homepage Hidden Gems (Explorer) | No Hidden Gems rail, no Explorer voice | 🔴 Critical |
| Homepage Editor's Pick (Curator) | No Editor's Pick | 🟠 High |
| Onboarding (Helper "Let's get started") | None | 🟠 High |
| Post-login recommendation (Curator) | Slot exists, no voice | 🟡 Medium |
| Success states (Celebrating) | Celebration markup exists, no voice | 🟡 Medium |
| Empty states (Explorer/Helper) | Neutral copy | 🟡 Medium |

**Finding:** the Guide tone-and-placement system is **entirely unimplemented** today (0 of 5 archetypes present as copy/persona). No avoided vocabulary (VIP/exclusive/secret) was found in current copy — so there is **nothing to remove**, only Guide voice to **add**. Illustration assets are Phase-2 per the roadmap (`docs/brand/assets/guide/` not yet populated).

---

## 6. Summary scorecard

| Surface | Structure/architecture | Visual tokens | Copy/voice | Guide system | Overall |
|---|---|---|---|---|---|
| **Homepage** | 🟢 Reusable shell | 🔴 Coral≠Purple, no Gold | 🟠 Off-brand hero/headings | 🔴 No guides, no Hidden Gems | 🟠 **High gap** |
| **Event cards** | ✅ Canonical, single-badge | 🔴 No Discovery Gold | 🟡 No distance/local copy | 🔴 No Hidden Gem / approved badges | 🟠 **High gap** |
| **Onboarding** | 🟢 Hub + recommendation slot | 🟡 | 🟡 Generic | 🟠 No Helper/Celebrating voice | 🟡 **Medium gap** |
| **Discovery** | ✅ Browse+search parity | 🟡 | 🟡 Functional empties | 🔴 No "surface the unexpected" | 🟠 **High gap** |

**Headline:** the **engineering scaffolding is strongly aligned** (one canonical card, region-driven rails, search/browse parity, a recommendation-ready post-login hub). The gaps are concentrated in **(a) the token/colour system (coral-led, no Discovery Gold), (b) the missing Hidden Gem discovery surface + badge, and (c) the entirely unimplemented Guide voice.** None of these are blocked by architecture.

---

## 7. Prioritised gap list (for planning — no code changed here)

| # | Gap | Surfaces | Severity | Brand ref | Likely phase |
|---|---|---|---|---|---|
| 1 | **No Hidden Gem surface or badge** (+ no Discovery Gold token) | Homepage, cards, discovery | 🔴 Critical | `mel-brand-system-v1` Hidden Gem Framework; `event-card-system` | 2–4 |
| 2 | **Primary colour is coral, not Purple `#6B46FF`** (purple only on vendor) | All public surfaces | 🔴 Critical | `design-tokens` | 2 (token layer) |
| 3 | **Guide voice unimplemented** (0/5 archetypes) | All four | 🔴 Critical | `guide-character-system` | 1A copy + 3 |
| 4 | Hero off-brand (headline "Your lane…"; rotator vs locked hero) | Homepage | 🟠 High | `homepage-system` Hero | 1A copy / 2 contract |
| 5 | Homepage order not location/gem-led; missing Editor's Pick & Community Favourites | Homepage, discovery | 🟠 High | `homepage-system` hierarchy | 1A order + 4 |
| 6 | No location-first discovery / unverified `/events/nearby`; no distance on cards | Discovery, cards, onboarding | 🟠 High | Discovery Principle 1 | 4 |
| 7 | Approved discovery badges absent (Community Favourite, Trending Tonight, Nearby) | Cards | 🟠 High | `event-card-system` | 4 |
| 8 | No interest/location capture in onboarding | Onboarding | 🟠 High | governance / discovery | 3 |
| 9 | Empty-state & section copy not optimistic/preferred-vocabulary | Discovery, homepage | 🟡 Medium | `copy-guidelines` | 1A copy |
| 10 | Success/celebration not branded to Celebrating Guide | Onboarding | 🟡 Medium | `guide-character-system` | 3 |
| 11 | `'Selling fast'` scarcity needs honest-criteria governance | Cards | 🟡 Medium | `event-card-system` badge rules | 4 |
| 12 | Lavender/Soft Sky/Warm Cream not exact brand values | All | 🟡 Medium | `design-tokens` | 2 |

### What is already aligned (no action)
- One canonical event card, single-badge discipline (`event-card-system` ✅).
- Search/browse card parity (`mel-event-card` on both ✅).
- Region-driven homepage shell reusable for brand rails (✅).
- Post-login hub recommendation slot ready for Curator Guide (✅).
- No avoided/exclusivity vocabulary present to remove (✅).
- `prefers-reduced-motion` respected; 44px touch targets (per `mobile-audit.md` ✅).

---

## 8. Notes & unverified items (no guessing)

- **Locked hero tension:** `homepage-system.md` references working "within the existing `.mel-event-hero--featured-style` contract", but the live homepage hero is `.mel-home-hero` (a separate hero with a rotator). Whether this is an accepted exception or a drift requires a **team decision against `DESIGN_SYSTEM.md`** — *repository shows two distinct hero systems; brand intent is one.*
- **Location-first:** `myeventlane_location` module exists; the degree of geo/suburb prioritisation in discovery rails was **not fully traced** — flagged for verification before Phase 4 (consistent with `discovery-audit.md §1`'s `/events/nearby` `/events/online` validation note).
- **Badge criteria:** brand requires documented eligibility before enabling badges (`drupal-design-governance.md`). No such criteria doc was found for Hidden Gem/Community Favourite — *Repository evidence not found.*
- Guide illustration assets (`docs/brand/assets/guide/`) are **Phase-2 per roadmap and not yet produced** — voice/copy gaps can close before art lands.

**This document is analysis only. No homepage, card, onboarding, or discovery code was modified.**
